<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_sees_the_campaigns_they_play_in_with_recaps(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'The Salt Accord', 'visibility' => 'public']);
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $session = $campaign->sessions()->create(['title' => 'Session 1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_stylized' => 'The tide rolled in.',
        ]);

        $this->actingAs($player)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('playerCampaigns', 1)
                ->where('playerCampaigns.0.name', 'The Salt Accord')
                ->where('playerCampaigns.0.world', 'W')
                ->has('playerCampaigns.0.recaps', 1)
                ->where('playerCampaigns.0.recaps.0.title', 'Session 1'));
    }

    public function test_the_gm_does_not_see_their_own_world_as_a_played_campaign(): void
    {
        $gm = User::factory()->create();
        $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('playerCampaigns', 0));
    }

    public function test_an_unpublished_recap_is_not_listed_for_the_player(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['recap_auto_publish' => false],
        ]);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $session = $campaign->sessions()->create(['title' => 'Session 1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done', 'published_at' => null,
            'recap_stylized' => 'x',
        ]);

        $this->actingAs($player)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('playerCampaigns', 1)
                ->has('playerCampaigns.0.recaps', 0));
    }
}
