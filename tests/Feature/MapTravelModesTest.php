<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MapTravelModesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_world_without_configured_modes_falls_back_to_the_defaults(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);

        $this->assertSame(World::DEFAULT_TRAVEL_MODES, $world->travelModes());
        $this->assertSame('On foot', $world->travelModes()[0]['name']);
        $this->assertSame(40.0, $world->travelModes()[0]['per_day']);
    }

    public function test_blank_names_and_non_positive_speeds_are_dropped(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $world->settings = ['travel_modes' => [
            ['name' => 'By boat', 'per_day' => 60],
            ['name' => '', 'per_day' => 10],
            ['name' => 'Broken', 'per_day' => 0],
        ]];
        $world->save();

        $this->assertSame([['name' => 'By boat', 'per_day' => 60.0]], $world->travelModes());
    }

    public function test_a_gm_can_save_travel_modes(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'travel_modes' => [
                ['name' => 'On foot', 'per_day' => 32],
                ['name' => 'By airship', 'per_day' => 200],
            ],
        ])->assertRedirect();

        $world->refresh();
        $this->assertSame([
            ['name' => 'On foot', 'per_day' => 32.0],
            ['name' => 'By airship', 'per_day' => 200.0],
        ], $world->travelModes());
    }

    public function test_a_travel_mode_with_no_speed_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'travel_modes' => [
                ['name' => 'On foot', 'per_day' => 0],
            ],
        ])->assertSessionHasErrors('travel_modes.0.per_day');
    }

    public function test_the_reader_map_page_exposes_the_scale_and_travel_modes(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'title' => 'Aeria', 'slug' => 'aeria', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);
        $media = Media::create([
            'user_id' => $world->user_id, 'world_id' => $world->id,
            'disk' => 'public', 'path' => 'media/aeria.png', 'filename' => 'aeria.png',
            'mime' => 'image/png', 'size' => 1000,
        ]);
        $map = $world->maps()->create([
            'name' => 'Aeria', 'slug' => 'aeria', 'document_id' => $document->id, 'image_media_id' => $media->id,
            'real_width' => 1300, 'distance_unit' => 'miles', 'is_private' => false,
        ]);

        $this->get(route('public.map', [$world, $map]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Map')
                ->where('map.distance_unit', 'miles')
                ->where('map.real_width', fn ($width) => (int) $width === 1300)
                ->has('map.travel_modes', 3)
                ->where('map.travel_modes.0.name', 'On foot'));
    }
}
