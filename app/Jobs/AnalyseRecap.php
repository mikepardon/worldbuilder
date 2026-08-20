<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Recap;
use App\Services\RecapAnalyzer;
use App\Services\RecapEntityMatcher;
use App\Support\CreditWeights;
use App\Support\Webhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stage 3 of building a session {@see Recap}: turn the transcript {@see TranscribeRecap} produced into the
 * analysis — recap variants, memorable moments, an outline and entities — via the Muse assistant, and mark
 * the recap done. The credit is spent here, on success, so a failed transcription never costs anything.
 */
class AnalyseRecap implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One AI pass; non-idempotent (and metered), so never silently re-run. */
    public int $tries = 1;

    public int $timeout = 600;

    /** $charge is false for a free re-analysis (e.g. re-running after editing the fact list); the recap's
     *  credits were already spent on the first pass and the transcript isn't re-transcribed. */
    public function __construct(public Recap $recap, public bool $charge = true) {}

    public function handle(RecapAnalyzer $analyzer, RecapEntityMatcher $matcher): void
    {
        $recap = $this->recap->fresh();
        if ($recap === null || $recap->isFinished()) {
            return;
        }

        $session = $recap->session;
        $campaign = $session->campaign;
        $world = $campaign->world;

        // Campaign-wide guidance applies to every recap; this recap's own facts stack on top.
        $facts = array_merge($campaign->recap_facts ?? [], $recap->facts ?? []);
        $instructions = $campaign->recap_instructions ?? [];

        try {
            $analysis = $analyzer->analyze($world, $session, (string) $recap->transcript, $recap->detail_level, $recap->user_id, $facts, $instructions);

            DB::transaction(function () use ($recap, $world, $matcher, $analysis): void {
                $recap->update([
                    'recap_full' => $analysis['recap_full'],
                    'recap_short' => $analysis['recap_short'],
                    'recap_stylized' => $analysis['recap_stylized'],
                    'moments' => $analysis['moments'],
                    'outline' => $analysis['outline'],
                    'next_steps' => $analysis['next_steps'],
                    'status' => 'done',
                    // Auto-publish to players unless the world holds recaps for GM review.
                    'published_at' => $world->recapAutoPublish() ? now() : $recap->published_at,
                ]);

                // Persist the extracted entities as rows, auto-linking any that already exist in the world.
                $matcher->populate($recap, $world, $analysis['entities']);
            });

            // Bill by the (now Deepgram-measured) audio length, at the per-hour recap rate — unless this is
            // a free re-analysis of an already-paid recap.
            if ($this->charge) {
                $recap->user?->spendAiCredits(CreditWeights::recapCreditCost((int) $recap->duration_seconds));
            }

            // If it auto-published, announce it (Discord + any subscribed webhooks).
            if ($world->recapAutoPublish()) {
                if (filled($world->discord_webhook)) {
                    dispatch(new NotifyDiscordRecap($recap->session_id));
                }

                $session = $recap->session;
                Webhooks::dispatch($world, 'recap.published', [
                    'world' => ['slug' => $world->slug, 'name' => $world->name],
                    'campaign' => ['name' => $session->campaign->name, 'slug' => $session->campaign->slug],
                    'session' => [
                        'title' => $session->title,
                        'slug' => $session->slug,
                        'url' => url("/w/{$world->slug}/campaigns/{$session->campaign->slug}/sessions/{$session->slug}"),
                    ],
                    'recap' => ['summary' => $recap->recap_short],
                ]);
            }
        } catch (Throwable $e) {
            // Record a safe, user-facing status and re-throw so failed_jobs keeps the full detail.
            $recap->markFailed('Analysis failed unexpectedly ('.class_basename($e).'). Please try again.');

            throw $e;
        }
    }

    /**
     * A job killed by its timeout or reaped for exceeding attempts never reaches the catch in handle(), so
     * without this hook the recap would sit on "analyzing" forever with no error surfaced to the GM. Guard on
     * isFinished() so a late reaper can never clobber a run that already completed (or already failed).
     */
    public function failed(Throwable $exception): void
    {
        $recap = $this->recap->fresh();
        if ($recap !== null && ! $recap->isFinished()) {
            $recap->markFailed('Analysis failed unexpectedly. Please try again.');
        }
    }
}
