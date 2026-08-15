<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignCompendiumItem;
use App\Models\Character;
use App\Models\Document;
use App\Models\RecapEntity;
use App\Models\Session;
use App\Services\AnthropicClient;
use App\Support\AiUsageContext;
use App\Support\Compendium;
use App\Support\CreditWeights;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Sessions within a {@see Campaign} — play recaps/prep. Managed by the campaign's GM (the world owner).
 * Edited in the full markdown editor (with the Muse assistant), reusing the document BrewEditor.
 */
class SessionController extends Controller
{
    public function store(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $this->validated($request);
        // New sessions inherit the world's "GM-only by default" setting unless the request says otherwise.
        $data['is_private'] ??= $campaign->world->newSessionsPrivate();
        $campaign->sessions()->create($data);

        return back();
    }

    /** Full-page session editor (markdown + Muse), mirroring the document editor. */
    public function edit(Session $session): Response
    {
        $this->authorize('manage', $session->campaign);

        $campaign = $session->campaign;
        $world = $campaign->world;

        return Inertia::render('Sessions/Edit', [
            // BrewEditor's back link points at campaigns.show, which is nested under the world.
            'campaign' => ['id' => $campaign->id, 'world_id' => $campaign->world_id, 'name' => $campaign->name],
            'document' => [
                'id' => $session->id,
                'title' => $session->title,
                'kind' => 'session',
                'kindLabel' => 'Session',
                'content' => $session->body,
                'summary' => $session->summary,
                'data' => (object) [],
                'is_private' => $session->is_private,
                'tags' => [],
            ],
            'entries' => $world->documents()
                ->orderBy('title')
                ->get(['id', 'title', 'kind'])
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'kind' => $document->kind,
                    'kindLabel' => Sections::kindLabel($document->kind),
                ]),
            'characters' => Character::whereIn('campaign_id', $world->campaigns()->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Character $character) => ['id' => $character->id, 'name' => $character->name, 'slug' => $character->slug]),
            'compendium' => $world->compendiumItems()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'item_type'])
                ->map(fn (CampaignCompendiumItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'type' => $item->item_type,
                    'typeLabel' => Compendium::label($item->item_type),
                    'token' => '{{'.$item->item_type.'='.$item->id.'}}',
                ]),
            'embeds' => $world->compendiumItems()->where('is_active', true)->with('image')->get()
                ->map(fn (CampaignCompendiumItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'item_type' => $item->item_type,
                    'typeLabel' => Compendium::label($item->item_type),
                    'block' => $item->item_type === 'monster' ? data_get($item->fields, 'block') : null,
                    'image_url' => $item->image?->url,
                    'document' => $item->item_type === 'monster' ? null : $item->document,
                ])->values(),
            'spells' => $world->compendiumItems()
                ->where('item_type', 'spell')
                ->orderBy('name')
                ->get(['id', 'name', 'summary', 'document', 'fields'])
                ->map(fn (CampaignCompendiumItem $spell) => ['id' => $spell->id, 'name' => $spell->name, 'summary' => $spell->summary, 'document' => $spell->document, 'fields' => $spell->fields ?? []])
                ->values(),
            'allTags' => $world->documents()->pluck('tags')->flatten()->filter()->unique()->sort()->values()->all(),
        ]);
    }

    public function update(Request $request, Session $session)
    {
        $this->authorize('manage', $session->campaign);

        $data = $this->validated($request);
        // The full editor sends the markdown body as `content`; map it onto the session's `body`.
        if ($request->has('content')) {
            $data['body'] = $request->string('content')->toString();
        }

        $session->update($data);

        return back();
    }

    /** Record a session's date and attendees (from the world Sessions hub). Called by axios → JSON. */
    public function details(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $validator = Validator::make($request->all(), [
            'held_on' => ['nullable', 'date'],
            'attendee_ids' => ['present', 'array'],
            'attendee_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Those changes could not be saved.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $session->update(['held_on' => $data['held_on'] ?? null]);

        // Only attach users who actually belong to this campaign.
        $memberIds = $session->campaign->members()->pluck('user_id')->all();
        $ids = array_values(array_intersect(array_map('intval', $data['attendee_ids']), $memberIds));
        $session->attendees()->sync($ids);

        return response()->json([
            'held_on' => $session->held_on?->toDateString(),
            'attendee_ids' => $session->attendees()->pluck('users.id')->all(),
        ]);
    }

    /**
     * A session as seen by the table: players get the stylised recap; the GM additionally gets the full
     * analysis. Player-only payloads never include the full analysis or transcript.
     */
    public function view(Request $request, Session $session): Response
    {
        $campaign = $session->campaign;
        $user = $request->user();
        $isGm = $user->isAdmin() || $campaign->world->user_id === $user->id;
        abort_unless($isGm || $campaign->hasMember($user), 403);

        $recap = $session->recap;
        $done = $recap !== null && $recap->status === 'done';

        return Inertia::render('Sessions/View', [
            'session' => ['id' => $session->id, 'title' => $session->title, 'held_on' => $session->held_on?->toDateString()],
            'campaign' => ['name' => $campaign->name],
            'world' => ['name' => $campaign->world->name],
            'isGm' => $isGm,
            'attendees' => $session->attendees()->orderBy('name')->pluck('name')->all(),
            'recap' => $done ? [
                'recap_stylized' => $recap->recap_stylized,
                'recap_full' => $isGm ? $recap->recap_full : null,
                'recap_short' => $isGm ? $recap->recap_short : null,
                'moments' => $isGm ? ($recap->moments ?? []) : [],
                'outline' => $isGm ? ($recap->outline ?? []) : [],
                'next_steps' => $isGm ? ($recap->next_steps ?? []) : [],
                'entities' => $isGm ? $recap->entities->map(fn (RecapEntity $entity): array => [
                    'name' => $entity->name, 'type' => $entity->type, 'description' => $entity->description,
                ])->all() : [],
                'transcript' => $isGm ? $recap->transcript : null,
            ] : null,
        ]);
    }

    /** Muse assistant for a session, mirroring AiController@ask but scoped to the session's recap. */
    public function ask(Request $request, Session $session, AnthropicClient $ai): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'content' => ['nullable', 'string'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('session_writeup', 'session');
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $world = $session->campaign->world;
        $content = $data['content'] ?? $session->body ?? '';
        $system = "You are a worldbuilding assistant for a tabletop RPG campaign called \"{$world->name}\"."
            .($world->setting ? " The setting: {$world->setting}." : '')
            ." You are helping the GM with a session recap titled \"{$session->title}\"."
            .' Be concise and evocative, stay consistent with the established world, and respond in Markdown.'
            ."\n\nThe current session write-up is:\n\n{$content}";

        $messages = [];
        foreach ($data['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $data['prompt']];

        try {
            $reply = $ai->chat($system, $messages, 1500, new AiUsageContext('session_writeup', $world->id, $user->id, 'session'));
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user->spendAiCredits($creditCost);

        return response()->json(['reply' => $reply, 'creditsRemaining' => $user->aiCreditsRemaining()]);
    }

    public function destroy(Session $session)
    {
        $this->authorize('manage', $session->campaign);

        $session->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string'],
            'held_on' => ['nullable', 'date'],
            'sort' => ['sometimes', 'integer'],
            'is_private' => ['sometimes', 'boolean'],
        ]);
    }
}
