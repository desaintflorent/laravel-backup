<?php

namespace Brindepaille\Backup;

use Brindepaille\Backup\Commands\BackupFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;
use Spatie\Backup\BackupServiceProvider as SpatieBackupServiceProvider;

class BackupServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    private const array MYSQL_DRIVERS = ['mysql', 'mariadb'];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brindepaille_backup.php', 'brindepaille_backup');

        // Les valeurs par défaut de Spatie doivent être en place avant d'être
        // ajustées, quel que soit l'ordre dans lequel les packages sont découverts.
        $this->app->register(SpatieBackupServiceProvider::class);

        $settings = $this->app->make(Settings::class);

        $this->registerBackupDisk($settings);
        $this->configureSpatieBackup($settings);
        $this->configureDatabaseDumps();
    }

    public function boot(): void
    {
        $this->commands([BackupFiles::class]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/brindepaille_backup.php' => $this->app->configPath('brindepaille_backup.php'),
            ], 'brindepaille-backup-config');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->scheduleBackups($schedule, $this->app->make(Settings::class));
        });
    }

    /**
     * Sans ses quatre variables, le disque n'existe pas : un client S3 sans
     * identifiants reprendrait ceux que l'hébergeur injecte dans AWS_*.
     */
    private function registerBackupDisk(Settings $settings): void
    {
        $disk = 'filesystems.disks.'.Settings::DISK;

        if ($settings->isConfigured() && ! Config::has($disk)) {
            Config::set($disk, $settings->disk());
        }
    }

    private function configureSpatieBackup(Settings $settings): void
    {
        if ($settings->hasName()) {
            Config::set('backup.backup.name', $settings->databaseFolder());
        }

        Config::set('backup.backup.destination.disks', [Settings::DISK]);

        // Un mot de passe vide ne doit pas effacer celui que l'application aurait défini.
        if ($settings->archivePassword() !== null) {
            Config::set('backup.backup.password', $settings->archivePassword());
        }

        // Chaque notification lit la taille des sauvegardes, ce que la clé du site
        // n'a pas le droit de faire : le dump serait déclaré en échec alors que le
        // zip est bien déposé. Les alertes passent par la sortie des tâches planifiées.
        Config::set('backup.notifications.notifications', array_fill_keys(
            array_keys((array) Config::get('backup.notifications.notifications', [])),
            [],
        ));
    }

    /**
     * Ces deux options évitent des droits MySQL qu'un utilisateur restreint à
     * son site n'a pas forcément : verrouiller les tables, lire les tablespaces.
     */
    private function configureDatabaseDumps(): void
    {
        foreach ((array) Config::get('database.connections', []) as $name => $connection) {
            if (! is_array($connection) || ! in_array($connection['driver'] ?? null, self::MYSQL_DRIVERS, true)) {
                continue;
            }

            $dump = (array) ($connection['dump'] ?? []);

            if (! in_array('use_single_transaction', $dump, true)) {
                $dump[] = 'use_single_transaction';
            }

            $extraOption = is_string($dump['add_extra_option'] ?? null) ? $dump['add_extra_option'] : '';

            if (! str_contains($extraOption, '--no-tablespaces')) {
                $dump['add_extra_option'] = trim($extraOption.' --no-tablespaces');
            }

            Config::set("database.connections.{$name}.dump", $dump);
        }
    }

    private function scheduleBackups(Schedule $schedule, Settings $settings): void
    {
        if (! $settings->isEnabled()) {
            return;
        }

        $database = $schedule->command('backup:run --only-db');

        match ($settings->frequency()) {
            'weekly' => $database->weeklyOn(0, $settings->databaseTime()),
            default => $database->dailyAt($settings->databaseTime()),
        };

        $files = $schedule->command('backup:files')->dailyAt($settings->filesTime());

        foreach ([$database, $files] as $task) {
            if ($settings->alertEmail() !== null) {
                $task->emailOutputOnFailure($settings->alertEmail());
            }

            if ($settings->runsOnOneServer()) {
                $task->onOneServer();
            }
        }
    }
}
