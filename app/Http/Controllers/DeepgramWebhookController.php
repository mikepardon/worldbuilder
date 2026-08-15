<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AnalyseRecap;
use App\Jobs\TranscribeRecap;
use App\Models\Recap;
use App\Services\Transcription\CallbackTranscriber;
use App\Services\Transcription\Transcriber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Receives the finished transcript Deepgram POSTs back after an async {@see TranscribeRecap}
 * submission. The route is a per-recap signed URL, so the `signed` middleware authenticates the caller and
 * binds it to exactly one recap — there is no session or CSRF token on a machine-to-machine callback.
 * On success it saves the transcript and hands off to {@see AnalyseRecap}; it is idempotent, so a duplicate
 * delivery is acknowledged without redoing the work.
 */
class DeepgramWebhookController extends Controller
{
    public function __invoke(Request $request, Recap $recap, Transcriber $transcriber): Response
    {
        // The transcript only reaches us through a callback-capable provider; a mismatched binding is a
        // server misconfiguration, not something to silently swallow.
        if (! $transcriber instanceof CallbackTranscriber) {
            throw new RuntimeException('The configured transcriber cannot receive callbacks.');
        }

        // Idempotent: a re-delivery (or a callback for an already-finished recap) is acknowledged as a no-op.
        if ($recap->transcript !== null || $recap->isFinished()) {
            return response()->noContent();
        }

        try {
            $transcript = $transcriber->transcriptFromCallback($request->all());
            $duration = $transcriber->durationSecondsFromCallback($request->all());
        } catch (Throwable $e) {
            $recap->markFailed('Transcription failed unexpectedly ('.class_basename($e).'). Please try again.');

            // Acknowledge so Deepgram doesn't retry a payload that will never parse; the failure is recorded.
            return response()->noContent();
        }

        $recap->update([
            'transcript' => $transcript,
            // Trust the provider's measured duration; keep any client-supplied value only as a fallback.
            'duration_seconds' => $duration > 0 ? $duration : $recap->duration_seconds,
            'status' => 'analyzing',
        ]);

        dispatch(new AnalyseRecap($recap));

        return response()->noContent();
    }
}
