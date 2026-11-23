<?php

namespace App\Console;

use App\Jobs\ExternalBackupExpirePendingJob;
use App\Jobs\ExternalBackupStartObservationJob;
use App\Models\ExternalBackupRule;
use Carbon\CarbonImmutable;
use App\Jobs\CheckForUpdateJob;
use App\Jobs\RunSecurityChecksJob;
use App\Jobs\TaskDownloadRun;
use App\Models\MonitoredScheduledTaskLogItems;
use App\Models\Task;
use App\Traits\LogsTaskActivity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

class Kernel extends ConsoleKernel
{
    use LogsTaskActivity;

    protected $schedule;

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\rconfigTagList::class,
        Commands\rconfigTagDownload::class,
        Commands\rconfigCatList::class,
        Commands\rconfigCatDownload::class,
        Commands\rconfigDeviceList::class,
        Commands\rconfigDeviceDownload::class,
        Commands\rconfigTaskList::class,
        Commands\rconfigTaskDownload::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $this->schedule = $schedule;

        if (Schema::hasTable('tasks')) {
            // Get all tasks from the database
            $tasks = Task::all();

            // Go through each task to dynamically set them up.
            foreach ($tasks as $task) {

                // if task is_paused, skip it
                if ($task->is_paused) {
                    continue;
                }

                // F02 has its own isolated queue and no native SSH/download job.
                if ($task->task_command === 'rconfig:check-external-backup') {
                    if (config('external-backups.runtime_enabled', false) && config('external-backups.queue_enabled', false)) {
                        $rule = ExternalBackupRule::query()
                            ->where('task_id', $task->id)
                            ->where('enabled', true)
                            ->first();

                        if ($rule !== null) {
                            $scheduledAt = CarbonImmutable::now('UTC')->startOfMinute()->format('Y-m-d H:i:s');
                            $schedule->job(new ExternalBackupStartObservationJob($rule->id, $scheduledAt), 'ExternalBackupChecks', 'redis_external_backups')
                                ->name('f02-external-backup-' . $rule->id)
                                ->cron(implode(' ', $task->task_cron));
                        }
                    }

                    continue;
                }

                // Use the scheduler to add the task at its desired frequency
                $executionStartTime = microtime(true);
                $this->download_task($executionStartTime, $task);
            }
        }

        if (config('external-backups.runtime_enabled', false) && config('external-backups.queue_enabled', false)) {
            $schedule->job(new ExternalBackupExpirePendingJob, 'ExternalBackupChecks', 'redis_external_backups')
                ->name('f02-expire-pending')
                ->everyMinute();
        }

        $schedule->command(RunHealthChecksCommand::class)->everyFiveMinutes();
        $schedule->command(ScheduleCheckHeartbeatCommand::class)->everyFiveMinutes();
        $schedule->command('queue:prune-batches --hours=48 --unfinished=72')->daily();
        $schedule->command('model:prune', ['--model' => MonitoredScheduledTaskLogItems::class])->daily();
        $schedule->command('model:prune', ['--model' => HealthCheckResultHistoryItem::class])->daily();
        $this->schedule->command('rconfig:config-summaries-sync')->dailyAt('3:00');
        $schedule->job(new CheckForUpdateJob)->weeklyOn(6, '03:00')->withoutOverlapping();
        $this->scheduleSecurityReview();
    }

    /**
     * Daily Security Center run on Horizon. It shares the run lock with page runs. Never on the demo site.
     */
    private function scheduleSecurityReview(): void
    {
        if (config('rConfig.is_demo') || config('security-checks.schedule_enabled', true) === false) {
            return;
        }

        $this->schedule->job(RunSecurityChecksJob::scheduled(), (string) config('security-checks.queue', 'rConfigDefault'))
            ->name('security-review-daily-check')
            ->dailyAt((string) config('security-checks.schedule_time', '04:15'));
    }

    /**
     * Schedule a download task. Every supported task_command runs the fixed
     * rconfig:download-task command with the task id as its only argument.
     * A task with an unsupported task_command is skipped and logged.
     */
    private function download_task($executionStartTime, $task)
    {
        if (in_array($task->task_command, TaskDownloadRun::ALLOWED_TASK_COMMANDS, true) === false) {
            Log::warning('Scheduler skipped a task with an unsupported task command.', [
                'task_id' => $task->id,
                'task_command' => $task->task_command,
            ]);

            return;
        }

        $task->task_command = TaskDownloadRun::RUN_COMMAND;

        $this->schedule->command(TaskDownloadRun::RUN_COMMAND, [(int) $task->id])
            ->cron(implode(' ', $task->task_cron))
            ->before(function () use ($task) {
                $this->logTaskStarted($task->id);
            })
            ->appendOutputTo(storage_path() . '/logs/laravel.log')
            ->after(function () use ($task) {
                $logmsg = 'Task command "' . $task->task_command . ' ' . $task->id . '" was run with ID:' . $task->id;
                activityLogIt(__CLASS__, __FUNCTION__, 'info', $logmsg, 'cron_scheduler');
                $this->logTaskFinished($task->id);
            });
    }

    private function afterTaskRunActions($task, $executionStartTime)
    {
        $logmsg = 'Task command "' . $task->task_command . ' ' . $task->id . '" was run with ID:' . $task->id;
        $this->logTaskFinished($task->id);
        activityLogIt(__CLASS__, __FUNCTION__, 'info', $logmsg, 'cron_scheduler');
    }

    private function onTaskFailureActions($task, $executionStartTime)
    {
        $logmsg = 'Task command "' . $task->task_command . ' ' . $task->id . '" failed. See logs for more information.';
        $this->logTaskFailed($task->id);
        activityLogIt(__CLASS__, __FUNCTION__, 'info', $logmsg, 'cron_scheduler');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
