<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Recap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A safety net for async transcription: dispatched (delayed) alongside a Deepgram submission, it fires
 * after the callback deadline and fails the recap if it's still waiting — i.e. the provider's callback
 * never arrived (a dropped delivery, a down tunnel). Once the callback lands the recap has already moved
 * on from `transcribing`, so this becomes a no-op. See {@see TranscribeRecap}.
 */
class ReapStuckTranscription implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public Recap $recap) {}

    public function handle(): void
    {
        $recap = $this->recap->fresh();

        // Only reap a recap still stuck awaiting the callback; any other status means it moved on.
        if ($recap === null || $recap->status !== 'transcribing') {
            return;
        }

        // Guard against firing early — the sync queue ignores the dispatch delay, and clocks drift. Only
        // reap once the callback deadline (measured from when transcription was submitted) has actually passed.
        $timeout = (int) config('services.deepgram.callback_timeout_minutes', 30);
        if ($recap->updated_at !== null && $recap->updated_at->addMinutes($timeout)->isFuture()) {
            return;
        }

        $recap->markFailed('The transcript never came back from the provider. Please try again.');
    }
}
