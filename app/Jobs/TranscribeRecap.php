<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Controllers\DeepgramWebhookController;
use App\Models\Recap;
use App\Services\Transcription\CallbackTranscriber;
use App\Services\Transcription\Transcriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Stage 2 of building a session {@see Recap} (stage 1 is the S3 upload): transcribe the audio with the
 * configured provider (Deepgram, reading it straight from S3).
 *
 * Two modes. When a public callback URL is configured, we submit the job to Deepgram and return straight
 * away; Deepgram POSTs the transcript to {@see DeepgramWebhookController}, which then
 * hands off to {@see AnalyseRecap}. Otherwise we transcribe synchronously here and hand off ourselves.
 * Kept separate from analysis so a slow transcription and a quick write-up have their own timeouts and can
 * fail independently. The synchronous mode is long by nature — run the worker with a matching `--timeout`.
 */
class TranscribeRecap implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Transcription is non-idempotent (and metered); never silently re-run a partial pass. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public Recap $recap) {}

    public function handle(Transcriber $transcriber): void
    {
        $recap = $this->recap->fresh();
        if ($recap === null || $recap->isFinished()) {
            return;
        }

        try {
            $recap->markStatus('transcribing');

            $callbackUrl = $this->callbackUrl($recap);
            if ($callbackUrl !== null && $transcriber instanceof CallbackTranscriber) {
                // Async: Deepgram delivers the transcript to the webhook, which continues the pipeline.
                $requestId = $transcriber->submit($recap->disk, $recap->path, $callbackUrl);
                $recap->update(['transcription_request_id' => $requestId]);

                // Fail the recap if the callback never lands, rather than leaving it stuck forever. The reaper
                // schedules itself within SQS's per-message delay ceiling and re-arms until the deadline passes.
                ReapStuckTranscription::arm($recap);

                return;
            }

            $transcript = $transcriber->transcribe($recap->disk, $recap->path);
            $recap->update(['transcript' => $transcript, 'status' => 'analyzing']);

            dispatch(new AnalyseRecap($recap));
        } catch (Throwable $e) {
            // Record a safe, user-facing status and re-throw so failed_jobs keeps the full detail.
            $recap->markFailed('Transcription failed unexpectedly ('.class_basename($e).'). Please try again.');

            throw $e;
        }
    }

    /**
     * A signed, per-recap URL Deepgram can POST the finished transcript back to, or null when no public
     * callback host is configured (so we fall back to synchronous transcription). The signature is relative
     * so it validates behind a tunnel/proxy whose host differs from the app's own.
     */
    private function callbackUrl(Recap $recap): ?string
    {
        $base = config('services.deepgram.callback_url');
        if (blank($base)) {
            return null;
        }

        $signedPath = URL::signedRoute('recaps.deepgram.webhook', ['recap' => $recap->id], absolute: false);

        return rtrim((string) $base, '/').$signedPath;
    }
}
