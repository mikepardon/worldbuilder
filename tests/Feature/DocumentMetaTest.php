<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_set_the_meta_fields(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $a = $world->documents()->create(['user_id' => $gm->id, 'title' => 'A', 'kind' => 'location', 'slug' => 'a']);
        $b = $world->documents()->create(['user_id' => $gm->id, 'title' => 'B', 'kind' => 'location', 'slug' => 'b']);

        $this->actingAs($gm)->put(route('documents.update', $a), [
            'accent' => 'crimson', 'comments_enabled' => false, 'show_toc' => true, 'related_ids' => [$b->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'id' => $a->id, 'accent' => 'crimson', 'comments_enabled' => false, 'show_toc' => true,
        ]);
        $this->assertSame([$b->id], $a->refresh()->related_ids);
    }

    public function test_a_scheduled_entry_is_hidden_from_players_until_its_time(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Reveal', 'kind' => 'location', 'slug' => 'reveal',
            'is_private' => false, 'publish_at' => now()->addWeek(),
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'reveal']))->assertNotFound();
        $this->actingAs($gm)->get(route('public.article', [$world, $type, 'reveal']))->assertOk();
    }

    public function test_an_old_slug_redirects_to_the_new_one(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Keep', 'kind' => 'location', 'slug' => 'old-keep', 'is_private' => false,
        ]);

        $this->actingAs($gm)->put(route('documents.slug', $entry), ['slug' => 'new-keep'])->assertRedirect();

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'old-keep']))
            ->assertRedirect(url("/w/{$world->slug}/{$type}/new-keep"));
    }

    public function test_related_entries_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $related = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Neighbour', 'kind' => 'location', 'slug' => 'neighbour', 'is_private' => false]);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Main', 'kind' => 'location', 'slug' => 'main',
            'is_private' => false, 'related_ids' => [$related->id],
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'main']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('related', 1)
                ->where('related.0.title', 'Neighbour'));
    }
}
