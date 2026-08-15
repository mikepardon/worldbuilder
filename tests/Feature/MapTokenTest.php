<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_add_a_token_pin_linked_to_an_entry(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'The Tavern']);
        $npc = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Barkeep', 'kind' => 'npc', 'slug' => 'the-barkeep',
        ]);

        $this->actingAs($gm)->post(route('map-pins.store', $map), [
            'x' => 50, 'y' => 50, 'style' => 'token', 'document_id' => $npc->id, 'label' => 'Barkeep',
        ])->assertRedirect();

        $this->assertDatabaseHas('map_pins', [
            'map_id' => $map->id, 'style' => 'token', 'document_id' => $npc->id, 'label' => 'Barkeep',
        ]);
    }

    public function test_a_token_can_portray_a_compendium_monster(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'The Cave']);
        $monster = $world->compendiumItems()->create(['name' => 'Goblin', 'slug' => 'goblin', 'item_type' => 'monster']);

        $this->actingAs($gm)->post(route('map-pins.store', $map), [
            'x' => 30, 'y' => 30, 'style' => 'token', 'compendium_item_id' => $monster->id, 'label' => 'Goblin',
        ])->assertRedirect();

        $this->assertDatabaseHas('map_pins', [
            'map_id' => $map->id, 'style' => 'token', 'compendium_item_id' => $monster->id,
        ]);
    }

    public function test_a_compendium_monster_from_another_world_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'Cave']);
        $otherMonster = $gm->worlds()->create(['name' => 'Other', 'visibility' => 'public'])
            ->compendiumItems()->create(['name' => 'Orc', 'slug' => 'orc', 'item_type' => 'monster']);

        $this->actingAs($gm)->post(route('map-pins.store', $map), [
            'x' => 10, 'y' => 10, 'style' => 'token', 'compendium_item_id' => $otherMonster->id,
        ])->assertSessionHasErrors('compendium_item_id');
    }

    public function test_pins_default_to_the_marker_style(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'Coast']);

        $this->actingAs($gm)->post(route('map-pins.store', $map), ['x' => 10, 'y' => 10, 'label' => 'X'])
            ->assertRedirect();

        $this->assertDatabaseHas('map_pins', ['map_id' => $map->id, 'style' => 'marker']);
    }

    public function test_an_unknown_pin_style_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'Coast']);

        $this->actingAs($gm)->post(route('map-pins.store', $map), [
            'x' => 10, 'y' => 10, 'style' => 'sprite',
        ])->assertSessionHasErrors('style');
    }
}
