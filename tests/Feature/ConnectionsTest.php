<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function gmWithCampaign(): array
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);

        return [$gm, $campaign];
    }

    private function doc(World $campaign, User $gm, string $title, string $kind = 'article', string $content = ''): Document
    {
        return $campaign->documents()->create([
            'user_id' => $gm->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'kind' => $kind,
            'content' => $content,
            'is_private' => false,
        ]);
    }

    public function test_a_wiki_link_in_the_content_creates_a_connection_on_save(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $article = $this->doc($campaign, $gm, 'Saltmere');

        $this->actingAs($gm)
            ->put(route('documents.update', $article), ['content' => 'The town is ruled by [[The Baron]].'])
            ->assertRedirect();

        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $article->id,
            'to_document_id' => $baron->id,
            'source' => 'wikilink',
            'label' => null,
        ]);
    }

    public function test_editing_the_content_replaces_stale_wiki_links(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $harbour = $this->doc($campaign, $gm, 'The Harbour');
        $article = $this->doc($campaign, $gm, 'Saltmere');

        $this->actingAs($gm)->put(route('documents.update', $article), ['content' => 'See [[The Baron]].']);
        $this->actingAs($gm)->put(route('documents.update', $article), ['content' => 'Now see [[The Harbour]].']);

        $this->assertDatabaseMissing('document_links', ['from_document_id' => $article->id, 'to_document_id' => $baron->id]);
        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $article->id,
            'to_document_id' => $harbour->id,
            'source' => 'wikilink',
        ]);
    }

    public function test_a_manual_connection_can_be_added_with_a_label_and_removed(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $baron = $this->doc($campaign, $gm, 'The Baron');

        $this->actingAs($gm)
            ->post(route('documents.links.store', $article), ['to_document_id' => $baron->id, 'label' => 'ruled by'])
            ->assertRedirect();

        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $article->id,
            'to_document_id' => $baron->id,
            'source' => 'manual',
            'label' => 'ruled by',
        ]);

        $link = DocumentLink::where('from_document_id', $article->id)->where('source', 'manual')->sole();
        $this->actingAs($gm)->delete(route('documents.links.destroy', $link))->assertRedirect();
        $this->assertDatabaseMissing('document_links', ['id' => $link->id]);
    }

    public function test_a_manual_connection_cannot_target_an_entry_in_another_world(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        [$otherGm, $otherCampaign] = $this->gmWithCampaign();
        $foreign = $this->doc($otherCampaign, $otherGm, 'Elsewhere');

        $this->actingAs($gm)
            ->from(route('documents.edit', $article))
            ->post(route('documents.links.store', $article), ['to_document_id' => $foreign->id])
            ->assertSessionHasErrors('to_document_id');

        $this->assertDatabaseCount('document_links', 0);
    }

    public function test_a_manual_connection_cannot_point_at_itself(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');

        $this->actingAs($gm)
            ->from(route('documents.edit', $article))
            ->post(route('documents.links.store', $article), ['to_document_id' => $article->id])
            ->assertSessionHasErrors('to_document_id');
    }

    public function test_a_wiki_link_cannot_be_deleted_through_the_manual_link_endpoint(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $this->doc($campaign, $gm, 'The Baron');
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $this->actingAs($gm)->put(route('documents.update', $article), ['content' => '[[The Baron]]']);

        $link = DocumentLink::where('source', 'wikilink')->sole();
        $this->actingAs($gm)->delete(route('documents.links.destroy', $link))->assertForbidden();
        $this->assertDatabaseHas('document_links', ['id' => $link->id]);
    }

    public function test_the_web_page_renders_every_entry_and_its_connections(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $this->actingAs($gm)->post(route('documents.links.store', $article), ['to_document_id' => $baron->id, 'label' => 'ruled by']);

        $this->actingAs($gm)
            ->get(route('worlds.web', $campaign))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Worlds/Web')
                ->has('graph.nodes', 2)
                ->has('graph.edges', 1)
                ->where('graph.edges.0.from', $article->id)
                ->where('graph.edges.0.to', $baron->id)
                ->where('graph.edges.0.label', 'ruled by')
            );
    }

    public function test_the_article_editor_receives_its_connections_and_backlinks(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $this->actingAs($gm)->post(route('documents.links.store', $article), ['to_document_id' => $baron->id, 'label' => 'ruled by']);

        $this->actingAs($gm)
            ->get(route('documents.edit', $article))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isArticleKind', true)
                ->has('links', 1)
                ->where('links.0.title', 'The Baron')
                ->where('links.0.label', 'ruled by')
                ->has('graph.nodes', 2)
            );

        // The target sees the backlink.
        $this->actingAs($gm)
            ->get(route('documents.edit', $baron))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('backlinks', 1)
                ->where('backlinks.0.title', 'Saltmere')
            );
    }

    public function test_deleting_an_entry_cascades_its_connections(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $this->actingAs($gm)->post(route('documents.links.store', $article), ['to_document_id' => $baron->id]);
        $this->assertDatabaseCount('document_links', 1);

        $this->actingAs($gm)->delete(route('documents.destroy', $baron))->assertRedirect();
        $this->assertDatabaseCount('document_links', 0);
    }

    public function test_the_public_article_exposes_wiki_targets_only_for_visible_entries(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $article = $this->doc($campaign, $gm, 'Saltmere', 'article', 'See [[The Baron]] and [[Secret Cove]].');
        $this->doc($campaign, $gm, 'The Baron');
        $cove = $this->doc($campaign, $gm, 'Secret Cove');
        $cove->update(['is_private' => true]);

        // Player: only public entries are linkable targets (the GM-only cove is excluded).
        $this->get(route('public.article', [$campaign, 'article', $article->slug]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('wikiTargets', 2)
            ->where('wikiTargets', fn ($targets) => collect($targets)->pluck('title')->contains('The Baron')
                && ! collect($targets)->pluck('title')->contains('Secret Cove')));

        // GM view: the private entry is a valid target too.
        $this->actingAs($gm)->get(route('public.article', [$campaign, 'article', $article->slug]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('wikiTargets', 3)
            ->where('wikiTargets', fn ($targets) => collect($targets)->pluck('title')->contains('Secret Cove')));
    }

    public function test_a_non_owner_cannot_add_a_connection(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('documents.links.store', $article), ['to_document_id' => $baron->id])
            ->assertForbidden();

        $this->assertDatabaseCount('document_links', 0);
    }

    public function test_a_typed_relationship_reads_in_both_directions_on_the_graph(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $city = $this->doc($campaign, $gm, 'Saltmere');
        $district = $this->doc($campaign, $gm, 'The Deepmarket');

        $this->actingAs($gm)->post(route('documents.links.store', $district), [
            'to_document_id' => $city->id,
            'relationship' => 'located_in',
        ]);

        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $district->id,
            'to_document_id' => $city->id,
            'relationship' => 'located_in',
            'source' => 'manual',
        ]);

        $this->actingAs($gm)->get(route('worlds.web', $campaign))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('graph.edges.0.relationship', 'located_in')
            ->where('graph.edges.0.label', 'Located in')
            ->where('graph.edges.0.inverseLabel', 'Contains'));
    }

    public function test_a_wiki_link_is_a_mentions_relationship(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $baron = $this->doc($campaign, $gm, 'The Baron');
        $article = $this->doc($campaign, $gm, 'Saltmere');

        $this->actingAs($gm)->put(route('documents.update', $article), ['content' => 'See [[The Baron]].']);

        $this->assertDatabaseHas('document_links', [
            'to_document_id' => $baron->id,
            'relationship' => 'mentions',
            'source' => 'wikilink',
        ]);

        $this->actingAs($gm)->get(route('worlds.web', $campaign))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('graph.edges.0.label', 'Mentions')
            ->where('graph.edges.0.inverseLabel', 'Mentioned by'));
    }

    public function test_re_adding_a_connection_updates_its_relationship(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');
        $baron = $this->doc($campaign, $gm, 'The Baron');

        $this->actingAs($gm)->post(route('documents.links.store', $article), ['to_document_id' => $baron->id, 'relationship' => 'located_in']);
        $this->actingAs($gm)->post(route('documents.links.store', $article), ['to_document_id' => $baron->id, 'relationship' => 'member_of']);

        $this->assertDatabaseCount('document_links', 1);
        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $article->id,
            'to_document_id' => $baron->id,
            'relationship' => 'member_of',
        ]);
    }

    public function test_a_reference_field_becomes_a_connection_reading_both_ways(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $campaign->customFields()->create([
            'key' => 'owner', 'label' => 'Owner', 'type' => 'reference', 'kinds' => ['location'],
            'ref_kinds' => ['npc'], 'link_label' => 'owned by', 'inverse_label' => 'owner of',
        ]);

        $baron = $this->doc($campaign, $gm, 'The Baron', 'npc');
        $keep = $this->doc($campaign, $gm, 'Iron Keep', 'location');
        $keep->update(['data' => ['owner' => $baron->id]]);

        $this->actingAs($gm)->get(route('worlds.web', $campaign))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('graph.edges', 1)
            ->where('graph.edges.0.from', $keep->id)
            ->where('graph.edges.0.to', $baron->id)
            ->where('graph.edges.0.relationship', 'reference')
            ->where('graph.edges.0.label', 'owned by')
            ->where('graph.edges.0.inverseLabel', 'owner of'));
    }

    public function test_a_reference_field_without_phrases_falls_back_to_the_field_name(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $campaign->customFields()->create([
            'key' => 'patron', 'label' => 'Patron', 'type' => 'reference',
            'kinds' => ['npc'], 'ref_kinds' => ['npc'],
        ]);

        $client = $this->doc($campaign, $gm, 'The Fixer', 'npc');
        $patron = $this->doc($campaign, $gm, 'The Baron', 'npc');
        $client->update(['data' => ['patron' => $patron->id]]);

        $this->actingAs($gm)->get(route('worlds.web', $campaign))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('graph.edges.0.label', 'Patron')
            ->where('graph.edges.0.inverseLabel', 'Patron'));
    }

    public function test_a_multiple_reference_field_connects_to_every_target(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $campaign->customFields()->create([
            'key' => 'staff', 'label' => 'Staff', 'type' => 'reference', 'multiple' => true,
            'kinds' => ['location'], 'ref_kinds' => ['npc'], 'inverse_label' => 'works at',
        ]);

        $alice = $this->doc($campaign, $gm, 'Alice', 'npc');
        $bob = $this->doc($campaign, $gm, 'Bob', 'npc');
        $tavern = $this->doc($campaign, $gm, 'The Tavern', 'location');
        $tavern->update(['data' => ['staff' => [$alice->id, $bob->id]]]);

        $this->actingAs($gm)->get(route('worlds.web', $campaign))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('graph.edges', 2));
    }

    public function test_the_editor_receives_relationship_options(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $article = $this->doc($campaign, $gm, 'Saltmere');

        $this->actingAs($gm)->get(route('documents.edit', $article))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('relationshipOptions')
            ->where('relationshipOptions.0.key', 'related_to'));
    }
}
