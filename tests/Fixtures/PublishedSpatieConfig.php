<?php

namespace Brindepaille\Backup\Tests\Fixtures;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Stands in for a config/backup.php the application published and edited:
 * its values are in place before any package provider registers.
 */
class PublishedSpatieConfig extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $config = require __DIR__.'/../../vendor/spatie/laravel-backup/config/backup.php';

        $config['backup']['source']['databases'] = ['mysql', 'reporting'];
        $config['backup']['database_dump_compressor'] = 'App\\Dumps\\GzipCompressor';
        $config['backup']['password'] = 'password-set-by-the-application';

        Config::set('backup', $config);
    }
}
