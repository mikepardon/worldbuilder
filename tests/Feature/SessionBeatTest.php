<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionBeatTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $gm): Session
    {
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'A Crown of Salt']);

        return $campaign->sessions()->create(['title' => 'Beneath the Chapel']);
    }

    public function test_a_gm_can_add_a_beat_which_defaults_to_an_event(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);

        $this->actingAs($gm)->post(route('beats.store', $session), [
            'body' => 'The party wakes to bells that should not be ringing.',
        ])->assertRedirect();

        $beat = $session->beats()->sole();
        $this->assertSame('event', $beat->kind);
        $this->assertSame('The party wakes to bells that should not be ringing.', $beat->body);
        $this->assertSame(1, $beat->sort);
    }

    public function test_beats_sort_after_existing_ones(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);

        $this->actingAs($gm)->post(route('beats.store', $session), ['kind' => 'start']);
        $this->actingAs($gm)->post(route('beats.store', $session), ['kind' => 'event']);

        $this->assertSame([1, 2], $session->beats()->orderBy('id')->pluck('sort')->all());
    }

    public function test_a_beat_of_an_unknown_kind_is_rejected(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);

        $this->actingAs($gm)->post(route('beats.store', $session), ['kind' => 'monologue'])
            ->assertSessionHasErrors('kind');
    }

    public function test_a_gm_can_mark_a_beat_as_the_main_encounter(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);
        $beat = $session->beats()->create(['kind' => 'event', 'body' => 'A fight', 'sort' => 1]);

        $this->actingAs($gm)->put(route('beats.update', $beat), ['kind' => 'encounter'])
            ->assertRedirect();

        $this->assertSame('encounter', $beat->refresh()->kind);
    }

    public function test_reordering_beats_rewrites_their_sort(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);
        $first = $session->beats()->create(['kind' => 'start', 'sort' => 0]);
        $second = $session->beats()->create(['kind' => 'event', 'sort' => 1]);

        $this->actingAs($gm)->put(route('beats.reorder', $session), [
            'ids' => [$second->id, $first->id],
        ])->assertRedirect();

        $this->assertSame(0, $second->refresh()->sort);
        $this->assertSame(1, $first->refresh()->sort);
    }

    public function test_a_gm_can_delete_a_beat(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);
        $beat = $session->beats()->create(['kind' => 'link', 'sort' => 1]);

        $this->actingAs($gm)->delete(route('beats.destroy', $beat))->assertRedirect();

        $this->assertDatabaseMissing('session_beats', ['id' => $beat->id]);
    }

    public function test_deleting_a_session_deletes_its_beats(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);
        $beat = $session->beats()->create(['kind' => 'event', 'sort' => 1]);

        $this->actingAs($gm)->delete(route('sessions.destroy', $session))->assertRedirect();

        $this->assertDatabaseMissing('session_beats', ['id' => $beat->id]);
    }

    public function test_a_gm_can_set_a_sessions_quest(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);

        $this->actingAs($gm)->patch(route('sessions.organise', $session), [
            'arc_id' => null,
            'quest' => 'Silence the choir under the chapel before it finishes its song.',
        ])->assertRedirect();

        $this->assertSame(
            'Silence the choir under the chapel before it finishes its song.',
            $session->refresh()->quest,
        );
    }

    public function test_a_stranger_cannot_add_a_beat(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $session = $this->makeSession($gm);

        $this->actingAs($stranger)->post(route('beats.store', $session), ['body' => 'sneaky'])
            ->assertForbidden();
    }

    public function test_the_campaign_page_exposes_a_sessions_quest_and_beats(): void
    {
        $gm = User::factory()->create();
        $session = $this->makeSession($gm);
        $session->update(['quest' => 'Silence the choir.']);
        $session->beats()->create(['kind' => 'start', 'body' => 'Bells ring.', 'sort' => 1]);

        $this->actingAs($gm)->get(route('campaigns.show', [$session->campaign->world, $session->campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Show')
                ->where('sessions.0.quest', 'Silence the choir.')
                ->has('sessions.0.beats', 1)
                ->where('sessions.0.beats.0.kind', 'start')
                ->where('sessions.0.beats.0.body', 'Bells ring.'));
    }
}
