<?php

namespace Brindepaille\Backup;

use Illuminate\Contracts\Config\Repository;

final readonly class Settings
{
    public const string DISK = 'backups';

    private const string CONFIG = 'brindepaille_backup';

    /** @var array<string, string> */
    private const array REQUIRED_VARIABLES = [
        'BACKUP_NAME' => 'name',
        'BACKUP_S3_BUCKET' => 'disk.bucket',
        'BACKUP_S3_KEY' => 'disk.key',
        'BACKUP_S3_SECRET' => 'disk.secret',
    ];

    public function __construct(private Repository $config) {}

    /**
     * @return list<string>
     */
    public function missingVariables(): array
    {
        return array_keys(array_filter(
            self::REQUIRED_VARIABLES,
            fn (string $key): bool => $this->string($key) === null,
        ));
    }

    public function isConfigured(): bool
    {
        return $this->missingVariables() === [];
    }

    /**
     * Une seule variable requise suffit : une configuration à moitié faite
     * doit échouer chaque nuit avec une alerte, pas rester inactive en silence.
     */
    public function isEnabled(): bool
    {
        return array_any(
            self::REQUIRED_VARIABLES,
            fn (string $key): bool => $this->string($key) !== null,
        );
    }

    public function databaseFolder(): string
    {
        return 'db/'.$this->string('name');
    }

    public function filesFolder(): string
    {
        return 'files/'.$this->string('name');
    }

    public function hasName(): bool
    {
        return $this->string('name') !== null;
    }

    public function frequency(): string
    {
        return $this->string('frequency') ?? 'daily';
    }

    public function alertEmail(): ?string
    {
        return $this->string('alert');
    }

    public function archivePassword(): ?string
    {
        return $this->string('archive_password');
    }

    public function filesDisk(): ?string
    {
        return $this->string('files_disk');
    }

    public function runsOnOneServer(): bool
    {
        return (bool) $this->config->get(self::CONFIG.'.on_one_server', false);
    }

    public function databaseTime(): string
    {
        return $this->string('schedule.database') ?? '02:00';
    }

    public function filesTime(): string
    {
        return $this->string('schedule.files') ?? '02:30';
    }

    /**
     * @return array<array-key, mixed>
     */
    public function disk(): array
    {
        return (array) $this->config->get(self::CONFIG.'.disk', []);
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get(self::CONFIG.'.'.$key);

        return is_scalar($value) && filled((string) $value) ? (string) $value : null;
    }
}
