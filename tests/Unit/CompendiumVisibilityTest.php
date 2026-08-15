<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\World;
use App\Support\Viewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompendiumVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function worldWithItems(): array
    {
        $owner = User::factory()->create();
        $campaign = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $campaign->compendiumItems()->create(['item_type' => 'spell', 'slug' => 'shown', 'name' => 'Public Spell', 'is_private' => false, 'is_active' => true]);
        $campaign->compendiumItems()->create(['item_type' => 'monster', 'slug' => 'secret', 'name' => 'Secret Boss', 'is_private' => true, 'is_active' => true]);
        $campaign->compendiumItems()->create(['item_type' => 'spell', 'slug' => 'draft', 'name' => 'Inactive Draft', 'is_private' => false, 'is_active' => false]);

        return [$owner, $campaign];
    }

    public function test_the_gm_sees_every_item(): void
    {
        [$owner, $campaign] = $this->worldWithItems();

        $items = $campaign->compendiumItems()->visibleTo(Viewer::for($campaign, $owner))->get();

        $this->assertCount(3, $items);
    }

    public function test_a_player_sees_only_public_active_items(): void
    {
        [, $campaign] = $this->worldWithItems();
        $player = User::factory()->create();

        $items = $campaign->compendiumItems()->visibleTo(Viewer::for($campaign, $player))->get();

        $this->assertCount(1, $items);
        $this->assertSame('Public Spell', $items->first()->name);
    }
}
