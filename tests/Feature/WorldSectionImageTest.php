<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldSectionImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_set_a_section_door_image(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.section-image', $world->id), [
            'section' => 'locations',
            'file' => UploadedFile::fake()->image('door.jpg', 800, 300),
        ])->assertRedirect();

        $images = $world->refresh()->sectionImages();
        $this->assertArrayHasKey('locations', $images);
        $this->assertNotSame('', $images['locations']);
        $this->assertDatabaseHas('media', ['world_id' => $world->id, 'filename' => 'door.jpg']);
    }

    public function test_setting_one_section_image_leaves_the_others_unset(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.section-image', $world->id), [
            'section' => 'people',
            'file' => UploadedFile::fake()->image('people.jpg'),
        ])->assertRedirect();

        $images = $world->refresh()->sectionImages();
        $this->assertSame(['people'], array_keys($images));
    }

    public function test_the_reader_home_exposes_the_section_door_image(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        // A location entry makes the "locations" section appear on the home page.
        $world->documents()->create([
            'title' => 'The Docks', 'slug' => 'the-docks', 'kind' => 'location', 'is_private' => false, 'content' => '',
        ]);

        $this->actingAs($gm)->post(route('worlds.section-image', $world->id), [
            'section' => 'locations',
            'file' => UploadedFile::fake()->image('door.jpg'),
        ]);
        $expectedUrl = $world->refresh()->sectionImages()['locations'];

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sections', function ($sections) use ($expectedUrl): bool {
                    $locations = collect($sections)
                        ->map(fn ($section): array => (array) $section)
                        ->firstWhere('slug', 'locations');

                    return $locations !== null && ($locations['image'] ?? null) === $expectedUrl;
                }));
    }

    public function test_removing_a_section_image_clears_it(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $this->actingAs($gm)->post(route('worlds.section-image', $world->id), [
            'section' => 'locations',
            'file' => UploadedFile::fake()->image('door.jpg'),
        ]);

        $this->actingAs($gm)->delete(route('worlds.section-image.clear', $world->id), [
            'section' => 'locations',
        ])->assertRedirect();

        $this->assertArrayNotHasKey('locations', $world->refresh()->sectionImages());
    }

    public function test_an_unknown_section_slug_is_rejected(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.section-image', $world->id), [
            'section' => 'not-a-section',
            'file' => UploadedFile::fake()->image('door.jpg'),
        ])->assertSessionHasErrors('section');

        $this->assertSame([], $world->refresh()->sectionImages());
    }

    public function test_a_stranger_cannot_set_a_section_image(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('worlds.section-image', $world->id), [
            'section' => 'locations',
            'file' => UploadedFile::fake()->image('door.jpg'),
        ])->assertForbidden();

        $this->assertSame([], $world->refresh()->sectionImages());
    }
}
