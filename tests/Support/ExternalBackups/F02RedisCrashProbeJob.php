<?php

namespace Tests\Support\ExternalBackups;

use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * CI-only Redis job proving redelivery after a lost worker acknowledgment.
 *
 * This is NOT an F02 production job: it observes synthetic DB rows only.
 */
final class F02RedisCrashProbeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(
        public readonly int $runId,
        public readonly string $marker,
        public readonly string $queueName
    ) {}

    public function handle(): void
    {
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('F02_SYNTHETIC_REDIS_QUEUE') !== '1'
            || ! app()->environment('testing')
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'v8core_test'
            || config('database.redis.default.host') !== '127.0.0.1'
            || preg_match('/\Af02_ci_[a-f0-9]{16}\z/', $this->queueName) !== 1) {
            throw new RuntimeException('Redis crash probe is permitted only in isolated GitHub CI.');
        }

        $run = ExternalBackupCheckRun::findOrFail($this->runId);

        if ($run->status !== 'pending' || $run->receipt()->exists()
            || ExternalBackupFileReceipt::where('check_run_id', $run->id)->exists()) {
            throw new RuntimeException('Synthetic run is not eligible for the Redis crash probe.');
        }

        $attempt = $this->job?->attempts() ?? 0;
        if ($attempt !== 1 && $attempt !== 2) {
            throw new RuntimeException('Redis crash probe received an unexpected attempt count.');
        }

        // Both delivery attempts use exactly the same UTC observation. This
        // asserts that the transactional state service is retry-idempotent.
        (new ExternalBackupRunStateService)->recordPending(
            $run->id,
            CarbonImmutable::parse('2026-11-24 15:05:00', 'UTC'),
            'missing'
        );

        if ($attempt === 1) {
            // The real queue:work process is killed after COMMIT but before
            // Laravel can DELETE/ACK the Redis reservation.
            if (file_put_contents($this->marker . '.started', "committed\n") === false) {
                throw new RuntimeException('Cannot signal synthetic committed job.');
            }

            usleep(15000000); // Parent should SIGKILL well before this expires.
            throw new RuntimeException('Synthetic first delivery was not interrupted.');
        }

        if (file_put_contents($this->marker . '.done', "attempts=2\n") === false) {
            throw new RuntimeException('Cannot signal successful synthetic Redis redelivery.');
        }
    }
}
