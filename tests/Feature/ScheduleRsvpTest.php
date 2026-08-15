<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ScheduleEvent;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleRsvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_campaign_member_can_rsvp_to_a_session(): void
    {
        [$campaign, $event] = $this->campaignWithEvent();
        $player = $this->member($campaign);

        $this->actingAs($player)->post(route('schedule.respond', $event), [
            'status' => 'attending', 'note' => 'Bringing snacks',
        ])->assertRedirect();

        $this->assertDatabaseHas('schedule_event_responses', [
            'schedule_event_id' => $event->id, 'user_id' => $player->id,
            'status' => 'attending', 'note' => 'Bringing snacks',
        ]);
    }

    public function test_changing_an_rsvp_updates_the_single_row_in_place(): void
    {
        [$campaign, $event] = $this->campaignWithEvent();
        $player = $this->member($campaign);

        $this->actingAs($player)->post(route('schedule.respond', $event), ['status' => 'attending']);
        $this->actingAs($player)->post(route('schedule.respond', $event), ['status' => 'declined']);

        $this->assertDatabaseCount('schedule_event_responses', 1);
        $this->assertDatabaseHas('schedule_event_responses', [
            'schedule_event_id' => $event->id, 'user_id' => $player->id, 'status' => 'declined',
        ]);
    }

    public function test_a_logged_in_non_member_cannot_rsvp(): void
    {
        [, $event] = $this->campaignWithEvent();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('schedule.respond', $event), ['status' => 'attending'])
            ->assertForbidden();

        $this->assertDatabaseCount('schedule_event_responses', 0);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        [$campaign, $event] = $this->campaignWithEvent();
        $player = $this->member($campaign);

        $this->actingAs($player)->post(route('schedule.respond', $event), ['status' => 'maybe'])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('schedule_event_responses', 0);
    }

    public function test_a_member_sees_the_roll_call_of_who_responded(): void
    {
        [$campaign, $event, $world] = $this->campaignWithEvent();
        $alice = $this->member($campaign, 'Alice');
        $this->actingAs($alice)->post(route('schedule.respond', $event), ['status' => 'attending', 'note' => 'yep']);

        $bob = $this->member($campaign, 'Bob');
        $this->actingAs($bob)->get(route('public.campaign.schedule', [$world, $campaign]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canRespond', true)
                ->where('events.0.responses.0.name', 'Alice')
                ->where('events.0.responses.0.status', 'attending'));
    }

    public function test_a_guest_sees_the_dates_but_never_who_responded(): void
    {
        [$campaign, $event, $world] = $this->campaignWithEvent();
        $alice = $this->member($campaign, 'Alice');
        $this->actingAs($alice)->post(route('schedule.respond', $event), ['status' => 'attending', 'note' => 'yep']);

        $this->post(route('logout'));

        $this->get(route('public.campaign.schedule', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canRespond', false)
                ->where('events.0.title', 'Session 6')
                ->has('events.0.responses', 0));
    }

    /** @return array{0: Campaign, 1: ScheduleEvent, 2: World} */
    private function campaignWithEvent(): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $event = $campaign->scheduleEvents()->create(['title' => 'Session 6', 'starts_at' => now()->addWeek()]);

        return [$campaign, $event, $world];
    }

    private function member(Campaign $campaign, string $name = 'Player'): User
    {
        $player = User::factory()->create(['name' => $name]);
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        return $player;
    }
}
