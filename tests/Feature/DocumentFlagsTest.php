<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_set_the_entry_flags(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Keep', 'kind' => 'location', 'slug' => 'the-keep',
        ]);

        $this->actingAs($gm)->put(route('documents.update', $document), [
            'is_featured' => true, 'hide_from_search' => true, 'cover_mode' => 'hide',
        ])->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id, 'is_featured' => true, 'hide_from_search' => true, 'cover_mode' => 'hide',
        ]);
    }

    public function test_featured_entries_appear_on_the_world_home(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Pinned', 'kind' => 'location', 'slug' => 'pinned',
            'is_private' => false, 'is_featured' => true,
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Ordinary', 'kind' => 'location', 'slug' => 'ordinary',
            'is_private' => false, 'is_featured' => false,
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->has('featured', 1)
                ->where('featured.0.title', 'Pinned'));
    }

    public function test_the_entry_noindex_and_cover_mode_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Secretish', 'kind' => 'location', 'slug' => 'secretish',
            'is_private' => false, 'hide_from_search' => true, 'cover_mode' => 'show',
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'secretish']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.noindex', true)
                ->where('entry.cover_mode', 'show'));
    }
}
