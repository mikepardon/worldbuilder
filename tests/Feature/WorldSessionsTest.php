<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_world_sessions_hub_lists_a_worlds_sessions(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->sessions()->create(['title' => 'Session 1']);
        $campaign->sessions()->create(['title' => 'Session 2']);

        $this->actingAs($gm)->get(route('worlds.sessions', $world))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Worlds/Sessions')->has('sessions', 2));
    }

    public function test_a_gm_can_record_a_sessions_date_and_attendees(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $session = $campaign->sessions()->create(['title' => 'Session 1']);

        $this->actingAs($gm)->putJson(route('sessions.details', $session), [
            'held_on' => '2026-08-01',
            'attendee_ids' => [$player->id],
        ])->assertOk()
            ->assertJsonPath('held_on', '2026-08-01')
            ->assertJsonPath('attendee_ids.0', $player->id);

        $session->refresh();
        $this->assertSame('2026-08-01', $session->held_on->toDateString());
        $this->assertTrue($session->attendees()->where('users.id', $player->id)->exists());
    }

    public function test_attendees_are_limited_to_campaign_members(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Session 1']);

        $this->actingAs($gm)->putJson(route('sessions.details', $session), [
            'held_on' => null, 'attendee_ids' => [$stranger->id],
        ])->assertOk();

        $this->assertSame(0, $session->attendees()->count());
    }

    public function test_a_player_sees_only_the_stylised_recap(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $session = $campaign->sessions()->create(['title' => 'Session 1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'x', 'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_stylized' => 'Previously on Saltmere…', 'recap_full' => 'SECRET full recap', 'transcript' => 'SECRET transcript',
            'moments' => [['type' => 'epic', 'description' => 'x', 'context' => '']],
        ]);

        $this->actingAs($player)->get(route('sessions.view', $session))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sessions/View')
                ->where('isGm', false)
                ->where('recap.recap_stylized', 'Previously on Saltmere…')
                ->where('recap.recap_full', null)
                ->where('recap.transcript', null)
                ->where('recap.moments', []));
    }

    public function test_the_gm_sees_the_full_analysis(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Session 1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'x', 'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_stylized' => 'Stylised', 'recap_full' => 'Full recap', 'transcript' => 'Transcript',
        ]);

        $this->actingAs($gm)->get(route('sessions.view', $session))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isGm', true)
                ->where('recap.recap_full', 'Full recap')
                ->where('recap.transcript', 'Transcript'));
    }

    public function test_a_non_member_cannot_view_a_session(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Session 1']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('sessions.view', $session))->assertForbidden();
    }
}
