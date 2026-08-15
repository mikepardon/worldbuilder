<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_set_a_maps_distance_scale(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'title' => 'Aeria', 'slug' => 'aeria', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);
        $map = $world->maps()->create(['name' => 'Aeria', 'document_id' => $document->id]);

        $this->actingAs($gm)->put(route('maps.update', [$world, $map]), [
            'real_width' => 1300,
            'distance_unit' => 'miles',
        ])->assertRedirect();

        $map->refresh();
        $this->assertSame(1300.0, $map->real_width);
        $this->assertSame('miles', $map->distance_unit);
    }

    public function test_an_invalid_distance_unit_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'M']);

        $this->actingAs($gm)->put(route('maps.update', [$world, $map]), [
            'real_width' => 500,
            'distance_unit' => 'lightyears',
        ])->assertSessionHasErrors('distance_unit');
    }

    public function test_a_stranger_cannot_change_a_maps_scale(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'M']);

        $this->actingAs($stranger)->put(route('maps.update', [$world, $map]), [
            'real_width' => 500, 'distance_unit' => 'miles',
        ])->assertForbidden();
    }
}
