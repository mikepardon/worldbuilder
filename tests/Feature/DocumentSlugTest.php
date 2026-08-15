<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_rename_an_entrys_url_slug(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Keep', 'kind' => 'location',
            'slug' => 'the-keep', 'content' => '', 'is_private' => false,
        ]);

        $this->actingAs($gm)
            ->put(route('documents.slug', $document), ['slug' => 'Iron Keep!!'])
            ->assertRedirect();

        // The submitted value is normalised to a clean slug before saving.
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'slug' => 'iron-keep']);

        // The reader resolves the entry at its new URL; the old slug redirects to it (kept as an alias).
        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'iron-keep']))->assertOk();
        $this->get(route('public.article', [$world, $type, 'the-keep']))
            ->assertRedirect(url("/w/{$world->slug}/{$type}/iron-keep"));
    }

    public function test_renaming_to_a_slug_already_used_by_the_same_kind_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Taken', 'kind' => 'location', 'slug' => 'taken', 'content' => '',
        ]);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'the-keep', 'content' => '',
        ]);

        $this->actingAs($gm)
            ->put(route('documents.slug', $document), ['slug' => 'taken'])
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'slug' => 'the-keep']);
    }

    public function test_the_same_slug_is_allowed_across_different_kinds(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Ash', 'kind' => 'character', 'slug' => 'ash', 'content' => '',
        ]);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Ash Vale', 'kind' => 'location', 'slug' => 'ash-vale', 'content' => '',
        ]);

        $this->actingAs($gm)
            ->put(route('documents.slug', $document), ['slug' => 'ash'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'slug' => 'ash']);
    }

    public function test_a_stranger_cannot_rename_an_entrys_slug(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'the-keep', 'content' => '',
        ]);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put(route('documents.slug', $document), ['slug' => 'stolen'])
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'slug' => 'the-keep']);
    }
}
