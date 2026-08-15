<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ScheduleEvent;
use App\Models\ScheduleEventResponse;
use App\Models\World;
use App\Support\Discord;
use App\Support\Webhooks;
use App\Support\WorldNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The GM's real-world scheduling calendar across a world's campaigns. */
class ScheduleController extends Controller
{
    public function index(World $world): Response
    {
        $this->authorize('managePlay', $world);

        return Inertia::render('Worlds/Schedule', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'campaigns' => $world->campaigns()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Campaign $campaign) => ['id' => $campaign->id, 'name' => $campaign->name]),
            'events' => ScheduleEvent::whereIn('campaign_id', $world->campaigns()->pluck('id'))
                ->with('campaign:id,name')
                ->orderBy('starts_at')->get()
                ->map(fn (ScheduleEvent $event) => $this->present($event)),
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('managePlay', $world);

        $data = $request->validate([
            'campaign_id' => ['required', 'integer', Rule::exists('campaigns', 'id')->where('world_id', $world->id)],
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $event = ScheduleEvent::create($data);
        $campaign = $event->campaign;

        Webhooks::dispatch($world, 'session.scheduled', [
            'world' => ['slug' => $world->slug, 'name' => $world->name],
            'campaign' => ['name' => $campaign->name, 'slug' => $campaign->slug],
            'schedule' => [
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'notes' => $event->notes,
            ],
        ]);

        Discord::sessionScheduled($event);

        return back();
    }

    public function update(Request $request, ScheduleEvent $event): RedirectResponse
    {
        $this->authorize('managePlay', $event->campaign->world);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'starts_at' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $event->update($data);

        return back();
    }

    public function destroy(ScheduleEvent $event): RedirectResponse
    {
        $this->authorize('managePlay', $event->campaign->world);

        $event->delete();

        return back();
    }

    /**
     * A player's RSVP to an upcoming session. Only campaign members (or the GM) may respond; the
     * one row per player is created or updated in place.
     */
    public function respond(Request $request, ScheduleEvent $event): RedirectResponse
    {
        $user = $request->user();
        $campaign = $event->campaign;

        abort_unless(
            $user !== null && ($user->can('managePlay', $campaign->world) || $campaign->hasMember($user)),
            403,
        );

        $data = $request->validate([
            'status' => ['required', Rule::in(ScheduleEventResponse::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $event->responses()->updateOrCreate(
            ['user_id' => $user->id],
            ['status' => $data['status'], 'note' => $data['note'] ?? null],
        );

        Webhooks::dispatch($campaign->world, 'rsvp.updated', [
            'world' => ['slug' => $campaign->world->slug, 'name' => $campaign->world->name],
            'campaign' => ['name' => $campaign->name, 'slug' => $campaign->slug],
            'schedule' => ['title' => $event->title, 'starts_at' => $event->starts_at?->toIso8601String()],
            'response' => ['player' => $user->name, 'status' => $data['status']],
        ]);

        return back();
    }

    /** @return array{id: int, campaign_id: int, campaign: string|null, title: string, starts_at: string|null, notes: string|null} */
    private function present(ScheduleEvent $event): array
    {
        return [
            'id' => $event->id,
            'campaign_id' => $event->campaign_id,
            'campaign' => $event->campaign?->name,
            'title' => $event->title,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'notes' => $event->notes,
        ];
    }
}
