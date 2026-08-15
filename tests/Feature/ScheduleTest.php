<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_schedule_an_event(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->post(route('schedule.store', $world), [
            'campaign_id' => $campaign->id,
            'title' => 'Session 4',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('schedule_events', [
            'campaign_id' => $campaign->id, 'title' => 'Session 4',
        ]);
    }

    public function test_a_co_author_cannot_manage_the_schedule_but_a_moderator_can(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $coauthor = User::factory()->create();
        $world->members()->create(['user_id' => $coauthor->id, 'role' => 'editor']);
        $this->actingAs($coauthor)->get(route('schedule.index', $world))->assertForbidden();

        $moderator = User::factory()->create();
        $world->members()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
        $this->actingAs($moderator)->get(route('schedule.index', $world))->assertOk();
    }

    public function test_only_upcoming_events_reach_the_public_campaign_overview(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $campaign->scheduleEvents()->create(['title' => 'Next game', 'starts_at' => now()->addWeek()]);
        $campaign->scheduleEvents()->create(['title' => 'Old game', 'starts_at' => now()->subWeek()]);

        $this->get(route('public.campaign', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('upcoming', 1)
                ->where('upcoming.0.title', 'Next game'));
    }

    public function test_scheduling_into_another_worlds_campaign_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $otherCampaign = $gm->worlds()->create(['name' => 'Other', 'visibility' => 'public'])->campaigns()->firstOrFail();

        $this->actingAs($gm)->post(route('schedule.store', $world), [
            'campaign_id' => $otherCampaign->id,
            'title' => 'Nope',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertSessionHasErrors('campaign_id');
    }
}
