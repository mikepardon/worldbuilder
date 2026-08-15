<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultJoinRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_players_join_as_player_by_default(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);

        $player = User::factory()->create();
        $this->actingAs($player)->post(route('join.store', $campaign->code))->assertRedirect();

        $this->assertDatabaseHas('campaign_members', [
            'campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player',
        ]);
    }

    public function test_players_join_as_co_gm_when_the_world_default_says_so(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['default_join_role' => 'co-gm'],
        ]);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);

        $player = User::factory()->create();
        $this->actingAs($player)->post(route('join.store', $campaign->code))->assertRedirect();

        $this->assertDatabaseHas('campaign_members', [
            'campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'co-gm',
        ]);
    }
}
