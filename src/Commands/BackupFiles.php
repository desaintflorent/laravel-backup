<?php

namespace Brindepaille\Backup\Commands;

use Brindepaille\Backup\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;

#[Signature('backup:files {--disk= : Disque source (par défaut : BACKUP_FILES_DISK, sinon le disque par défaut de l\'appli)}')]
#[Description('Aligne la sauvegarde sur le disque live : dépose ce qui manque, masque ce qui a disparu')]
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

        $sourceName = $this->sourceDiskName($settings);

        if ($sourceName === Settings::DISK) {
            $this->error('Le disque source ne peut pas être le disque de sauvegarde.');

            return self::FAILURE;
        }

        $source = Storage::disk($sourceName);
        $backup = Storage::disk(Settings::DISK);
        $folder = $settings->filesFolder();

        // Lister les noms est autorisé des deux côtés ; lire la sauvegarde ne l'est pas.
        $live = $source->allFiles();
        $saved = array_map(
            fn (string $path): string => Str::after($path, "{$folder}/"),
            $backup->allFiles($folder),
        );

        if ($live === [] && $saved !== []) {
            $this->error('Le disque live semble vide : rien n\'est masqué, par prudence.');

            return self::FAILURE;
        }

        $copied = 0;
        $unreadable = [];

        foreach (array_diff($live, $saved) as $path) {
            $stream = $this->openForReading($source, $path);

            if ($stream === null) {
                // Disparu depuis le listage : plus rien à sauvegarder. Encore là : à signaler.
                if ($source->exists($path)) {
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

                return self::FAILURE;
            }

            $copied++;

            $this->line("Déposé : {$path}", verbosity: 'v');
        }

        $hidden = 0;

        // Sans numéro de version, cette suppression ne pose qu'un marqueur : le fichier
        // reste dans les anciennes versions jusqu'à l'échéance fixée par le bucket.
        foreach (array_diff($saved, $live) as $path) {
            if (! $backup->delete("{$folder}/{$path}")) {
                $this->error("Masquage refusé par la sauvegarde : {$path}");

                return self::FAILURE;
            }

            $hidden++;

            $this->line("Masqué : {$path}", verbosity: 'v');
        }

        $this->info("{$copied} fichier(s) déposé(s), {$hidden} masqué(s).");

        if ($unreadable !== []) {
            $this->error(count($unreadable).' fichier(s) illisible(s) sur le disque live, non sauvegardé(s) : '.$this->listed($unreadable));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function sourceDiskName(Settings $settings): string
    {
        $option = $this->option('disk');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return $settings->filesDisk() ?? Config::string('filesystems.default');
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
