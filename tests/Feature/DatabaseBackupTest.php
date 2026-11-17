<?php

namespace Brindepaille\Backup\Tests\Feature;

use Brindepaille\Backup\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use ZipArchive;

class DatabaseBackupTest extends TestCase
{
    public function test_dump_lands_encrypted_in_the_database_folder_of_the_site_without_any_notification(): void
    {
        if ((new ExecutableFinder)->find('sqlite3') === null) {
            $this->markTestSkipped('The sqlite3 binary is required to dump the test database.');
        }

        $this->configureBackup(['BACKUP_ARCHIVE_PASSWORD' => 'a-long-random-phrase']);
        $this->useSqliteDatabaseFile();
        $backup = Storage::fake('backups');
        Notification::fake();
        $this->travelTo('2026-11-17 02:00:00');

        $this->artisan('backup:run', ['--only-db' => true])->assertSuccessful();

        $this->assertSame(['db/client-a/2026-11-17-02-00-00.zip'], $backup->allFiles());
        $this->assertTrue($this->isEncrypted($backup->path('db/client-a/2026-11-17-02-00-00.zip')));
        Notification::assertNothingSent();
    }

    private function useSqliteDatabaseFile(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'backup-test-');
        $this->beforeApplicationDestroyed(fn () => File::delete($database));

        Config::set('database.connections.site', ['driver' => 'sqlite', 'database' => $database]);
        Config::set('backup.backup.source.databases', ['site']);
    }

    private function isEncrypted(string $zipPath): bool
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $encryptionMethod = $zip->statIndex(0)['encryption_method'];
        $zip->close();

        return $encryptionMethod !== ZipArchive::EM_NONE;
    }
}
