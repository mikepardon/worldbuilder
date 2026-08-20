<?php

declare(strict_types=1);

namespace Tests\Feature;

use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the queue timing invariant behind WORLDBUILDER-5: the database connection's `retry_after` must be
 * longer than the slowest job, or a job that is still legitimately running is re-reserved by the queue and
 * fails with MaxAttemptsExceededException.
 */
class QueueConfigTest extends TestCase
{
    public function test_database_retry_after_exceeds_the_longest_job_timeout(): void
    {
        $longestTimeout = 0;

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }

            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 0;
            $longestTimeout = max($longestTimeout, (int) $timeout);
        }

        $this->assertGreaterThan(
            $longestTimeout,
            (int) config('queue.connections.database.retry_after'),
            "queue.connections.database.retry_after ({$longestTimeout}s longest job) must exceed every job's ".
            'timeout, or a still-running job is re-reserved and fails as "attempted too many times".',
        );
    }
}
