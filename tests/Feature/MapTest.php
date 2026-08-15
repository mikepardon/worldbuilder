<?php

namespace Tests\Feature;

use App\Events\MapChanged;
use App\Models\User;
use App\Models\World;
use App\Support\Viewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function world(): array
    {
        $owner = User::factory()->create();
        $campaign = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        return [$owner, $campaign];
    }

    public function test_gm_can_create_a_map(): void
    {
        [$owner, $campaign] = $this->world();

        $this->actingAs($owner)
            ->post(route('maps.store', $campaign), ['name' => 'The Sundered Coast'])
            ->assertRedirect();

        // A location's map is player-visible by default; the GM can hide it from the Map tab.
        $this->assertDatabaseHas('maps', [
            'world_id' => $campaign->id, 'name' => 'The Sundered Coast', 'slug' => 'the-sundered-coast', 'is_private' => false,
        ]);
    }

    public function test_map_slugs_are_unique_within_a_world(): void
    {
        [$owner, $campaign] = $this->world();

        $this->actingAs($owner)->post(route('maps.store', $campaign), ['name' => 'Harbour']);
        $this->actingAs($owner)->post(route('maps.store', $campaign), ['name' => 'Harbour']);

        $this->assertEqualsCanonicalizing(['harbour', 'harbour-2'], $campaign->maps()->pluck('slug')->all());
    }

    public function test_gm_can_add_a_pin_linked_to_an_entry(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);
        $docks = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Docks', 'kind' => 'location']);

        $this->actingAs($owner)
            ->post(route('map-pins.store', $map), ['x' => 42.5, 'y' => 60, 'label' => 'Docks', 'document_id' => $docks->id])
            ->assertRedirect();

        $this->assertDatabaseHas('map_pins', ['map_id' => $map->id, 'document_id' => $docks->id, 'label' => 'Docks']);
    }

    public function test_a_background_drag_saves_the_new_position_and_answers_with_no_content(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);
        $pin = $map->pins()->create(['x' => 10, 'y' => 10, 'label' => 'Docks']);

        // A drag-to-move persists via a plain XHR (no X-Inertia header): the editor expects a bare 204
        // so it can leave the marker where it was dropped without re-rendering.
        $this->actingAs($owner)
            ->put(route('map-pins.update', $pin), ['x' => 73.5, 'y' => 21])
            ->assertNoContent();

        $pin->refresh();
        $this->assertSame(73.5, (float) $pin->x);
        $this->assertSame(21.0, (float) $pin->y);
    }

    public function test_an_inertia_pin_update_redirects_so_the_editor_panel_can_refresh(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);
        $pin = $map->pins()->create(['x' => 10, 'y' => 10, 'label' => 'Docks']);

        // The edit-panel save is an Inertia visit; it must get a redirect, not a 204.
        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->put(route('map-pins.update', $pin), ['x' => 40, 'y' => 40, 'label' => 'Renamed'])
            ->assertRedirect();

        $this->assertDatabaseHas('map_pins', ['id' => $pin->id, 'label' => 'Renamed']);
    }

    public function test_a_pin_cannot_link_to_an_entry_from_another_world(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);
        $otherWorldDoc = User::factory()->create()->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'public'])
            ->documents()->create(['title' => 'Foreign', 'kind' => 'location']);

        $this->actingAs($owner)
            ->post(route('map-pins.store', $map), ['x' => 10, 'y' => 10, 'document_id' => $otherWorldDoc->id])
            ->assertSessionHasErrors('document_id');
    }

    public function test_a_pin_position_must_be_within_the_image(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);

        $this->actingAs($owner)
            ->post(route('map-pins.store', $map), ['x' => 150, 'y' => 10])
            ->assertSessionHasErrors('x');
    }

    public function test_deleting_a_map_removes_its_pins(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);
        $map->pins()->create(['x' => 10, 'y' => 10, 'label' => 'X']);

        $this->actingAs($owner)->delete(route('maps.destroy', [$campaign, $map]))->assertRedirect();

        $this->assertDatabaseMissing('maps', ['id' => $map->id]);
        $this->assertDatabaseCount('map_pins', 0);
    }

    public function test_private_maps_are_hidden_from_players(): void
    {
        [$owner, $campaign] = $this->world();
        $campaign->maps()->create(['name' => 'Player Map', 'is_private' => false]);
        $campaign->maps()->create(['name' => 'GM Map', 'is_private' => true]);

        $gmView = $campaign->maps()->visibleTo(Viewer::for($campaign, $owner))->count();
        $playerView = $campaign->maps()->visibleTo(Viewer::for($campaign, User::factory()->create()))->count();

        $this->assertSame(2, $gmView);
        $this->assertSame(1, $playerView);
    }

    public function test_gm_can_configure_the_grid(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);

        $this->actingAs($owner)
            ->put(route('maps.update', [$campaign, $map]), ['grid_visible' => true, 'grid_size' => 30, 'unit_size' => 5, 'unit' => 'ft'])
            ->assertRedirect();

        $map->refresh();
        $this->assertTrue($map->grid_visible);
        $this->assertSame(30, $map->grid_size);
        $this->assertSame(5.0, $map->unit_size);
        $this->assertSame('ft', $map->unit);
    }

    public function test_gm_can_enable_fog_and_persist_revealed_cells(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);

        $this->actingAs($owner)
            ->put(route('maps.update', [$campaign, $map]), ['fog_enabled' => true, 'fog' => ['1,1', '2,3']])
            ->assertRedirect();

        $map->refresh();
        $this->assertTrue($map->fog_enabled);
        $this->assertSame(['1,1', '2,3'], $map->fog);
    }

    public function test_grid_size_is_bounded(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);

        $this->actingAs($owner)
            ->put(route('maps.update', [$campaign, $map]), ['grid_size' => 1])
            ->assertSessionHasErrors('grid_size');
    }

    public function test_changing_a_map_broadcasts_a_poke_on_its_channel(): void
    {
        Event::fake([MapChanged::class]);
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'Coast']);

        $this->actingAs($owner)->post(route('map-pins.store', $map), ['x' => 10, 'y' => 10])->assertRedirect();

        Event::assertDispatched(MapChanged::class, fn (MapChanged $event) => $event->mapId === $map->id);
    }

    public function test_the_map_changed_event_broadcasts_on_the_map_channel(): void
    {
        $event = new MapChanged(42);

        $this->assertSame('maps.42', $event->broadcastOn()->name);
        $this->assertSame('MapChanged', $event->broadcastAs());
    }

    public function test_a_map_can_be_tied_to_a_location(): void
    {
        [$owner, $campaign] = $this->world();
        $doc = $campaign->documents()->create(['title' => 'Phendor', 'kind' => 'location']);
        $map = $campaign->maps()->create(['name' => 'Phendor Map']);

        $this->actingAs($owner)->put(route('maps.update', [$campaign, $map]), ['document_id' => $doc->id])->assertRedirect();

        $this->assertSame($doc->id, $map->fresh()->document_id);
    }

    public function test_the_depicted_location_appears_in_the_reader_panel(): void
    {
        [, $campaign] = $this->world();
        $doc = $campaign->documents()->create(['title' => 'Glieda', 'kind' => 'location', 'is_private' => false, 'content' => 'A vast continent.']);
        $map = $campaign->maps()->create(['name' => 'Glieda Map', 'is_private' => false, 'document_id' => $doc->id]);

        $this->get(route('public.map', [$campaign, $map]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('map.location.title', 'Glieda')
                ->where('map.location.content', fn ($c) => str_contains($c, 'A vast continent.')));
    }

    public function test_a_travel_pin_links_to_the_target_locations_map(): void
    {
        [, $campaign] = $this->world();
        $city = $campaign->documents()->create(['title' => 'Phendor', 'kind' => 'location', 'is_private' => false]);
        $cityMap = $campaign->maps()->create(['name' => 'Phendor Map', 'is_private' => false, 'document_id' => $city->id]);
        $world = $campaign->maps()->create(['name' => 'World', 'is_private' => false]);
        $world->pins()->create(['x' => 10, 'y' => 10, 'behavior' => 'travel', 'document_id' => $city->id, 'label' => 'Phendor']);

        $this->get(route('public.map', [$campaign, $world]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('map.pins.0.action', 'travel')
                ->where('map.pins.0.href', fn ($href) => str_contains($href, "/maps/{$cityMap->slug}")));
    }

    public function test_an_info_pin_exposes_popup_data_rather_than_a_link(): void
    {
        [, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'World', 'is_private' => false]);
        $map->pins()->create(['x' => 5, 'y' => 5, 'behavior' => 'info', 'label' => 'A ruin', 'note' => 'Rumoured haunted.']);

        $this->get(route('public.map', [$campaign, $map]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('map.pins.0.action', 'info')
                ->where('map.pins.0.href', null)
                ->where('map.pins.0.popup.summary', 'Rumoured haunted.'));
    }

    public function test_a_non_owner_cannot_manage_maps(): void
    {
        [, $campaign] = $this->world();

        $this->actingAs(User::factory()->create())
            ->post(route('maps.store', $campaign), ['name' => 'Sneaky'])
            ->assertForbidden();
    }

    public function test_the_reader_lists_only_visible_maps(): void
    {
        [$owner, $campaign] = $this->world();
        $campaign->maps()->create(['name' => 'Player Map', 'is_private' => false]);
        $campaign->maps()->create(['name' => 'GM Map', 'is_private' => true]);

        $this->get(route('public.maps', $campaign))
            ->assertInertia(fn (Assert $page) => $page->component('Public/Maps')->has('maps', 1));
        $this->actingAs($owner)->get(route('public.maps', $campaign))
            ->assertInertia(fn (Assert $page) => $page->has('maps', 2));
    }

    public function test_a_private_map_is_hidden_from_players(): void
    {
        [$owner, $campaign] = $this->world();
        $map = $campaign->maps()->create(['name' => 'GM Only', 'is_private' => true]);

        $this->get(route('public.map', [$campaign, $map]))->assertNotFound();
        $this->actingAs($owner)->get(route('public.map', [$campaign, $map]))->assertOk();
    }

    public function test_pins_that_link_to_gm_only_entries_are_hidden_from_players(): void
    {
        [$owner, $campaign] = $this->world();
        $public = $campaign->documents()->create(['title' => 'The Docks', 'kind' => 'location', 'is_private' => false]);
        $secret = $campaign->documents()->create(['title' => 'Hidden Lair', 'kind' => 'location', 'is_private' => true]);
        $map = $campaign->maps()->create(['name' => 'Coast', 'is_private' => false]);
        $map->pins()->create(['x' => 10, 'y' => 10, 'label' => 'Docks', 'document_id' => $public->id]);
        $map->pins()->create(['x' => 20, 'y' => 20, 'label' => 'Lair', 'document_id' => $secret->id]);
        $map->pins()->create(['x' => 30, 'y' => 30, 'label' => 'Just a marker']);

        // Player: public-linked pin + label-only pin; the secret-linked pin is gone.
        $this->get(route('public.map', [$campaign, $map]))
            ->assertInertia(fn (Assert $page) => $page->component('Public/Map')->has('map.pins', 2));
        // GM: all three.
        $this->actingAs($owner)->get(route('public.map', [$campaign, $map]))
            ->assertInertia(fn (Assert $page) => $page->has('map.pins', 3));
    }

    public function test_a_locations_map_is_delivered_to_its_editor(): void
    {
        [$owner, $campaign] = $this->world();
        $location = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'Phendor', 'slug' => 'phendor', 'kind' => 'location']);
        $map = $campaign->maps()->create(['name' => 'Phendor', 'document_id' => $location->id]);
        $map->pins()->create(['x' => 20, 'y' => 30, 'label' => 'Gate']);

        $this->actingAs($owner)
            ->get(route('documents.edit', $location))
            ->assertInertia(fn (Assert $page) => $page
                ->where('map.id', $map->id)
                ->has('map.pins', 1));
    }
}
