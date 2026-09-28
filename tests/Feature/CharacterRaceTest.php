<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignCompendiumItem;
use App\Models\User;
use App\Services\CharacterTotals;
use App\Support\DemoRuleSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CharacterRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_races_base_modifiers_land_in_the_sheet_before_talents(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->stats()->create(['key' => 'str', 'label' => 'Strength', 'default_value' => 3]);
        $system->stats()->create(['key' => 'dex', 'label' => 'Dexterity', 'default_value' => 3]);
        $race = CampaignCompendiumItem::create([
            'world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'race', 'slug' => 'elf', 'name' => 'Elf',
            'fields' => ['stat_modifiers' => [['type' => 'stat', 'key' => 'dex', 'delta' => 2]]],
            'is_private' => false, 'is_active' => true,
        ]);
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        $character = $campaign->characters()->create(['name' => 'Aria', 'user_id' => $gm->id, 'level' => 1, 'race_compendium_item_id' => $race->id]);
        $build = $character->builds()->create(['rule_system_id' => $system->id, 'manual_points' => 0]);

        $sheet = app(CharacterTotals::class)->sheet($build->fresh());

        $this->assertSame(5, $sheet['stats']['dex'], 'the race adds +2 dexterity to the base 3');
        $this->assertSame(3, $sheet['stats']['str'], 'an unmodified stat keeps its default');
    }

    public function test_the_build_page_exposes_the_race_and_its_granted_spells(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->stats()->create(['key' => 'dex', 'label' => 'Dexterity', 'default_value' => 3]);
        $spell = CampaignCompendiumItem::create([
            'world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'firebolt', 'name' => 'Firebolt',
            'fields' => ['level' => 'Cantrip'], 'is_private' => false, 'is_active' => true,
        ]);
        $race = CampaignCompendiumItem::create([
            'world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'race', 'slug' => 'elf', 'name' => 'Elf',
            'fields' => ['stat_modifiers' => [['type' => 'stat', 'key' => 'dex', 'delta' => 2]], 'grants' => [$spell->id]],
            'is_private' => false, 'is_active' => true,
        ]);
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        $character = $campaign->characters()->create(['name' => 'Aria', 'user_id' => $gm->id, 'level' => 1, 'race_compendium_item_id' => $race->id]);

        $this->actingAs($gm)->get(route('character-build.show', [$world, $campaign, $character]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Characters/Build')
                ->where('race.name', 'Elf')
                ->where('race.grants.0.name', 'Firebolt')
                ->where('sheet.stats.dex', 5));
    }

    public function test_a_character_can_be_assigned_a_race_from_its_world(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $race = CampaignCompendiumItem::create([
            'world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'race', 'slug' => 'dwarf', 'name' => 'Dwarf',
            'is_private' => false, 'is_active' => true,
        ]);
        $campaign = $world->campaigns()->create(['name' => 'Play']);
        $character = $campaign->characters()->create(['name' => 'Bront', 'user_id' => $gm->id, 'level' => 1]);

        $this->actingAs($gm)->put(route('characters.update', $character->id), [
            'name' => 'Bront', 'race_compendium_item_id' => $race->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($race->id, $character->refresh()->race_compendium_item_id);
    }

    public function test_a_character_cannot_be_assigned_a_race_from_another_world(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $elsewhere = $gm->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'private']);
        $foreignRace = CampaignCompendiumItem::create([
            'world_id' => $elsewhere->id, 'user_id' => $gm->id, 'item_type' => 'race', 'slug' => 'orc', 'name' => 'Orc',
            'is_private' => false, 'is_active' => true,
        ]);
        $campaign = $world->campaigns()->create(['name' => 'Play']);
        $character = $campaign->characters()->create(['name' => 'Bront', 'user_id' => $gm->id, 'level' => 1]);

        $this->actingAs($gm)->put(route('characters.update', $character->id), [
            'name' => 'Bront', 'race_compendium_item_id' => $foreignRace->id,
        ])->assertSessionHasErrors('race_compendium_item_id');

        $this->assertNull($character->refresh()->race_compendium_item_id);
    }
}
