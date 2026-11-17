<?php

namespace Brindepaille\Backup\Tests\Feature;

use Brindepaille\Backup\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

class BackupScheduleTest extends TestCase
{
    public function test_schedules_nothing_while_no_required_variable_is_defined(): void
    {
        $this->defineEnvironmentVariables([
            'BACKUP_FREQUENCY' => 'weekly',
            'BACKUP_ALERT_EMAIL' => 'alerts@example.com',
        ]);

        $this->assertSame([], $this->scheduledTasks());
    }

    public function test_dumps_the_database_then_mirrors_the_files_every_night(): void
    {
        $this->configureBackup();

        $this->assertSame([
            'backup:run --only-db' => '0 2 * * *',
            'backup:files' => '30 2 * * *',
        ], $this->scheduledTasks());
    }

    public function test_weekly_frequency_dumps_the_database_on_sundays_and_keeps_the_files_nightly(): void
    {
        $this->configureBackup(['BACKUP_FREQUENCY' => 'weekly']);

        $this->assertSame([
            'backup:run --only-db' => '0 2 * * 0',
            'backup:files' => '30 2 * * *',
        ], $this->scheduledTasks());
    }

    public function test_unknown_frequency_falls_back_to_a_nightly_dump(): void
    {
        $this->configureBackup(['BACKUP_FREQUENCY' => 'monthly']);

        $this->assertSame('0 2 * * *', $this->scheduledTasks()['backup:run --only-db']);
    }

    public function test_still_schedules_both_tasks_when_only_part_of_the_required_variables_is_defined(): void
    {
        $this->defineEnvironmentVariables(['BACKUP_S3_BUCKET' => 'studio-backups']);

        $this->assertSame(['backup:run --only-db', 'backup:files'], array_keys($this->scheduledTasks()));
    }

    public function test_emails_the_output_of_a_failed_task_to_the_alert_address(): void
    {
        $this->configureBackup(['BACKUP_ALERT_EMAIL' => 'alerts@example.com']);

        $this->finishTasks(exitCode: 1, output: 'Backup failed because: access denied.');

        $emails = $this->sentEmails();

        $this->assertCount(2, $emails);
        $this->assertSame(['alerts@example.com', 'alerts@example.com'], $emails->map(fn (Email $email): string => $email->getTo()[0]->getAddress())->all());
        $this->assertSame('Backup failed because: access denied.', $emails->first()->getTextBody());
    }

    public function test_sends_no_email_when_the_tasks_succeed(): void
    {
        $this->configureBackup(['BACKUP_ALERT_EMAIL' => 'alerts@example.com']);

        $this->finishTasks(exitCode: 0, output: 'Backup completed!');

        $this->assertCount(0, $this->sentEmails());
    }

    public function test_sends_no_email_for_a_failed_task_without_an_alert_address(): void
    {
        $this->configureBackup();

        $this->finishTasks(exitCode: 1, output: 'Backup failed because: access denied.');

        $this->assertCount(0, $this->sentEmails());
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function oneServerVariable(): array
    {
        return [
            'undefined' => [null, false],
            'true' => ['true', true],
        ];
    }

    #[DataProvider('oneServerVariable')]
    public function test_tasks_are_limited_to_one_server_only_when_the_variable_asks_for_it(?string $value, bool $expected): void
    {
        $this->configureBackup(['BACKUP_ON_ONE_SERVER' => $value]);

        $this->assertSame(
            [$expected, $expected],
            array_map(fn (Event $task): bool => $task->onOneServer, $this->app->make(Schedule::class)->events()),
        );
    }

    /**
     * @return array<string, string> Cron expression of each scheduled Artisan command.
     */
    private function scheduledTasks(): array
    {
        return (new Collection($this->app->make(Schedule::class)->events()))
            ->mapWithKeys(fn (Event $task): array => [Str::after((string) $task->command, "'artisan' ") => $task->expression])
            ->all();
    }

    /**
     * Ends every scheduled task the way the scheduler does once its process exits.
     */
    private function finishTasks(int $exitCode, string $output): void
    {
        foreach ($this->app->make(Schedule::class)->events() as $task) {
            $capturesOutput = $task->output !== $task->getDefaultOutput();

            if ($capturesOutput) {
                File::put($task->output, $output);
            }

            try {
                $task->finish($this->app, $exitCode);
            } finally {
                if ($capturesOutput) {
                    File::delete($task->output);
                }
            }
        }
    }

    /**
     * @return Collection<int, Email>
     */
    private function sentEmails(): Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $message): Email => $message->getOriginalMessage());
    }
}
