<?php

namespace Tests\Feature;

use App\Models\GlobalAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EditorDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_opens_the_field_editor_with_its_fields(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $loc = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        $this->actingAs($gm)->get(route('documents.edit', $loc))->assertInertia(fn (Assert $p) => $p
            ->where('isFormKind', true)
            ->has('fields', 4)
            ->where('fields.0.key', 'type'));
    }

    public function test_admin_global_attributes_drive_the_editor_fields(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $loc = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        GlobalAttribute::create([
            'key' => 'climate', 'label' => 'Climate', 'type' => 'text',
            'placeholder' => 'Temperate', 'kinds' => ['location'], 'visible' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($gm)->get(route('documents.edit', $loc))->assertInertia(fn (Assert $p) => $p
            ->where('isFormKind', true)
            ->has('fields', 1)
            ->where('fields.0.key', 'climate')
            ->where('fields.0.placeholder', 'Temperate'));
    }

    public function test_reference_field_gets_linkable_entries_excluding_itself(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $loc = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);
        $npc = $campaign->documents()->create(['title' => 'Lady Merrow', 'slug' => 'merrow', 'kind' => 'npc']);

        GlobalAttribute::create([
            'key' => 'ruler_ref', 'label' => 'Ruled by', 'type' => 'reference',
            'kinds' => ['location'], 'ref_kinds' => ['npc'], 'visible' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($gm)->get(route('documents.edit', $loc))->assertInertia(fn (Assert $p) => $p
            ->where('fields.0.type', 'reference')
            ->where('fields.0.ref_kinds', ['npc'])
            ->has('entries', 1)
            ->where('entries.0.id', $npc->id));
    }

    public function test_session_opens_the_brew_editor(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $session = $campaign->documents()->create(['title' => 'S1', 'slug' => 's1', 'kind' => 'session']);

        $this->actingAs($gm)->get(route('documents.edit', $session))->assertInertia(fn (Assert $p) => $p
            ->where('isFormKind', false)
            ->has('fields', 0));
    }

    public function test_brew_editor_receives_embed_render_payloads(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $session = $campaign->documents()->create(['title' => 'S1', 'slug' => 's1', 'kind' => 'session']);
        $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'ogre', 'name' => 'Ogre', 'provider' => 'custom',
            'fields' => ['block' => ['ac' => '11', 'abilities' => ['str' => 19]]],
        ]);

        $this->actingAs($gm)->get(route('documents.edit', $session))->assertInertia(fn (Assert $p) => $p
            ->has('embeds', 1)
            ->where('embeds.0.name', 'Ogre')
            ->where('embeds.0.item_type', 'monster')
            ->where('embeds.0.block.ac', '11'));
    }

    public function test_updating_content_through_the_editor_syncs_wiki_links(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $article = $campaign->documents()->create(['title' => 'Overview', 'slug' => 'overview', 'kind' => 'article']);
        $docks = $campaign->documents()->create(['title' => 'The Docks', 'slug' => 'docks', 'kind' => 'location']);

        $this->actingAs($gm)
            ->put(route('documents.update', $article), ['content' => 'The party arrives at [[The Docks]].'])
            ->assertRedirect();

        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $article->id, 'to_document_id' => $docks->id, 'source' => 'wikilink',
        ]);
    }

    public function test_field_editor_receives_embed_render_payloads_so_the_preview_can_render_them(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        // A field-kind entry (location) — its section bodies may embed compendium cards ({{monster=id}}).
        $loc = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);
        $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'ogre', 'name' => 'Ogre', 'provider' => 'custom',
            'fields' => ['block' => ['ac' => '11', 'abilities' => ['str' => 19]]],
        ]);

        $this->actingAs($gm)->get(route('documents.edit', $loc))->assertInertia(fn (Assert $p) => $p
            ->where('isFormKind', true)
            ->has('embeds', 1)
            ->where('embeds.0.name', 'Ogre')
            ->where('embeds.0.item_type', 'monster')
            ->where('embeds.0.block.ac', '11'));
    }
}
