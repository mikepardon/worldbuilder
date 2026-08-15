<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TranscribeRecap;
use App\Models\Recap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-run transcription for a recap from the CLI, reusing the audio already in storage — the command-line
 * counterpart to the recap page's Retry button. Runs the submit inline so the new provider request id is
 * reported straight away; the callback (async mode) or completion (sync mode) continues on the worker.
 */
class RetryRecapTranscription extends Command
{
    protected $signature = 'recap:retry {recap : The recap id to re-transcribe}';

    protected $description = 'Re-submit a recap for transcription, reusing its stored recording';

    public function handle(): int
    {
        $recap = Recap::find($this->argument('recap'));
        if ($recap === null) {
            $this->error("No recap found with id {$this->argument('recap')}.");

            return self::FAILURE;
        }

        if (! Storage::disk($recap->disk)->exists($recap->path)) {
            $this->error("The recording ({$recap->disk}:{$recap->path}) is no longer in storage.");

            return self::FAILURE;
        }

        $recap->update(['status' => 'queued', 'error' => null, 'transcription_request_id' => null]);

        $this->info("Re-submitting recap {$recap->id} ({$recap->path})…");
        dispatch_sync(new TranscribeRecap($recap));

        $recap->refresh();
        $this->info("Status: {$recap->status}");
        if ($recap->transcription_request_id !== null) {
            $this->info("Provider request id: {$recap->transcription_request_id}");
        }
        if ($recap->status === 'failed') {
            $this->error("Failed: {$recap->error}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
