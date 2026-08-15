<?php

namespace Tests\Feature;

use App\Models\DocumentLink;
use App\Models\GlobalAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReaderTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $doc = $campaign->documents()->create([
            'title' => 'Docks', 'slug' => 'docks', 'kind' => 'location', 'is_private' => false,
            'content' => 'Open text. {{secret}}hidden truth{{/}} End.',
        ]);

        return [$gm, $campaign, $doc];
    }

    public function test_owner_sees_secrets_players_do_not(): void
    {
        [$gm, $campaign, $doc] = $this->world();
        $url = route('public.article', [$campaign, 'location', $doc->slug]);

        // Anonymous player: secret stripped. (Guest request first — actingAs persists for the test.)
        $this->get($url)->assertInertia(fn (Assert $p) => $p
            ->where('gmSecrets', false)
            ->where('entry.content', fn ($c) => ! str_contains($c, 'hidden truth')));

        // Owner (GM view): raw content with the secret, gmSecrets flagged.
        $this->actingAs($gm)->get($url)->assertInertia(fn (Assert $p) => $p
            ->where('gmSecrets', true)
            ->where('entry.content', fn ($c) => str_contains($c, 'hidden truth')));

        // Owner previewing as player: also stripped.
        $this->actingAs($gm)->get(route('public.article', [$campaign, 'location', $doc->slug, 'as' => 'player']))->assertInertia(fn (Assert $p) => $p
            ->where('entry.content', fn ($c) => ! str_contains($c, 'hidden truth')));
    }

    public function test_players_never_receive_an_unclosed_secret_through_the_reader(): void
    {
        [$gm, $campaign] = $this->world();
        // A GM typo: the secret is never closed. The bytes must still not reach a player.
        $doc = $campaign->documents()->create([
            'title' => 'Vault', 'slug' => 'vault', 'kind' => 'location', 'is_private' => false,
            'content' => 'The door is here. {{secret}}the code is 4242 and the dragon sleeps',
        ]);
        $url = route('public.article', [$campaign, 'location', $doc->slug]);

        $this->get($url)->assertInertia(fn (Assert $p) => $p
            ->where('entry.content', fn ($c) => ! str_contains($c, '4242') && ! str_contains($c, 'dragon'))
            ->where('entry.content', fn ($c) => str_contains($c, 'The door is here.')));

        $this->actingAs($gm)->get($url)->assertInertia(fn (Assert $p) => $p
            ->where('entry.content', fn ($c) => str_contains($c, '4242')));
    }

    public function test_owner_can_view_a_private_entry_but_players_cannot(): void
    {
        [$gm, $campaign] = $this->world();
        $secretDoc = $campaign->documents()->create(['title' => 'GM', 'slug' => 'gm', 'kind' => 'lore', 'is_private' => true, 'content' => 'x']);
        $url = route('public.article', [$campaign, 'lore', $secretDoc->slug]);

        // Guest first (actingAs persists for the rest of the test).
        $this->get($url)->assertNotFound();
        $this->actingAs($gm)->get($url)->assertOk();
    }

    public function test_gm_can_reveal_a_secret_block(): void
    {
        [$gm, , $doc] = $this->world();

        $this->actingAs($gm)->post(route('documents.reveal', $doc), ['index' => 0])->assertRedirect();

        $content = $doc->fresh()->content;
        $this->assertStringContainsString('hidden truth', $content);
        $this->assertStringNotContainsString('{{secret}}', $content);
    }

    public function test_a_reader_can_add_share_and_delete_a_note(): void
    {
        [, , $doc] = $this->world();
        $reader = User::factory()->create();

        $this->actingAs($reader)->post(route('notes.store', $doc), ['body' => 'She never blinked.'])->assertRedirect();
        $note = $doc->notes()->first();
        $this->assertSame($reader->id, $note->user_id);
        $this->assertFalse($note->shared);

        $this->actingAs($reader)->patch(route('notes.share', $note))->assertRedirect();
        $this->assertTrue($note->fresh()->shared);

        $this->actingAs($reader)->delete(route('notes.destroy', $note))->assertRedirect();
        $this->assertNull($note->fresh());
    }

    public function test_field_kind_entry_exposes_quick_facts_and_omits_empty_fields(): void
    {
        [, $campaign, $doc] = $this->world();
        // Only population and ruler are set; type and region are left blank.
        $doc->update(['data' => ['population' => '2,000', 'ruler' => 'Lady Merrow']]);

        $this->get(route('public.article', [$campaign, 'location', $doc->slug]))
            ->assertInertia(fn (Assert $p) => $p->where('facts', [
                ['key' => 'population', 'label' => 'Population', 'value' => '2,000', 'link' => null, 'items' => [['value' => '2,000', 'link' => null]]],
                ['key' => 'ruler', 'label' => 'Ruled by', 'value' => 'Lady Merrow', 'link' => null, 'items' => [['value' => 'Lady Merrow', 'link' => null]]],
            ]));
    }

    public function test_a_reference_fact_resolves_to_the_target_entry_title_and_link(): void
    {
        [, $campaign, $doc] = $this->world();
        GlobalAttribute::create([
            'key' => 'ruler', 'label' => 'Ruled by', 'type' => 'reference',
            'kinds' => ['location'], 'ref_kinds' => ['npc'], 'visible' => true, 'sort_order' => 1,
        ]);
        $ruler = $campaign->documents()->create([
            'title' => 'Lady Merrow', 'slug' => 'lady-merrow', 'kind' => 'npc', 'is_private' => false, 'content' => '',
        ]);
        $doc->update(['data' => ['ruler' => $ruler->id]]);

        $this->get(route('public.article', [$campaign, 'location', $doc->slug]))
            ->assertInertia(fn (Assert $p) => $p->where('facts', [
                ['key' => 'ruler', 'label' => 'Ruled by', 'value' => 'Lady Merrow', 'link' => ['type' => 'person', 'slug' => 'lady-merrow'], 'items' => [['value' => 'Lady Merrow', 'link' => ['type' => 'person', 'slug' => 'lady-merrow']]]],
            ]));
    }

    public function test_a_reference_fact_to_a_private_entry_is_hidden_from_players(): void
    {
        [$gm, $campaign, $doc] = $this->world();
        GlobalAttribute::create([
            'key' => 'ruler', 'label' => 'Ruled by', 'type' => 'reference',
            'kinds' => ['location'], 'ref_kinds' => ['npc'], 'visible' => true, 'sort_order' => 1,
        ]);
        $ruler = $campaign->documents()->create([
            'title' => 'Lady Merrow', 'slug' => 'lady-merrow', 'kind' => 'npc', 'is_private' => true, 'content' => '',
        ]);
        $doc->update(['data' => ['ruler' => $ruler->id]]);
        $url = route('public.article', [$campaign, 'location', $doc->slug]);

        // Player: the private referent is not visible, so the fact is dropped entirely (no title leak).
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where('facts', []));

        // GM: sees the resolved reference.
        $this->actingAs($gm)->get($url)->assertInertia(fn (Assert $p) => $p->where('facts', [
            ['key' => 'ruler', 'label' => 'Ruled by', 'value' => 'Lady Merrow', 'link' => ['type' => 'person', 'slug' => 'lady-merrow'], 'items' => [['value' => 'Lady Merrow', 'link' => ['type' => 'person', 'slug' => 'lady-merrow']]]],
        ]));
    }

    public function test_the_reader_web_shows_only_entries_the_viewer_may_see(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $alpha = $campaign->documents()->create(['title' => 'Alpha', 'slug' => 'alpha', 'kind' => 'location', 'is_private' => false, 'content' => '']);
        $campaign->documents()->create(['title' => 'Beta', 'slug' => 'beta', 'kind' => 'npc', 'is_private' => false, 'content' => '']);
        $secret = $campaign->documents()->create(['title' => 'Secret', 'slug' => 'secret', 'kind' => 'location', 'is_private' => true, 'content' => '']);

        // A hand-added link from a public entry to a private one.
        DocumentLink::create([
            'world_id' => $campaign->id, 'from_document_id' => $alpha->id,
            'to_document_id' => $secret->id, 'relationship' => 'related_to', 'source' => 'manual',
        ]);

        // Guest: the private entry and the edge that touches it are absent.
        $this->get(route('public.web', $campaign))->assertInertia(fn (Assert $p) => $p
            ->component('Public/Web')
            ->where('graph.nodes', fn ($nodes) => collect($nodes)->pluck('title')->contains('Alpha')
                && collect($nodes)->pluck('title')->contains('Beta')
                && ! collect($nodes)->pluck('title')->contains('Secret'))
            ->where('graph.edges', fn ($edges) => count($edges) === 0));

        // GM: sees the private entry and its edge.
        $this->actingAs($gm)->get(route('public.web', $campaign))->assertInertia(fn (Assert $p) => $p
            ->where('graph.nodes', fn ($nodes) => collect($nodes)->pluck('title')->contains('Secret'))
            ->where('graph.edges', fn ($edges) => count($edges) === 1));
    }

    public function test_reader_nodes_carry_a_reader_url_type_and_slug(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign->documents()->create(['title' => 'The Harbourmaster', 'slug' => 'harbourmaster', 'kind' => 'npc', 'is_private' => false, 'content' => '']);
        $campaign->documents()->create(['title' => 'The Docks', 'slug' => 'docks', 'kind' => 'location', 'is_private' => false, 'content' => '']);

        $this->get(route('public.web', $campaign))->assertInertia(fn (Assert $p) => $p
            ->where('graph.nodes', fn ($nodes) => collect($nodes)->contains(fn ($n) => $n['title'] === 'The Harbourmaster'
                && $n['type'] === 'person' && $n['slug'] === 'harbourmaster')));
    }

    public function test_a_timeline_page_renders_its_age_events_with_scoped_links(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $merrow = $world->documents()->create(['title' => 'Lady Merrow', 'slug' => 'lady-merrow', 'kind' => 'npc', 'is_private' => false, 'content' => '']);
        $secret = $world->documents()->create(['title' => 'The Cabal', 'slug' => 'the-cabal', 'kind' => 'faction', 'is_private' => true, 'content' => '']);
        $world->documents()->create([
            'title' => 'The Sundering', 'slug' => 'the-sundering', 'kind' => 'timeline', 'is_private' => false, 'content' => '',
            'data' => ['span' => '1204 DR', 'events' => [
                ['when' => '1204 DR', 'title' => 'The coast fractures', 'detail' => '', 'link' => $merrow->id],
                ['when' => '1205 DR', 'title' => 'A whisper in the dark', 'detail' => '', 'link' => $secret->id],
            ]],
        ]);

        // Guest: the Age's events render; the public link resolves, the private one is scrubbed.
        $this->get(route('public.article', [$world, 'timeline', 'the-sundering']))->assertInertia(fn (Assert $p) => $p
            ->component('Public/Article')
            ->where('timeline.title', 'The Sundering')
            ->where('timeline.span', '1204 DR')
            ->where('timeline.events.0.link.slug', 'lady-merrow')
            ->where('timeline.events.1.link', null));
    }

    public function test_a_bloodline_page_renders_its_members_with_scoped_links(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $alya = $world->documents()->create(['title' => 'Alya', 'slug' => 'alya', 'kind' => 'npc', 'is_private' => false, 'content' => '']);
        $secret = $world->documents()->create(['title' => 'Hidden Heir', 'slug' => 'hidden-heir', 'kind' => 'npc', 'is_private' => true, 'content' => '']);
        $world->documents()->create([
            'title' => 'House Alduin', 'slug' => 'house-alduin', 'kind' => 'bloodline', 'is_private' => false, 'content' => '',
            'data' => ['members' => [
                ['id' => 'a', 'name' => 'Zhikuvar', 'parents' => []],
                ['id' => 'b', 'name' => 'Alya', 'link' => $alya->id, 'parents' => ['a']],
                ['id' => 'c', 'name' => 'Hidden Heir', 'link' => $secret->id, 'parents' => ['a']],
            ]],
        ]);

        $this->get(route('public.article', [$world, 'bloodline', 'house-alduin']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Article')
                ->has('bloodline', 3)
                ->where('bloodline.1.link.slug', 'alya')
                ->where('bloodline.2.link', null));
    }

    public function test_an_entry_page_flags_whether_it_has_connections(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $baron = $world->documents()->create(['title' => 'The Baron', 'slug' => 'the-baron', 'kind' => 'npc', 'is_private' => false, 'content' => '']);
        $keep = $world->documents()->create(['title' => 'Iron Keep', 'slug' => 'iron-keep', 'kind' => 'location', 'is_private' => false, 'content' => '']);
        $world->documents()->create(['title' => 'Lonely Isle', 'slug' => 'lonely-isle', 'kind' => 'location', 'is_private' => false, 'content' => '']);
        DocumentLink::create([
            'world_id' => $world->id, 'from_document_id' => $keep->id,
            'to_document_id' => $baron->id, 'relationship' => 'related_to', 'source' => 'manual',
        ]);

        $this->get(route('public.article', [$world, 'location', 'iron-keep']))
            ->assertInertia(fn (Assert $p) => $p->where('entry.hasConnections', true));

        $this->get(route('public.article', [$world, 'location', 'lonely-isle']))
            ->assertInertia(fn (Assert $p) => $p->where('entry.hasConnections', false));
    }

    public function test_a_reader_cannot_touch_another_readers_note(): void
    {
        [, , $doc] = $this->world();
        $owner = User::factory()->create();
        $note = $doc->notes()->create(['user_id' => $owner->id, 'body' => 'mine', 'shared' => false]);
        $other = User::factory()->create();

        $this->actingAs($other)->patch(route('notes.share', $note))->assertForbidden();
        $this->actingAs($other)->delete(route('notes.destroy', $note))->assertForbidden();
    }

    /** @return list<string> */
    private function searchTitles(array $groups): array
    {
        return collect($groups)->flatMap(fn (array $g) => array_column($g['items'], 'title'))->all();
    }

    public function test_reader_search_returns_a_matching_public_entry_with_its_reader_url(): void
    {
        [, $campaign] = $this->world();
        $campaign->documents()->create([
            'title' => 'The Salt Harbour', 'slug' => 'salt-harbour', 'kind' => 'location', 'is_private' => false,
        ]);

        $groups = $this->getJson(route('public.search', $campaign).'?q=harbour')->assertOk()->json('groups');

        $this->assertContains('The Salt Harbour', $this->searchTitles($groups));
        $item = collect($groups)->flatMap(fn (array $g) => $g['items'])->firstWhere('title', 'The Salt Harbour');
        $this->assertSame(route('public.article', [$campaign->slug, 'location', 'salt-harbour']), $item['url']);
    }

    public function test_reader_search_ignores_a_query_shorter_than_two_characters(): void
    {
        [, $campaign] = $this->world();

        $this->getJson(route('public.search', $campaign).'?q=a')->assertOk()->assertExactJson(['groups' => []]);
    }

    public function test_reader_search_hides_private_entries_from_players_but_shows_the_gm(): void
    {
        [$gm, $campaign] = $this->world();
        $campaign->documents()->create([
            'title' => 'Hidden Bastion', 'slug' => 'hidden-bastion', 'kind' => 'location', 'is_private' => true,
        ]);

        // Guest first — actingAs persists across a test's requests.
        $guest = $this->getJson(route('public.search', $campaign).'?q=bastion')->assertOk()->json('groups');
        $this->assertNotContains('Hidden Bastion', $this->searchTitles($guest));

        $asGm = $this->actingAs($gm)->getJson(route('public.search', $campaign).'?q=bastion')->assertOk()->json('groups');
        $this->assertContains('Hidden Bastion', $this->searchTitles($asGm));
    }

    public function test_reader_search_hides_a_scheduled_entry_until_its_publish_time(): void
    {
        [, $campaign] = $this->world();
        $campaign->documents()->create([
            'title' => 'Future Feast', 'slug' => 'future-feast', 'kind' => 'article', 'is_private' => false,
            'publish_at' => now()->addWeek(),
        ]);

        $groups = $this->getJson(route('public.search', $campaign).'?q=feast')->assertOk()->json('groups');
        $this->assertNotContains('Future Feast', $this->searchTitles($groups));
    }

    public function test_reader_search_omits_an_entry_flagged_hidden_from_search(): void
    {
        [, $campaign] = $this->world();
        $campaign->documents()->create([
            'title' => 'Unlisted Grotto', 'slug' => 'unlisted-grotto', 'kind' => 'location', 'is_private' => false,
            'hide_from_search' => true,
        ]);

        $groups = $this->getJson(route('public.search', $campaign).'?q=grotto')->assertOk()->json('groups');
        $this->assertNotContains('Unlisted Grotto', $this->searchTitles($groups));
    }
}
