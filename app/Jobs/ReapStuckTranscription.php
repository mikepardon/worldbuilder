<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Recap;
use Carbon\CarbonInterface;
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
 *
 * SQS rejects a per-message delay above 15 minutes, so a longer callback deadline cannot be scheduled in
 * a single hop. The job therefore schedules no further out than that ceiling and re-arms itself until the
 * deadline actually passes — keeping the safety net working on SQS without exceeding its limit.
 */
class ReapStuckTranscription implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** SQS refuses a per-message delay beyond 15 minutes; never schedule a single hop further out than this. */
    private const MAX_DELAY_SECONDS = 15 * 60;

    public int $tries = 1;

    public function __construct(public Recap $recap) {}

    /** Schedule the reaper for the callback deadline, as a first hop no longer than SQS will accept. */
    public static function arm(Recap $recap): void
    {
        dispatch(new self($recap))->delay(self::nextHopSeconds(self::deadlineFor($recap)));
    }

    public function handle(): void
    {
        $recap = $this->recap->fresh();

        // Only reap a recap still stuck awaiting the callback; any other status means it moved on.
        if ($recap === null || $recap->status !== 'transcribing') {
            return;
        }

        // Not due yet — the callback may still be coming. Each hop is capped below the real deadline on SQS,
        // and the sync driver ignores the delay entirely, so re-arm and check again rather than failing early.
        // (On the sync driver the delay is a no-op; skip re-arming there to avoid an immediate tight loop.)
        $deadline = self::deadlineFor($recap);
        if ($deadline->isFuture()) {
            if (! self::onSyncQueue()) {
                dispatch(new self($recap))->delay(self::nextHopSeconds($deadline));
            }

            return;
        }

        $recap->markFailed('The transcript never came back from the provider. Please try again.');
    }

    /** The callback deadline, measured from the recap's last touch (its submission) so re-arming keeps it fixed. */
    private static function deadlineFor(Recap $recap): CarbonInterface
    {
        $timeout = (int) config('services.deepgram.callback_timeout_minutes', 30);

        return ($recap->updated_at ?? now())->addMinutes($timeout);
    }

    /** Seconds until the deadline, clamped to SQS's maximum so a single delayed dispatch is always accepted. */
    private static function nextHopSeconds(CarbonInterface $deadline): int
    {
        $secondsAway = $deadline->getTimestamp() - now()->getTimestamp();

        return max(0, min($secondsAway, self::MAX_DELAY_SECONDS));
    }

    private static function onSyncQueue(): bool
    {
        $connection = config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }
}
