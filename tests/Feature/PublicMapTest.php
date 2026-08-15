<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Models\World;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicMapTest extends TestCase
{
    use RefreshDatabase;

    private function locationWithMap(World $world, array $mapOverrides = []): string
    {
        $document = $world->documents()->create([
            'title' => 'Aeria', 'slug' => 'aeria', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);
        $media = Media::create([
            'user_id' => $world->user_id, 'world_id' => $world->id,
            'disk' => 'public', 'path' => 'media/aeria.png', 'filename' => 'aeria.png',
            'mime' => 'image/png', 'size' => 1000,
        ]);
        $world->maps()->create(array_merge([
            'name' => 'Aeria', 'document_id' => $document->id, 'image_media_id' => $media->id,
            'real_width' => 1300, 'distance_unit' => 'miles', 'is_private' => false,
        ], $mapOverrides));

        return route('public.article', [$world, Sections::typeSlug('location'), 'aeria']);
    }

    public function test_a_location_map_is_exposed_to_the_reader_with_its_scale(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $url = $this->locationWithMap($world);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Article')
                ->where('map.distance_unit', 'miles')
                ->where('map.real_width', fn ($width) => (int) $width === 1300)
                ->has('map.image_url'));
    }

    public function test_a_private_map_is_hidden_from_the_public(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $url = $this->locationWithMap($world, ['is_private' => true]);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('map', null));
    }

    public function test_a_location_without_a_map_has_no_map_payload(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'Plainsville', 'slug' => 'plainsville', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'plainsville']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('map', null));
    }
}
