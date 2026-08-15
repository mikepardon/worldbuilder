<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\Session;
use App\Models\World;
use App\Models\WorldMember;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** A signed-in player's home: the worlds they play in and their characters. */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();

        // Worlds of campaigns the viewer is a member of (the new Campaign layer sits between User and World).
        $memberWorldIds = Campaign::whereIn('id', $viewer->memberships()->pluck('campaign_id'))
            ->pluck('world_id');
        // Worlds the viewer co-authors (invited editor).
        $editorWorldIds = WorldMember::where('user_id', $viewer->id)->pluck('world_id');

        // Worlds the viewer runs (owns, co-authors, or — for a platform admin — manages) plus those they play in.
        $worlds = ($viewer->isAdmin()
            ? World::query()
            : World::where('user_id', $viewer->id)
                ->orWhereIn('id', $editorWorldIds)
                ->orWhereIn('id', $memberWorldIds))
            ->orderBy('name')->get()
            ->map(fn (World $world) => [
                'id' => $world->id,
                'name' => $world->name,
                'slug' => $world->slug,
                // Can the viewer GM this world (owner or admin)? Drives "Edit world" vs "Open".
                'can_manage' => $viewer->can('manage', $world),
            ]);

        $characters = Character::where('user_id', $viewer->id)->with(['image', 'campaign:id,name'])
            ->orderBy('name')->get()
            ->map(fn (Character $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'image_url' => $character->image?->url,
                'campaign' => $character->campaign?->name,
                'level' => $character->level,
                'class' => $character->class,
                'race' => $character->race,
                'ac' => $character->ac,
                'hp' => $character->hp,
                'max_hp' => $character->max_hp,
                'stats' => $character->stats,
                'sheet' => $character->sheet,
                // A D&D Beyond link means the sheet can be opened and re-synced from source.
                'ddb_url' => $character->ddb_url,
                'is_ddb' => filled($character->ddb_url),
            ]);

        // Campaigns the viewer plays in (someone else's world), with their recent published recaps.
        $playerCampaigns = Campaign::whereIn('id', $viewer->memberships()->pluck('campaign_id'))
            ->whereHas('world', fn ($query) => $query->where('user_id', '!=', $viewer->id))
            ->with('world:id,name,slug,user_id,settings')
            ->orderBy('name')->get()
            ->map(fn (Campaign $campaign) => [
                'name' => $campaign->name,
                'world' => $campaign->world->name,
                'url' => url("/w/{$campaign->world->slug}/campaigns/{$campaign->slug}"),
                'recaps' => $campaign->sessions()->with('recap')
                    ->orderByDesc('held_on')->orderByDesc('sort')->orderByDesc('id')->get()
                    ->filter(fn (Session $session) => $session->recap !== null
                        && $session->recap->status === 'done'
                        && $session->recap->isPublishedFor($campaign->world))
                    ->take(3)
                    ->map(fn (Session $session) => [
                        'title' => $session->title,
                        'held_on' => $session->held_on?->toDateString(),
                        'url' => url("/w/{$campaign->world->slug}/campaigns/{$campaign->slug}/sessions/{$session->slug}"),
                    ])->values(),
            ]);

        return Inertia::render('Dashboard', [
            'me' => ['id' => $viewer->id, 'name' => $viewer->name],
            'worlds' => $worlds,
            'playerCampaigns' => $playerCampaigns,
            'characters' => $characters,
            // Whether the seeded demo world is available to explore and copy.
            'hasSandbox' => World::where('is_sandbox', true)->exists(),
            // Free accounts own a single world; drives whether the "New world" form is enabled.
            'canCreateWorld' => $viewer->isAdmin() || $viewer->worlds()->count() < $viewer->worldLimit(),
        ]);
    }
}
