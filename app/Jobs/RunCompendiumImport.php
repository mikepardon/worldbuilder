<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompendiumImportRun;
use App\Services\CompendiumImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports a compendium source off the web request, one page/slice at a time. Each run fetches a single
 * chunk from the provider, upserts it, records how far it got on the run, then re-dispatches itself for
 * the next chunk — so no single run is long enough to trip the queue's retry_after or the worker timeout
 * (the failure mode the old synchronous import hit, stranding runs at "running").
 */
class RunCompendiumImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** One chunk per run, kept well under the queue's retry_after. */
    public int $timeout = 200;

    public function __construct(public CompendiumImportRun $run) {}

    public function handle(CompendiumImporter $importer): void
    {
        $run = $this->run->fresh();
        if ($run === null || $run->isFinished()) {
            return;
        }

        $source = $run->source;

        try {
            if ($run->status !== 'running') {
                $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
            }

            $chunk = $importer->provider($source)->chunk($source, $run->cursor);
            $counts = $importer->ingest($source, $chunk['records']);

            $run->update([
                'added' => $run->added + $counts['added'],
                'updated' => $run->updated + $counts['updated'],
                'unchanged' => $run->unchanged + $counts['unchanged'],
                'cursor' => $chunk['next'],
            ]);

            if ($chunk['next'] !== null) {
                dispatch(new self($run));

                return;
            }

            $source->update(['last_run_at' => now()]);
            $run->update(['status' => 'complete', 'finished_at' => now()]);
        } catch (Throwable $error) {
            $run->update(['status' => 'failed', 'error' => Str::limit($error->getMessage(), 480), 'finished_at' => now()]);
            throw $error;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->run->fresh()?->update([
            'status' => 'failed',
            'error' => Str::limit($exception->getMessage(), 480),
            'finished_at' => now(),
        ]);
    }
}
