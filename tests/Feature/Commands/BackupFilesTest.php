<?php

namespace Brindepaille\Backup\Tests\Feature\Commands;

use Brindepaille\Backup\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class BackupFilesTest extends TestCase
{
    public function test_copies_the_live_files_missing_from_the_backup(): void
    {
        $this->configureBackup();
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('contract.pdf', 'contract');
        $live->put('photos/portrait.jpg', 'portrait');

        $this->artisan('backup:files')
            ->expectsOutputToContain('2 fichier(s) déposé(s), 0 masqué(s).')
            ->doesntExpectOutputToContain('Déposé :')
            ->assertSuccessful();

        $backup->assertExists('files/client-a/contract.pdf', 'contract');
        $backup->assertExists('files/client-a/photos/portrait.jpg', 'portrait');
    }

    public function test_never_rewrites_a_file_that_is_already_saved(): void
    {
        $this->configureBackup();
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('contract.pdf', 'second draft');
        $backup->put('files/client-a/contract.pdf', 'first draft');

        $this->artisan('backup:files')
            ->expectsOutputToContain('0 fichier(s) déposé(s), 0 masqué(s).')
            ->assertSuccessful();

        $backup->assertExists('files/client-a/contract.pdf', 'first draft');
    }

    public function test_hides_the_saved_files_that_left_the_live_disk(): void
    {
        $this->configureBackup();
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('kept.pdf', 'kept');
        $backup->put('files/client-a/kept.pdf', 'kept');
        $backup->put('files/client-a/photos/removed.jpg', 'removed');

        $this->artisan('backup:files')
            ->expectsOutputToContain('0 fichier(s) déposé(s), 1 masqué(s).')
            ->assertSuccessful();

        $backup->assertMissing('files/client-a/photos/removed.jpg');
        $backup->assertExists('files/client-a/kept.pdf');
    }

    public function test_hides_nothing_when_the_live_disk_comes_back_empty(): void
    {
        $this->configureBackup();
        $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $backup->put('files/client-a/contract.pdf', 'contract');

        $this->artisan('backup:files')
            ->expectsOutputToContain('Le disque live semble vide')
            ->assertFailed();

        $backup->assertExists('files/client-a/contract.pdf');
    }

    public function test_only_touches_the_files_folder_of_the_site(): void
    {
        $this->configureBackup();
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('contract.pdf', 'contract');
        $backup->put('files/client-b/report.pdf', 'report of another site');
        $backup->put('db/client-a/2026-11-16-02-00-00.zip', 'dump');

        $this->artisan('backup:files')
            ->expectsOutputToContain('1 fichier(s) déposé(s), 0 masqué(s).')
            ->assertSuccessful();

        $this->assertSame([
            'db/client-a/2026-11-16-02-00-00.zip',
            'files/client-a/contract.pdf',
            'files/client-b/report.pdf',
        ], $backup->allFiles());
    }

    public function test_fails_without_copying_anything_while_required_variables_are_missing(): void
    {
        $this->configureBackup(['BACKUP_NAME' => null, 'BACKUP_S3_SECRET' => null]);
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('contract.pdf', 'contract');

        $this->artisan('backup:files')
            ->expectsOutputToContain('variable(s) manquante(s) : BACKUP_NAME, BACKUP_S3_SECRET.')
            ->assertFailed();

        $this->assertSame([], $backup->allFiles());
    }

    public function test_mirrors_the_disk_named_by_the_files_disk_variable_instead_of_the_default_disk(): void
    {
        $this->configureBackup(['BACKUP_FILES_DISK' => 'uploads']);
        $this->fakeDefaultDisk()->put('from-default-disk.pdf', 'default');
        Storage::fake('uploads')->put('from-uploads-disk.pdf', 'uploads');
        $backup = Storage::fake('backups');

        $this->artisan('backup:files')->assertSuccessful();

        $this->assertSame(['files/client-a/from-uploads-disk.pdf'], $backup->allFiles());
    }

    public function test_disk_option_takes_precedence_over_the_files_disk_variable(): void
    {
        $this->configureBackup(['BACKUP_FILES_DISK' => 'uploads']);
        Storage::fake('uploads')->put('from-uploads-disk.pdf', 'uploads');
        Storage::fake('archive')->put('from-archive-disk.pdf', 'archive');
        $backup = Storage::fake('backups');

        $this->artisan('backup:files', ['--disk' => 'archive'])->assertSuccessful();

        $this->assertSame(['files/client-a/from-archive-disk.pdf'], $backup->allFiles());
    }

    public function test_refuses_to_mirror_the_backup_disk_into_itself(): void
    {
        $this->configureBackup();
        $backup = Storage::fake('backups');
        $backup->put('db/client-a/2026-11-16-02-00-00.zip', 'dump');

        $this->artisan('backup:files', ['--disk' => 'backups'])
            ->expectsOutputToContain('Le disque source ne peut pas être le disque de sauvegarde.')
            ->assertFailed();

        $this->assertSame(['db/client-a/2026-11-16-02-00-00.zip'], $backup->allFiles());
    }

    public function test_verbose_run_names_each_file_it_copies_and_hides(): void
    {
        $this->configureBackup();
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('contract.pdf', 'contract');
        $backup->put('files/client-a/removed.jpg', 'removed');

        $this->artisan('backup:files', ['--verbose' => true])
            ->expectsOutputToContain('Déposé : contract.pdf')
            ->expectsOutputToContain('Masqué : removed.jpg')
            ->assertSuccessful();
    }

    public function test_skips_a_file_that_vanished_since_the_listing(): void
    {
        $this->configureBackup();
        $live = $this->fakeDefaultDisk();
        $backup = Storage::fake('backups');
        $live->put('kept.pdf', 'kept');
        $listing = Mockery::mock(FilesystemAdapter::class, [$live->getDriver(), $live->getAdapter(), $live->getConfig()])->makePartial();
        $listing->shouldReceive('allFiles')->andReturn(['kept.pdf', 'vanished.tmp']);
        Storage::set('live', $listing);

        $this->artisan('backup:files')
            ->expectsOutputToContain('1 fichier(s) déposé(s), 0 masqué(s).')
            ->assertSuccessful();

        $this->assertSame(['files/client-a/kept.pdf'], $backup->allFiles());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function liveDiskErrorModes(): array
    {
        return [
            'live disk returning null on a failed read' => [false],
            'live disk throwing on a failed read' => [true],
        ];
    }

    #[DataProvider('liveDiskErrorModes')]
    public function test_reports_a_live_file_it_cannot_read_and_still_saves_the_others(bool $throws): void
    {
        $this->skipWhenFilePermissionsAreNotEnforced();
        $this->configureBackup();
        $live = $this->fakeDefaultDisk(['throw' => $throws]);
        $backup = Storage::fake('backups');
        $live->put('locked.pdf', 'locked');
        $live->put('readable.pdf', 'readable');
        chmod($live->path('locked.pdf'), 0000);

        $this->artisan('backup:files')
            ->expectsOutputToContain('1 fichier(s) déposé(s), 0 masqué(s).')
            ->expectsOutputToContain('1 fichier(s) illisible(s) sur le disque live, non sauvegardé(s) : locked.pdf')
            ->assertFailed();

        $this->assertSame(['files/client-a/readable.pdf'], $backup->allFiles());
    }

    public function test_lets_the_error_of_the_backup_disk_stop_the_run_when_a_write_is_rejected(): void
    {
        $this->skipWhenFilePermissionsAreNotEnforced();
        $this->configureBackup();
        $this->fakeDefaultDisk()->put('contract.pdf', 'contract');
        $this->lockFolder(Storage::fake('backups'), 'files/client-a');

        $this->expectException(UnableToWriteFile::class);

        $this->artisan('backup:files')->run();
    }

    public function test_fails_when_a_backup_disk_that_does_not_throw_rejects_a_write(): void
    {
        $this->skipWhenFilePermissionsAreNotEnforced();
        $this->configureBackup();
        $this->fakeDefaultDisk()->put('contract.pdf', 'contract');
        $this->lockFolder(Storage::fake('backups', ['throw' => false]), 'files/client-a');

        $this->artisan('backup:files')
            ->expectsOutputToContain('Écriture refusée par la sauvegarde : contract.pdf')
            ->assertFailed();
    }

    public function test_fails_when_a_backup_disk_that_does_not_throw_refuses_to_hide_a_file(): void
    {
        $this->skipWhenFilePermissionsAreNotEnforced();
        $this->configureBackup();
        $this->fakeDefaultDisk()->put('kept.pdf', 'kept');
        $backup = Storage::fake('backups', ['throw' => false]);
        $backup->put('files/client-a/kept.pdf', 'kept');
        $backup->put('files/client-a/removed.jpg', 'removed');
        $this->lockFolder($backup, 'files/client-a');

        $this->artisan('backup:files')
            ->expectsOutputToContain('Masquage refusé par la sauvegarde : removed.jpg')
            ->assertFailed();

        $backup->assertExists('files/client-a/removed.jpg');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fakeDefaultDisk(array $config = []): FilesystemAdapter
    {
        Config::set('filesystems.default', 'live');

        return Storage::fake('live', $config);
    }

    /**
     * Makes a folder of a fake disk refuse writes and deletions until the test ends.
     */
    private function lockFolder(FilesystemAdapter $disk, string $folder): void
    {
        $disk->makeDirectory($folder);
        chmod($disk->path($folder), 0555);

        $this->beforeApplicationDestroyed(fn () => chmod($disk->path($folder), 0755));
    }

    private function skipWhenFilePermissionsAreNotEnforced(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('File permissions do not apply to the root user.');
        }
    }
}
