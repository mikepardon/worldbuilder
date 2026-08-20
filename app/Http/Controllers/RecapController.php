<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AnalyseRecap;
use App\Jobs\NotifyDiscordRecap;
use App\Jobs\TranscribeRecap;
use App\Models\Campaign;
use App\Models\Recap;
use App\Models\RecapEntity;
use App\Models\Session;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Support\CreditWeights;
use App\Support\RecapEntityPresenter;
use App\Support\Webhooks;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A session's Recap — the post-play analysis built from an uploaded recording. One per session. The audio
 * is uploaded straight to S3 (via the multipart signing endpoints); `store` records the object and queues
 * {@see TranscribeRecap} (then {@see AnalyseRecap}); this page polls `status` until it's ready. Managed by the campaign GM.
 */
class RecapController extends Controller
{
    /** The recap page: the analysis when ready, or the uploader when there isn't one yet. */
    public function show(World $world, Campaign $campaign, Session $session): Response
    {
        $this->authorize('manage', $campaign);

        $recap = $session->recap;

        return Inertia::render('Recaps/Show', [
            'world' => WorldNav::for($world),
            'session' => ['id' => $session->id, 'title' => $session->title],
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'recap_facts' => $campaign->recap_facts ?? [],
                'recap_instructions' => $campaign->recap_instructions ?? [],
            ],
            'recap' => $recap ? $this->payload($recap) : null,
        ]);
    }

    /** Record an audio object already uploaded to S3 and queue it for transcription + analysis. */
    public function store(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'key' => ['required', 'string'],
            'original_name' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'detail_level' => ['sometimes', 'in:brief,comprehensive'],
        ]);

        abort_unless(str_starts_with($data['key'], "recaps/{$session->id}/"), 403);

        // Recaps are billed by audio length, so the recording's duration must be known up front to price it.
        $duration = (int) ($data['duration_seconds'] ?? 0);
        if ($duration < 1) {
            return response()->json(['message' => 'We couldn’t read the recording’s length — please try uploading again.'], 422);
        }

        // Check the length-based cost before any transcription or analysis runs — don't start work they can't pay for.
        $user = $request->user();
        $cost = CreditWeights::recapCreditCost($duration);
        if (! $user->canSpendAiCredits($cost)) {
            return response()->json([
                'message' => "This recap (~{$this->hoursLabel($duration)}) costs {$cost} credits — you have {$user->aiCreditsRemaining()}. Top up or upgrade for more.",
                'outOfCredits' => true,
                'requiredCredits' => $cost,
            ], 402);
        }

        if (! Storage::disk('s3')->exists($data['key'])) {
            return response()->json(['message' => 'That upload could not be found in storage — please try again.'], 422);
        }

        // Record the stored audio's real size so it counts toward the owner's storage quota.
        $sizeBytes = (int) Storage::disk('s3')->size($data['key']);

        // One recap per session: replace any existing one (and tidy up its stored audio).
        $existing = $session->recap;
        if ($existing !== null) {
            $this->deleteAudio($existing);
            $existing->delete();
        }

        $recap = $session->recap()->create([
            'user_id' => $user->id,
            'disk' => 's3',
            'path' => $data['key'],
            'original_name' => $data['original_name'] ?? null,
            'duration_seconds' => (int) ($data['duration_seconds'] ?? 0),
            'size_bytes' => $sizeBytes,
            'detail_level' => $data['detail_level'] ?? $session->campaign->world->defaultRecapDetail(),
            'status' => 'queued',
        ]);

        dispatch(new TranscribeRecap($recap));

        return response()->json($this->payload($recap), 202);
    }

    /**
     * Create a recap from pasted text (a transcript or the GM's own notes) instead of audio. Runs the exact
     * same analysis as an audio recap, just skipping transcription — the text becomes the transcript.
     */
    public function storeText(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:50', 'max:100000'],
            'detail_level' => ['sometimes', 'in:brief,comprehensive'],
        ]);

        if (! app(AnthropicClient::class)->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $text = trim($data['text']);

        // There's no audio length to price by, so estimate an equivalent spoken duration (~150 words/minute)
        // and reuse the same per-hour recap pricing as audio, with a one-credit floor.
        $duration = max(60, (int) ceil(str_word_count($text) / 150 * 60));

        $user = $request->user();
        $cost = CreditWeights::recapCreditCost($duration);
        if (! $user->canSpendAiCredits($cost)) {
            return response()->json([
                'message' => "This recap costs {$cost} credits — you have {$user->aiCreditsRemaining()}. Top up or upgrade for more.",
                'outOfCredits' => true,
                'requiredCredits' => $cost,
            ], 402);
        }

        // One recap per session: replace any existing one (and tidy up its stored audio).
        $existing = $session->recap;
        if ($existing !== null) {
            $this->deleteAudio($existing);
            $existing->delete();
        }

        $recap = $session->recap()->create([
            'user_id' => $user->id,
            'disk' => '',
            'path' => '',
            'original_name' => null,
            'duration_seconds' => $duration,
            'size_bytes' => 0,
            'detail_level' => $data['detail_level'] ?? $session->campaign->world->defaultRecapDetail(),
            'status' => 'analyzing',
            'transcript' => $text,
        ]);

        // Straight to analysis — no transcription step. Billed on success like an audio recap.
        dispatch(new AnalyseRecap($recap));

        return response()->json($this->payload($recap), 202);
    }

    /** Re-run transcription for an existing recap (e.g. after a dropped callback), reusing the stored audio. */
    public function retry(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        abort_if($recap === null, 404);

        // The source audio may have been deleted to reclaim storage; without it there's nothing to re-transcribe.
        if (! $recap->hasAudio()) {
            return response()->json(['message' => 'The source audio was deleted to save storage, so this recording can’t be re-transcribed. Your saved recap is unaffected.'], 422);
        }

        $user = $request->user();
        $cost = CreditWeights::recapCreditCost((int) $recap->duration_seconds);
        if (! $user->canSpendAiCredits($cost)) {
            return response()->json([
                'message' => "This recap costs {$cost} credits — you have {$user->aiCreditsRemaining()}. Top up or upgrade for more.",
                'outOfCredits' => true,
                'requiredCredits' => $cost,
            ], 402);
        }

        if (! Storage::disk($recap->disk)->exists($recap->path)) {
            return response()->json(['message' => 'The original recording is no longer in storage — please upload again.'], 422);
        }

        // Reset to the start of the pipeline (clearing the stale request id/error) and re-queue.
        $recap->update(['status' => 'queued', 'error' => null, 'transcription_request_id' => null]);

        dispatch(new TranscribeRecap($recap));

        return response()->json($this->payload($recap), 202);
    }

    /** Poll the recap's status and, once ready, its analysis. */
    public function status(Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;

        return response()->json($recap ? $this->payload($recap) : ['status' => 'none']);
    }

    /** Re-run only the analysis on the existing transcript (e.g. after editing facts) — free, no re-transcribe. */
    public function reanalyse(Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        abort_if($recap === null, 404);
        abort_if(blank($recap->transcript), 422, 'There is no transcript to re-analyse yet.');

        $recap->update(['status' => 'analyzing', 'error' => null]);

        dispatch(new AnalyseRecap($recap, charge: false));

        return response()->json($this->payload($recap), 202);
    }

    /** Save the GM's edits to the analysis: the recap variants, memorable moments and scene outline. */
    public function updateContent(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        abort_if($recap === null, 404);

        // Validate by hand and return JSON errors: this endpoint is called by axios, and the app only
        // renders validation exceptions as JSON on api/* routes (a redirect would break the axios caller).
        $validator = Validator::make($request->all(), [
            'recap_full' => ['nullable', 'string'],
            'recap_short' => ['nullable', 'string'],
            'recap_stylized' => ['nullable', 'string'],
            'moments' => ['present', 'array'],
            'moments.*.type' => ['required', Rule::in(Recap::MOMENT_TYPES)],
            'moments.*.description' => ['required', 'string'],
            'moments.*.context' => ['nullable', 'string'],
            'outline' => ['present', 'array'],
            'outline.*.title' => ['required', 'string', 'max:180'],
            'outline.*.detail' => ['nullable', 'string'],
            'next_steps' => ['sometimes', 'array'],
            'next_steps.*' => ['nullable', 'string'],
            'facts' => ['sometimes', 'array'],
            'facts.*' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Those changes could not be saved.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $update = [
            'recap_full' => trim((string) ($data['recap_full'] ?? '')),
            'recap_short' => trim((string) ($data['recap_short'] ?? '')),
            'recap_stylized' => trim((string) ($data['recap_stylized'] ?? '')),
            'moments' => collect($data['moments'])->map(fn (array $moment): array => [
                'type' => $moment['type'],
                'description' => trim($moment['description']),
                'context' => trim((string) ($moment['context'] ?? '')),
            ])->values()->all(),
            'outline' => collect($data['outline'])->map(fn (array $scene): array => [
                'title' => trim($scene['title']),
                'detail' => trim((string) ($scene['detail'] ?? '')),
            ])->values()->all(),
        ];

        // Only touch these lists when the caller sends them, so a partial save can't silently wipe them.
        if (array_key_exists('next_steps', $data)) {
            $update['next_steps'] = $this->cleanList($data['next_steps']);
        }
        if (array_key_exists('facts', $data)) {
            $update['facts'] = $this->cleanList($data['facts']);
        }

        $recap->update($update);

        return response()->json($this->payload($recap));
    }

    /** Create a public, read-only share link for this recap so it can be sent to anyone, not just members. */
    public function share(Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        abort_if($recap === null, 404);
        abort_unless($recap->status === 'done', 422, 'Only a finished recap can be shared.');

        if ($recap->share_token === null) {
            $recap->update(['share_token' => Str::random(40)]);
        }

        return response()->json($this->payload($recap));
    }

    /** Revoke the public share link. */
    public function unshare(Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        abort_if($recap === null, 404);

        $recap->update(['share_token' => null]);

        return response()->json($this->payload($recap));
    }

    /** The GM's 1–5 star rating of the analysis quality. */
    public function rate(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5']]);

        $recap = $session->recap;
        abort_if($recap === null, 404);
        $recap->update(['rating' => $data['rating']]);

        return response()->json(['rating' => $recap->rating]);
    }

    /** Remove the recap so a fresh recording can be uploaded. */
    public function destroy(Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        if ($recap !== null) {
            $this->deleteAudio($recap);
            $recap->delete();
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * Trim a list of strings and drop the blanks, reindexed.
     *
     * @param  array<int, string|null>  $items
     * @return list<string>
     */
    private function cleanList(array $items): array
    {
        return collect($items)
            ->map(fn (?string $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()->all();
    }

    /** A short "N.N h" / "N min" label for an audio length in seconds. */
    private function hoursLabel(int $seconds): string
    {
        return $seconds >= 3600
            ? number_format($seconds / 3600, 1).' h'
            : max(1, (int) round($seconds / 60)).' min';
    }

    private function deleteAudio(Recap $recap): void
    {
        if ($recap->disk !== '' && $recap->path !== '') {
            Storage::disk($recap->disk)->delete($recap->path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    /** Publish or unpublish a finished recap to players. */
    public function publish(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $recap = $session->recap;
        abort_unless($recap !== null && $recap->status === 'done', 404);

        $data = $request->validate(['published' => ['required', 'boolean']]);
        $wasPublished = $recap->published_at !== null;
        $recap->update(['published_at' => $data['published'] ? now() : null]);

        // Announce only on the transition into published — never on a re-toggle.
        if ($data['published'] && ! $wasPublished) {
            $world = $session->campaign->world;

            if (filled($world->discord_webhook)) {
                dispatch(new NotifyDiscordRecap($session->id));
            }

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

        return response()->json($this->payload($recap));
    }

    private function payload(Recap $recap): array
    {
        $recap->loadMissing(['entities.linkedDocument', 'entities.linkedCompendiumItem']);

        return [
            'id' => $recap->id,
            'status' => $recap->status,
            'published' => $recap->published_at !== null,
            'auto_publish' => $recap->session->campaign->world->recapAutoPublish(),
            'share_url' => $recap->share_token !== null ? url("/recap/{$recap->share_token}") : null,
            'transcription_request_id' => $recap->transcription_request_id,
            'detail_level' => $recap->detail_level,
            'original_name' => $recap->original_name,
            // Text recaps have no audio: the UI skips the transcription step and offers re-analyse over retry.
            'has_audio' => $recap->hasAudio(),
            'rating' => $recap->rating,
            'transcript' => $recap->transcript,
            'recap_full' => $recap->recap_full,
            'recap_short' => $recap->recap_short,
            'recap_stylized' => $recap->recap_stylized,
            'moments' => $recap->moments ?? [],
            'outline' => $recap->outline ?? [],
            'next_steps' => $recap->next_steps ?? [],
            'facts' => $recap->facts ?? [],
            'entities' => $recap->entities->map(fn (RecapEntity $entity): array => RecapEntityPresenter::present($entity))->all(),
            'error' => $recap->error,
        ];
    }
}
