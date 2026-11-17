<?php

namespace Brindepaille\Backup\Tests;

use Brindepaille\Backup\BackupServiceProvider;
use Orchestra\Testbench\Foundation\Env;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var array<string, string> */
    protected const array REQUIRED_VARIABLES = [
        'BACKUP_NAME' => 'client-a',
        'BACKUP_S3_BUCKET' => 'studio-backups',
        'BACKUP_S3_KEY' => 'key-of-client-a',
        'BACKUP_S3_SECRET' => 'secret-of-client-a',
    ];

    /** @var list<string> */
    private array $definedVariables = [];

    protected function tearDown(): void
    {
        foreach ($this->definedVariables as $name) {
            Env::forget($name);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [BackupServiceProvider::class];
    }

    /**
     * Reboots the application as a site whose environment holds the four
     * required variables. A null value leaves that variable undefined.
     *
     * @param  array<string, string|null>  $variables
     */
    protected function configureBackup(array $variables = []): void
    {
        $this->defineEnvironmentVariables([...self::REQUIRED_VARIABLES, ...$variables]);
    }

    /**
     * @param  array<string, string|null>  $variables
     */
    protected function defineEnvironmentVariables(array $variables): void
    {
        foreach (array_filter($variables, is_string(...)) as $name => $value) {
            Env::set($name, $value);

            $this->definedVariables[] = $name;
        }

        $this->refreshApplication();
    }
}
