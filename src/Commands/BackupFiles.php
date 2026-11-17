<?php

namespace Brindepaille\Backup\Commands;

use Brindepaille\Backup\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\UnableToReadFile;

#[Signature('backup:files {--disk= : Ne traiter que ce disque, parmi ceux de BACKUP_FILES_DISK}')]
#[Description('Aligne la sauvegarde sur les disques live : dépose ce qui manque, masque ce qui a disparu')]
class BackupFiles extends Command
{
    private const int MAX_LISTED_PATHS = 20;

    public function handle(Settings $settings): int
    {
        $missingVariables = $settings->missingVariables();

        if ($missingVariables !== []) {
            $this->error('Sauvegarde non configurée, variable(s) manquante(s) : '.implode(', ', $missingVariables).'.');

            return self::FAILURE;
        }

        $configured = $settings->filesDisks();
        $disks = $this->disksToMirror($configured);

        if ($disks === null) {
            return self::FAILURE;
        }

        $folder = $settings->filesFolder();

        // Lister les noms est autorisé des deux côtés ; lire la sauvegarde ne l'est pas.
        $saved = array_map(
            fn (string $path): string => Str::after($path, "{$folder}/"),
            Storage::disk(Settings::DISK)->allFiles($folder),
        );

        $complete = true;
        $liveCount = 0;

        // Chaque disque a son sous-dossier : son miroir ne masque que ses propres fichiers.
        foreach ($disks as $disk) {
            $live = array_map(
                fn (string $path): string => "{$disk}/{$path}",
                Storage::disk($disk)->allFiles(),
            );
            $liveCount += count($live);

            $savedOnDisk = array_filter($saved, fn (string $path): bool => str_starts_with($path, "{$disk}/"));

            $complete = $this->mirror($disk, $live, $savedOnDisk, $folder) && $complete;
        }

        if (! $complete) {
            return self::FAILURE;
        }

        // Un passage limité à un disque ne dit rien du reste de la sauvegarde.
        if ($this->option('disk') !== null) {
            return self::SUCCESS;
        }

        // Ce qui reste hors des dossiers de disques : la sauvegarde d'avant les
        // sous-dossiers, que ce passage vient de recopier, ou celle d'un disque retiré.
        $strays = array_filter($saved, fn (string $path): bool => array_all(
            $configured,
            fn (string $disk): bool => ! str_starts_with($path, "{$disk}/"),
        ));

        if ($strays === []) {
            return self::SUCCESS;
        }

        if ($liveCount === 0) {
            $this->error('Les disques live semblent vides : rien n\'est masqué hors de leurs dossiers, par prudence.');

            return self::FAILURE;
        }

        if (! $this->hide($folder, $strays)) {
            return self::FAILURE;
        }

        $this->info(count($strays).' fichier(s) masqué(s) hors des dossiers de disques (ancienne arborescence ou disque retiré).');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $configured
     * @return list<string>|null
     */
    private function disksToMirror(array $configured): ?array
    {
        if (in_array(Settings::DISK, $configured, true)) {
            $this->error('Le disque source ne peut pas être le disque de sauvegarde.');

            return null;
        }

        // Un nom de disque erroné doit arrêter le passage avant le moindre masquage.
        foreach ($configured as $disk) {
            try {
                Storage::disk($disk);
            } catch (InvalidArgumentException $exception) {
                $this->error("Disque « {$disk} » inutilisable : {$exception->getMessage()}");

                return null;
            }
        }

        $only = $this->option('disk');

        if (! is_string($only)) {
            return $configured;
        }

        if (! in_array($only, $configured, true)) {
            $this->error("Le disque « {$only} » n'est pas sauvegardé. Disque(s) de BACKUP_FILES_DISK : ".implode(', ', $configured).'.');

            return null;
        }

        return [$only];
    }

    /**
     * Les chemins sont relatifs au dossier de la sauvegarde : ils commencent par le nom du disque.
     *
     * @param  array<string>  $live
     * @param  array<string>  $saved
     */
    private function mirror(string $disk, array $live, array $saved, string $folder): bool
    {
        if ($live === [] && $saved !== []) {
            $this->error("Le disque live « {$disk} » semble vide : rien n'est masqué, par prudence.");

            return false;
        }

        $source = Storage::disk($disk);
        $backup = Storage::disk(Settings::DISK);
        $copied = 0;
        $unreadable = [];

        foreach (array_diff($live, $saved) as $path) {
            $livePath = Str::after($path, "{$disk}/");
            $stream = $this->openForReading($source, $livePath);

            if ($stream === null) {
                // Disparu depuis le listage : plus rien à sauvegarder. Encore là : à signaler.
                if ($source->exists($livePath)) {
                    $unreadable[] = $path;
                }

                continue;
            }

            try {
                $written = $backup->writeStream("{$folder}/{$path}", $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $written) {
                $this->error("Écriture refusée par la sauvegarde : {$path}");

                return false;
            }

            $copied++;

            $this->line("Déposé : {$path}", verbosity: 'v');
        }

        $gone = array_diff($saved, $live);

        if (! $this->hide($folder, $gone)) {
            return false;
        }

        $this->info("{$disk} : {$copied} fichier(s) déposé(s), ".count($gone).' masqué(s).');

        if ($unreadable !== []) {
            $this->error(count($unreadable)." fichier(s) illisible(s) sur le disque live « {$disk} », non sauvegardé(s) : ".$this->listed($unreadable));

            return false;
        }

        return true;
    }

    /**
     * Sans numéro de version, cette suppression ne pose qu'un marqueur : le fichier
     * reste dans les anciennes versions jusqu'à l'échéance fixée par le bucket.
     *
     * @param  array<string>  $paths
     */
    private function hide(string $folder, array $paths): bool
    {
        foreach ($paths as $path) {
            if (! Storage::disk(Settings::DISK)->delete("{$folder}/{$path}")) {
                $this->error("Masquage refusé par la sauvegarde : {$path}");

                return false;
            }

            $this->line("Masqué : {$path}", verbosity: 'v');
        }

        return true;
    }

    /**
     * @return resource|null
     */
    private function openForReading(Filesystem $source, string $path): mixed
    {
        try {
            $stream = $source->readStream($path);
        } catch (UnableToReadFile) {
            return null;
        }

        return is_resource($stream) ? $stream : null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function listed(array $paths): string
    {
        $shown = implode(', ', array_slice($paths, 0, self::MAX_LISTED_PATHS));

        return count($paths) > self::MAX_LISTED_PATHS ? "{$shown}…" : $shown;
    }
}
