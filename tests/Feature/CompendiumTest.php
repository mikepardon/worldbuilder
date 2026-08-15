<?php

namespace Tests\Feature;

use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\User;
use App\Support\Compendium;
use App\Support\Statblock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CompendiumTest extends TestCase
{
    use RefreshDatabase;

    private function gmWithCampaign(): array
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);

        return [$gm, $campaign];
    }

    public function test_creating_a_monster_seeds_an_empty_stat_block(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();

        $this->actingAs($gm)
            ->post(route('compendium.store', $campaign), ['name' => 'Kobold', 'item_type' => 'monster'])
            ->assertRedirect();

        $item = $campaign->compendiumItems()->first();
        $this->assertSame('monster', $item->item_type);
        $this->assertArrayHasKey('block', $item->fields);
        $this->assertStringContainsString('# Kobold', $item->document);
    }

    public function test_updating_the_block_regenerates_the_markdown(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'ogre', 'name' => 'Ogre',
            'fields' => ['block' => Statblock::empty()], 'provider' => 'custom',
        ]);

        $block = Statblock::empty();
        $block['ac'] = '11 (hide armor)';

        $this->actingAs($gm)
            ->put(route('compendium.update', $item), ['name' => 'Ogre', 'block' => $block])
            ->assertRedirect();

        $this->assertStringContainsString('**Armor Class** 11 (hide armor)', $item->fresh()->document);
    }

    public function test_monster_spellcasting_renders_into_the_stat_block_markdown(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'mage', 'name' => 'Mage',
            'fields' => ['block' => Statblock::empty()], 'provider' => 'custom',
        ]);

        $block = Statblock::empty();
        $block['spellcasting'] = [
            'intro' => 'The mage is a 9th-level spellcaster.',
            'groups' => [
                ['label' => 'Cantrips (at will)', 'spells' => [['name' => 'fire bolt'], ['name' => 'light']]],
                ['label' => '1st level (4 slots)', 'spells' => [['name' => 'magic missile', 'id' => 12]]],
            ],
        ];

        $this->actingAs($gm)
            ->put(route('compendium.update', $item), ['name' => 'Mage', 'block' => $block])
            ->assertRedirect();

        $doc = $item->fresh()->document;
        $this->assertStringContainsString('***Spellcasting.*** The mage is a 9th-level spellcaster.', $doc);
        $this->assertStringContainsString('**Cantrips (at will):** *fire bolt*, *light*', $doc);
        $this->assertStringContainsString('*magic missile*', $doc);
    }

    public function test_updating_a_spells_fields_regenerates_its_document(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fireball', 'name' => 'Fireball', 'provider' => 'custom',
        ]);

        $this->actingAs($gm)
            ->put(route('compendium.update', $item), [
                'name' => 'Fireball',
                'fields' => ['level' => '3rd', 'school' => 'Evocation', 'range' => '150 feet', 'description' => 'A bright streak flashes.'],
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('3rd', $fresh->fields['level']);
        $this->assertStringContainsString('#### Fireball', $fresh->document);
        $this->assertStringContainsString('**Level** 3rd', $fresh->document);
        $this->assertStringContainsString('**Range** 150 feet', $fresh->document);
        $this->assertStringContainsString('A bright streak flashes.', $fresh->document);
    }

    public function test_rebuild_regenerates_from_open5e_source(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin', 'provider' => 'imported',
            'data' => ['name' => 'Goblin', 'strength' => 8, 'dexterity' => 14, 'constitution' => 10,
                'special_abilities' => [['name' => 'Nimble Escape', 'desc' => 'Bonus-action Hide.']]],
        ]);

        $this->actingAs($gm)->post(route('compendium.rebuild', $item))->assertRedirect();

        $fresh = $item->fresh();
        $this->assertNotNull($fresh->fields['block']);
        $this->assertStringContainsString('Nimble Escape', $fresh->document);
    }

    public function test_browse_lists_the_global_library_and_flags_existing_items(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        // Already copied into this world.
        $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin', 'provider' => 'imported',
        ]);
        // Global, admin-curated library.
        CompendiumItem::create(['item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin', 'data' => ['challenge_rating' => '1/4']]);
        CompendiumItem::create(['item_type' => 'monster', 'slug' => 'orc', 'name' => 'Orc', 'data' => ['challenge_rating' => '1/2']]);

        $response = $this->actingAs($gm)->getJson(route('compendium.browse', $campaign).'?item_type=monster');

        $response->assertOk();
        $results = collect($response->json('results'));
        $this->assertTrue($results->firstWhere('slug', 'goblin')['exists']);
        $this->assertFalse($results->firstWhere('slug', 'orc')['exists']);
    }

    public function test_import_copies_selected_global_items_into_the_world(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $goblin = CompendiumItem::create(['item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin']);
        CompendiumItem::create(['item_type' => 'monster', 'slug' => 'orc', 'name' => 'Orc']);

        $this->actingAs($gm)->post(route('compendium.import', $campaign), ['ids' => [$goblin->id]])
            ->assertRedirect();

        $items = $campaign->compendiumItems()->get();
        $this->assertCount(1, $items);
        $this->assertSame('goblin', $items->first()->slug);
        $this->assertSame('imported', $items->first()->provider);
        $this->assertSame($goblin->id, (int) $items->first()->source_item_id);
    }

    public function test_imported_items_record_their_source_of_origin(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $source = CompendiumSource::create([
            'key' => 'open5e-monsters', 'name' => 'Open5e — Monsters', 'provider' => 'open5e',
            'item_type' => 'monster', 'api_url' => 'https://api.open5e.com/monsters/', 'enabled' => true,
        ]);
        $goblin = CompendiumItem::create(['source_id' => $source->id, 'item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin']);

        $this->actingAs($gm)->post(route('compendium.import', $campaign), ['ids' => [$goblin->id]])->assertRedirect();

        $item = $campaign->compendiumItems()->first();
        $this->assertSame('open5e', $item->origin);
        $this->assertSame('Open5e', Compendium::sourceLabel($item->origin, $item->provider));
    }

    public function test_imported_items_are_read_only_but_can_be_toggled_active(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fireball', 'name' => 'Fireball', 'provider' => 'imported', 'is_active' => true,
        ]);

        $this->actingAs($gm)->put(route('compendium.update', $item), [
            'name' => 'Hacked', 'is_active' => false, 'tags' => ['evocation'],
        ])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('Fireball', $fresh->name);      // content edits ignored
        $this->assertFalse($fresh->is_active);            // active toggle honoured
        $this->assertSame(['evocation'], $fresh->tags);   // tags honoured
    }

    public function test_clone_makes_an_editable_custom_copy(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fireball', 'name' => 'Fireball', 'provider' => 'imported',
            'document' => '# Fireball',
        ]);

        $this->actingAs($gm)->post(route('compendium.clone', $item))->assertRedirect();

        $this->assertSame(2, $campaign->compendiumItems()->count());
        $copy = $campaign->compendiumItems()->where('provider', 'custom')->first();
        $this->assertNotNull($copy);
        $this->assertStringContainsString('Fireball', $copy->name);
    }

    public function test_every_world_page_ships_the_shared_sidebar_nav(): void
    {
        [$gm, $campaign] = $this->gmWithCampaign();
        $firstCampaign = $campaign->campaigns()->first();

        $urls = [
            route('worlds.show', $campaign),
            route('compendium.index', $campaign),
            route('players.index', $firstCampaign),
            route('media.index', $campaign),
        ];

        foreach ($urls as $url) {
            $this->actingAs($gm)->get($url)->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('world.id', $campaign->id)
                    ->where('world.name', 'World')
                    ->has('world.sections')
            );
        }
    }

    public function test_non_owner_cannot_edit_compendium_item(): void
    {
        [, $campaign] = $this->gmWithCampaign();
        $other = User::factory()->create();
        $item = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'x', 'name' => 'X', 'provider' => 'custom',
        ]);

        $this->actingAs($other)->get(route('compendium.edit', $item))->assertForbidden();
    }
}
