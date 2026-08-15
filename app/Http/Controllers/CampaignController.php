<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\World;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

/**
 * Campaigns — a playthrough inside a {@see World}. Each owns its players, sessions, battle rooms and
 * player characters, while drawing on the world's lore, compendium and maps.
 */
class CampaignController extends Controller
{
    /** Campaigns within a world (GM view). */
    public function index(World $world)
    {
        $this->authorize('managePlay', $world);

        return Inertia::render('Campaigns/Index', [
            'world' => WorldNav::for($world),
            'campaigns' => $world->campaigns()->withCount(['members', 'rooms', 'sessions'])->latest()->get()
                ->map(fn (Campaign $campaign) => $this->card($campaign)),
        ]);
    }

    public function store(Request $request, World $world)
    {
        $this->authorize('managePlay', $world);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $campaign = $world->campaigns()->create([
            ...$data,
            'visibility' => $world->defaultCampaignVisibility(),
            'game_system' => $world->defaultGameSystem(),
        ]);

        return redirect()->route('campaigns.show', [$world, $campaign]);
    }

    /** Update the campaign-wide recap guidance — standing facts/corrections and output instructions. */
    public function updateRecapGuidance(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('manage', $campaign);

        // Validated by hand and returned as JSON: this is called by axios from the recap page.
        $validator = Validator::make($request->all(), [
            'facts' => ['present', 'array'],
            'facts.*' => ['nullable', 'string'],
            'instructions' => ['present', 'array'],
            'instructions.*' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Those changes could not be saved.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $clean = fn (array $items): array => collect($items)
            ->map(fn (?string $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()->all();

        $campaign->update([
            'recap_facts' => $clean($data['facts']),
            'recap_instructions' => $clean($data['instructions']),
        ]);

        return response()->json([
            'recap_facts' => $campaign->recap_facts ?? [],
            'recap_instructions' => $campaign->recap_instructions ?? [],
        ]);
    }

    /** A campaign's dashboard: its players, sessions and rooms. */
    public function show(Request $request, World $world, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $campaign->load(['world', 'members.user:id,name']);

        return Inertia::render('Campaigns/Show', [
            'world' => WorldNav::for($campaign->world),
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'code' => $campaign->code,
                'description' => $campaign->description,
                'visibility' => $campaign->visibility,
                'public_url' => url("/w/{$campaign->world->slug}/campaigns/{$campaign->slug}"),
            ],
            'players' => $campaign->members->map(fn ($member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user?->name,
                'role' => $member->role,
            ]),
            'sessions' => $campaign->sessions()->orderByDesc('sort')->orderByDesc('id')->get()
                ->map(fn ($session) => [
                    'id' => $session->id,
                    'title' => $session->title,
                    'slug' => $session->slug,
                    'summary' => $session->summary,
                    'body' => $session->body,
                    'held_on' => $session->held_on?->toDateString(),
                    'is_private' => $session->is_private,
                ]),
            'rooms' => $campaign->rooms()->withCount(['members', 'tokens'])->latest()->get()
                ->map(fn ($room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'code' => $room->code,
                    'members_count' => $room->members_count,
                    'tokens_count' => $room->tokens_count,
                ]),
        ]);
    }

    /** Consolidated campaign settings hub, opened from the campaign page. */
    public function settings(World $world, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $campaign->load(['world', 'members.user:id,name']);

        return Inertia::render('Campaigns/Settings', [
            'world' => WorldNav::for($campaign->world),
            'campaign' => [
                'id' => $campaign->id,
                'world_id' => $campaign->world_id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'visibility' => $campaign->visibility,
                'game_system' => $campaign->game_system,
                'world_game_system' => $campaign->world->defaultGameSystem(),
                'code' => $campaign->code,
                'public_url' => url("/w/{$campaign->world->slug}/campaigns/{$campaign->slug}"),
                'recap_facts' => $campaign->recap_facts ?? [],
                'recap_instructions' => $campaign->recap_instructions ?? [],
                // The secret itself is never sent to the client — only whether one is connected.
                'discord_connected' => filled($campaign->discord_webhook),
                'world_discord_connected' => filled($campaign->world->discord_webhook),
            ],
            'players' => $campaign->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'role' => $member->role,
            ]),
            'playersUrl' => route('players.index', $campaign->id),
        ]);
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'in:private,hidden,public'],
            'game_system' => ['sometimes', 'nullable', 'string', 'max:40'],
            'discord_webhook' => ['sometimes', 'nullable', 'url', 'max:255', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
        ]);

        $campaign->fill(Arr::only($data, ['name', 'description', 'visibility', 'game_system']));

        // A blank webhook clears the campaign override (notifications fall back to the world's); anything
        // else is stored (encrypted) on the column. The secret never round-trips through mass assignment.
        if (array_key_exists('discord_webhook', $data)) {
            $campaign->discord_webhook = blank($data['discord_webhook']) ? null : $data['discord_webhook'];
        }

        $campaign->save();

        return back();
    }

    public function destroy(Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $world = $campaign->world;
        $campaign->delete();

        return redirect()->route('campaigns.index', $world);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'slug' => $campaign->slug,
            'code' => $campaign->code,
            'description' => $campaign->description,
            'members_count' => $campaign->members_count,
            'rooms_count' => $campaign->rooms_count,
            'sessions_count' => $campaign->sessions_count,
        ];
    }
}
