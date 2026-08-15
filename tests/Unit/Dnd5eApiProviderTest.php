<?php

namespace Tests\Unit;

use App\Services\Compendium\Dnd5eApiProvider;
use PHPUnit\Framework\TestCase;

class Dnd5eApiProviderTest extends TestCase
{
    public function test_it_maps_a_dnd5eapi_spell_to_structured_fields(): void
    {
        $fields = (new Dnd5eApiProvider)->toFields('spell', [
            'index' => 'fireball', 'name' => 'Fireball', 'level' => 3,
            'school' => ['name' => 'Evocation'],
            'casting_time' => '1 action', 'range' => '150 feet',
            'components' => ['V', 'S', 'M'], 'material' => 'a tiny ball of bat guano and sulfur',
            'duration' => 'Instantaneous',
            'desc' => ['A bright streak flashes.', 'It blossoms into flame.'],
            'higher_level' => ['The damage increases by 1d6 per slot above 3rd.'],
        ]);

        $this->assertSame('3rd', $fields['level']);
        $this->assertSame('Evocation', $fields['school']);
        $this->assertSame('V, S, M (a tiny ball of bat guano and sulfur)', $fields['components']);
        $this->assertSame("A bright streak flashes.\n\nIt blossoms into flame.", $fields['description']);
        $this->assertSame('The damage increases by 1d6 per slot above 3rd.', $fields['higher_levels']);
    }

    public function test_it_maps_a_dnd5eapi_monster_to_a_stat_block(): void
    {
        $block = (new Dnd5eApiProvider)->toBlock('monster', [
            'index' => 'goblin', 'name' => 'Goblin', 'size' => 'Small', 'type' => 'humanoid', 'alignment' => 'neutral evil',
            'armor_class' => [['value' => 15, 'type' => 'armor']],
            'hit_points' => 7, 'hit_dice' => '2d6',
            'speed' => ['walk' => '30 ft.'],
            'strength' => 8, 'dexterity' => 14, 'constitution' => 10, 'intelligence' => 10, 'wisdom' => 8, 'charisma' => 8,
            'proficiencies' => [
                ['value' => 6, 'proficiency' => ['name' => 'Skill: Stealth']],
                ['value' => 2, 'proficiency' => ['name' => 'Saving Throw: DEX']],
            ],
            'senses' => ['darkvision' => '60 ft.', 'passive_perception' => 9],
            'condition_immunities' => [['name' => 'Poisoned']],
            'languages' => 'Common, Goblin', 'challenge_rating' => 0.25,
            'special_abilities' => [['name' => 'Nimble Escape', 'desc' => 'It can take the Disengage action as a bonus action.']],
            'actions' => [['name' => 'Scimitar', 'desc' => 'Melee Weapon Attack: 1d6+2 slashing.']],
        ]);

        $this->assertSame('Small', $block['size']);
        $this->assertSame('15', $block['ac']);
        $this->assertSame('7 (2d6)', $block['hp']);
        $this->assertSame(14, $block['abilities']['dex']);
        $this->assertStringContainsString('Stealth +6', $block['skills']);
        $this->assertStringContainsString('DEX +2', $block['saves']);
        $this->assertStringContainsString('Poisoned', $block['conditionImmunities']);
        $this->assertSame('Nimble Escape', $block['traits'][0]['name']);
        $this->assertCount(1, $block['actions']);
    }
}
