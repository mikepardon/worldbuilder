<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CampaignMember;
use App\Models\Session;
use App\Models\User;
use App\Models\World;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The world-level Sessions hub: every session across the world's campaigns, filterable by campaign, with
 * its date, attendees and recap status. GM view (world owner / co-authors).
 */
class WorldSessionController extends Controller
{
    public function index(Request $request, World $world): Response
    {
        $this->authorize('managePlay', $world);

        $campaigns = $world->campaigns()->orderBy('name')->get(['id', 'name']);
        $campaignIds = $campaigns->pluck('id')->all();

        $requested = (int) $request->query('campaign');
        $filter = in_array($requested, $campaignIds, true) ? $requested : null;

        $sessions = Session::query()
            ->whereIn('campaign_id', $campaignIds)
            ->when($filter !== null, fn ($query) => $query->where('campaign_id', $filter))
            ->with(['campaign:id,name', 'recap:id,session_id,status', 'attendees:id,name'])
            ->orderByRaw('held_on IS NULL, held_on DESC')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Session $session): array => [
                'id' => $session->id,
                'title' => $session->title,
                'held_on' => $session->held_on?->toDateString(),
                'campaign_id' => $session->campaign_id,
                'campaign_name' => $session->campaign->name,
                'attendees' => $session->attendees->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all(),
                'attendee_ids' => $session->attendees->pluck('id')->all(),
                'recap_status' => $session->recap?->status,
                'edit_url' => route('sessions.edit', [$world->id, $session->campaign_id, $session->id]),
                'view_url' => route('sessions.view', $session->id),
                'recap_url' => route('sessions.recap.show', [$world->id, $session->campaign_id, $session->id]),
            ])
            ->all();

        // Campaign members for the attendee picker, keyed by campaign id.
        $members = [];
        foreach ($campaigns as $campaign) {
            $members[$campaign->id] = $campaign->members()->with('user:id,name')->get()
                ->map(fn (CampaignMember $member): array => ['id' => $member->user_id, 'name' => $member->user?->name ?? 'Player'])
                ->all();
        }

        return Inertia::render('Worlds/Sessions', [
            'world' => WorldNav::for($world),
            'campaigns' => $campaigns->map(fn ($campaign): array => ['id' => $campaign->id, 'name' => $campaign->name])->all(),
            'filter' => $filter,
            'sessions' => $sessions,
            'members' => $members,
        ]);
    }
}
