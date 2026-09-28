<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ArcBoardTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(User $gm): Campaign
    {
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);

        return $world->campaigns()->create(['name' => 'A Crown of Salt']);
    }

    public function test_a_gm_can_create_an_arc_which_defaults_to_upcoming(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);

        $this->actingAs($gm)->post(route('arcs.store', $campaign), [
            'title' => 'The Hollow Crown',
        ])->assertRedirect();

        $arc = $campaign->arcs()->sole();
        $this->assertSame('The Hollow Crown', $arc->title);
        $this->assertSame(SessionStatus::ToPlay, $arc->status);
        $this->assertSame('the-hollow-crown', $arc->slug);
    }

    public function test_new_arcs_sort_after_existing_ones(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);

        $this->actingAs($gm)->post(route('arcs.store', $campaign), ['title' => 'First']);
        $this->actingAs($gm)->post(route('arcs.store', $campaign), ['title' => 'Second']);

        $sorts = $campaign->arcs()->orderBy('id')->pluck('sort')->all();
        $this->assertSame([1, 2], $sorts);
    }

    public function test_a_gm_can_set_an_arcs_status(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $arc = $campaign->arcs()->create(['title' => 'The Salt Road']);

        $this->actingAs($gm)->put(route('arcs.update', $arc), ['status' => 'playing'])
            ->assertRedirect();

        $this->assertSame(SessionStatus::Playing, $arc->refresh()->status);
    }

    public function test_deleting_an_arc_ungroups_its_sessions_rather_than_deleting_them(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $arc = $campaign->arcs()->create(['title' => 'The Salt Road']);
        $session = $campaign->sessions()->create(['title' => 'Smoke over Karr', 'arc_id' => $arc->id]);

        $this->actingAs($gm)->delete(route('arcs.destroy', $arc))->assertRedirect();

        $this->assertDatabaseMissing('campaign_arcs', ['id' => $arc->id]);
        $session->refresh();
        $this->assertNull($session->arc_id);
        $this->assertSame('Smoke over Karr', $session->title);
    }

    public function test_reordering_arcs_rewrites_their_sort(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $first = $campaign->arcs()->create(['title' => 'First', 'sort' => 0]);
        $second = $campaign->arcs()->create(['title' => 'Second', 'sort' => 1]);

        $this->actingAs($gm)->put(route('arcs.reorder', $campaign), [
            'ids' => [$second->id, $first->id],
        ])->assertRedirect();

        $this->assertSame(0, $second->refresh()->sort);
        $this->assertSame(1, $first->refresh()->sort);
    }

    public function test_organising_a_session_places_it_in_an_arc_and_sets_its_status(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $arc = $campaign->arcs()->create(['title' => 'The Hollow Crown']);
        $session = $campaign->sessions()->create(['title' => 'Beneath the Chapel']);

        $this->actingAs($gm)->patch(route('sessions.organise', $session), [
            'arc_id' => $arc->id,
            'status' => 'playing',
        ])->assertRedirect();

        $session->refresh();
        $this->assertSame($arc->id, $session->arc_id);
        $this->assertSame(SessionStatus::Playing, $session->status);
    }

    public function test_a_session_cannot_be_placed_in_another_campaigns_arc(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $otherCampaign = $campaign->world->campaigns()->create(['name' => 'Elsewhere']);
        $foreignArc = $otherCampaign->arcs()->create(['title' => 'Not yours']);
        $session = $campaign->sessions()->create(['title' => 'Beneath the Chapel']);

        $this->actingAs($gm)->patch(route('sessions.organise', $session), [
            'arc_id' => $foreignArc->id,
        ])->assertRedirect();

        $this->assertNull($session->refresh()->arc_id);
    }

    public function test_a_new_session_can_be_created_straight_into_an_arc(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $arc = $campaign->arcs()->create(['title' => 'The Hollow Crown']);

        $this->actingAs($gm)->post(route('sessions.store', $campaign), [
            'title' => 'The Gate Opens',
            'arc_id' => $arc->id,
        ])->assertRedirect();

        $session = $campaign->sessions()->sole();
        $this->assertSame($arc->id, $session->arc_id);
        $this->assertSame(SessionStatus::ToPlay, $session->status);
    }

    public function test_a_stranger_cannot_create_an_arc(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $campaign = $this->campaign($gm);

        $this->actingAs($stranger)->post(route('arcs.store', $campaign), ['title' => 'Sneaky'])
            ->assertForbidden();
    }

    public function test_the_campaign_page_exposes_arcs_and_session_status(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->campaign($gm);
        $arc = $campaign->arcs()->create(['title' => 'The Hollow Crown', 'status' => 'playing']);
        $campaign->sessions()->create(['title' => 'Beneath the Chapel', 'arc_id' => $arc->id, 'status' => 'playing']);

        $this->actingAs($gm)->get(route('campaigns.show', [$campaign->world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Show')
                ->has('arcs', 1)
                ->where('arcs.0.title', 'The Hollow Crown')
                ->where('arcs.0.status', 'playing')
                ->where('arcs.0.sessions_count', 1)
                ->where('sessions.0.status', 'playing')
                ->where('sessions.0.arc_id', $arc->id));
    }

    public function test_the_status_enum_reads_for_the_table(): void
    {
        $this->assertSame('To play', SessionStatus::ToPlay->label());
        $this->assertSame('orange', SessionStatus::Playing->colour());
        $this->assertSame('grey', SessionStatus::Played->colour());
    }
}
