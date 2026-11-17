<?php

namespace Brindepaille\Backup\Tests\Feature;

use Brindepaille\Backup\BackupServiceProvider;
use Brindepaille\Backup\Tests\Fixtures\PublishedSpatieConfig;
use Brindepaille\Backup\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Attributes\WithConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Backup\Config\BackupConfig;
use Spatie\Backup\Config\Config as SpatieConfig;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;

class BackupServiceProviderTest extends TestCase
{
    private bool $applicationPublishedSpatieConfig = false;

    protected function getPackageProviders($app): array
    {
        return $this->applicationPublishedSpatieConfig
            ? [PublishedSpatieConfig::class, BackupServiceProvider::class]
            : [BackupServiceProvider::class];
    }

    public function test_registers_the_backups_disk_from_the_four_required_variables(): void
    {
        $this->configureBackup();

        $this->assertSame([
            'driver' => 's3',
            'key' => 'key-of-client-a',
            'secret' => 'secret-of-client-a',
            'region' => 'eu-west-3',
            'bucket' => 'studio-backups',
            'endpoint' => 'https://s3.eu-west-3.amazonaws.com',
            'use_path_style_endpoint' => false,
            'throw' => true,
            'options' => ['StorageClass' => 'GLACIER_IR'],
        ], Config::get('filesystems.disks.backups'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredVariables(): array
    {
        return [
            'BACKUP_NAME' => ['BACKUP_NAME'],
            'BACKUP_S3_BUCKET' => ['BACKUP_S3_BUCKET'],
            'BACKUP_S3_KEY' => ['BACKUP_S3_KEY'],
            'BACKUP_S3_SECRET' => ['BACKUP_S3_SECRET'],
        ];
    }

    #[DataProvider('requiredVariables')]
    public function test_does_not_register_the_backups_disk_while_a_required_variable_is_missing(string $variable): void
    {
        $this->configureBackup([$variable => null]);

        $this->assertFalse(Config::has('filesystems.disks.backups'));
    }

    public function test_backups_disk_reaches_the_backup_bucket_despite_the_aws_variables_of_the_host(): void
    {
        $this->configureBackup([
            'AWS_ACCESS_KEY_ID' => 'key-of-the-live-bucket',
            'AWS_SECRET_ACCESS_KEY' => 'secret-of-the-live-bucket',
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_BUCKET' => 'live-bucket',
            'AWS_ENDPOINT' => 'https://live.r2.example.com',
            'AWS_ENDPOINT_URL' => 'https://live.r2.example.com',
        ]);

        $disk = Storage::disk('backups');
        $client = $disk->getClient();

        $this->assertSame('https://s3.eu-west-3.amazonaws.com', (string) $client->getEndpoint());
        $this->assertSame('eu-west-3', $client->getRegion());
        $this->assertSame('key-of-client-a', $client->getCredentials()->wait()->getAccessKeyId());
        $this->assertSame('studio-backups', $disk->getConfig()['bucket']);
    }

    public function test_region_endpoint_and_storage_class_follow_their_optional_variables(): void
    {
        $this->configureBackup([
            'BACKUP_S3_REGION' => 'eu-central-1',
            'BACKUP_S3_STORAGE_CLASS' => 'STANDARD',
        ]);

        $this->assertSame('eu-central-1', Config::get('filesystems.disks.backups.region'));
        $this->assertSame('https://s3.eu-central-1.amazonaws.com', Config::get('filesystems.disks.backups.endpoint'));
        $this->assertSame(['StorageClass' => 'STANDARD'], Config::get('filesystems.disks.backups.options'));
    }

    #[WithConfig('filesystems.disks.backups', ['driver' => 'local', 'root' => '/tmp/defined-by-the-application'])]
    public function test_keeps_a_backups_disk_defined_by_the_application(): void
    {
        $this->configureBackup();

        $this->assertSame(
            ['driver' => 'local', 'root' => '/tmp/defined-by-the-application'],
            Config::get('filesystems.disks.backups'),
        );
    }

    public function test_spatie_stores_dumps_in_the_database_folder_of_the_site_on_the_backups_disk(): void
    {
        $this->configureBackup();

        $backup = $this->spatieBackupConfig();

        $this->assertSame('db/client-a', $backup->name);
        $this->assertSame(['backups'], $backup->destination->disks);
    }

    public function test_databases_variable_lists_the_connections_spatie_dumps(): void
    {
        $this->configureBackup(['BACKUP_DATABASES' => 'mysql, reporting,mysql']);

        $this->assertSame(['mysql', 'reporting'], $this->spatieBackupConfig()->source->databases);
    }

    public function test_leaves_the_backup_name_of_spatie_alone_while_the_site_has_no_name(): void
    {
        $this->configureBackup(['BACKUP_NAME' => null]);

        $this->assertStringStartsNotWith('db/', $this->spatieBackupConfig()->name);
    }

    public function test_no_spatie_notification_keeps_a_channel(): void
    {
        $this->configureBackup();

        $channels = $this->app->make(SpatieConfig::class)->notifications->notifications;

        $this->assertNotEmpty($channels);
        $this->assertSame([], array_filter($channels));
    }

    public function test_archive_password_variable_becomes_the_password_of_the_zips(): void
    {
        $this->configureBackup(['BACKUP_ARCHIVE_PASSWORD' => 'a-long-random-phrase']);

        $this->assertSame('a-long-random-phrase', $this->spatieBackupConfig()->password);
    }

    public function test_only_overrides_the_settings_it_owns_in_a_spatie_config_published_by_the_application(): void
    {
        $this->applicationPublishedSpatieConfig = true;
        $this->configureBackup();

        $backup = $this->spatieBackupConfig();

        $this->assertSame(['mysql', 'reporting'], $backup->source->databases);
        $this->assertSame('App\\Dumps\\GzipCompressor', $backup->databaseDumpCompressor);
        $this->assertSame('db/client-a', $backup->name);
        $this->assertSame(['backups'], $backup->destination->disks);
    }

    public function test_keeps_the_archive_password_of_the_application_while_the_variable_is_empty(): void
    {
        $this->applicationPublishedSpatieConfig = true;
        $this->configureBackup();

        $this->assertSame('password-set-by-the-application', $this->spatieBackupConfig()->password);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mysqlConnections(): array
    {
        return [
            'mysql' => ['mysql'],
            'mariadb' => ['mariadb'],
        ];
    }

    #[DataProvider('mysqlConnections')]
    public function test_dumps_run_without_locking_tables_or_reading_tablespaces(string $connection): void
    {
        $command = DbDumperFactory::createFromConnection($connection)->getDumpCommand('dump.sql', 'credentials.cnf');

        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString('--no-tablespaces', $command);
    }

    #[WithConfig('database.connections.mysql.database', 'shop')]
    #[WithConfig('database.connections.mysql.dump', ['exclude_tables' => ['sessions'], 'add_extra_option' => '--skip-lock-tables'])]
    public function test_keeps_the_dump_options_of_the_application(): void
    {
        $command = DbDumperFactory::createFromConnection('mysql')->getDumpCommand('dump.sql', 'credentials.cnf');

        $this->assertStringContainsString('--ignore-table=shop.sessions', $command);
        $this->assertStringContainsString('--skip-lock-tables', $command);
        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString('--no-tablespaces', $command);
    }

    public function test_leaves_connections_other_than_mysql_without_dump_options(): void
    {
        $this->assertFalse(Config::has('database.connections.pgsql.dump'));
        $this->assertFalse(Config::has('database.connections.sqlite.dump'));
    }

    public function test_registering_again_over_a_cached_configuration_changes_nothing(): void
    {
        $this->configureBackup(['BACKUP_ARCHIVE_PASSWORD' => 'a-long-random-phrase']);
        $cached = Config::all();

        (new BackupServiceProvider($this->app))->register();

        $this->assertSame($cached, Config::all());
    }

    private function spatieBackupConfig(): BackupConfig
    {
        return $this->app->make(SpatieConfig::class)->backup;
    }
}
