<?php

namespace Tests\Unit;

use App\Services\Open5eClient;
use PHPUnit\Framework\TestCase;

class Open5eFieldsTest extends TestCase
{
    public function test_preview_handles_a_description_delivered_as_an_array(): void
    {
        // Some Open5e records send `desc` as an array of paragraphs — this used to crash the preview.
        $preview = Open5eClient::preview('condition', [
            'name' => 'Blinded',
            'desc' => ['A blinded creature cannot see.', 'It automatically fails checks that require sight.'],
        ]);

        $this->assertSame('Blinded', $preview['name']);
        $this->assertStringContainsString('cannot see', $preview['summary']);
    }

    public function test_preview_tolerates_array_valued_facets_without_throwing(): void
    {
        $preview = Open5eClient::preview('monster', [
            'name' => 'Aberrant Thing', 'size' => 'Large', 'type' => ['aberration'],
            'challenge_rating' => '5', 'desc' => ['x'],
        ]);

        $this->assertSame('Aberrant Thing', $preview['name']);
        $this->assertIsString($preview['meta']);
        $this->assertStringContainsString('CR 5', $preview['meta']);
    }

    public function test_it_maps_an_open5e_spell_record_to_structured_fields(): void
    {
        $fields = Open5eClient::toFields('spell', [
            'name' => 'Fireball',
            'level_int' => 3,
            'school' => 'evocation',
            'casting_time' => '1 action',
            'range' => '150 feet',
            'components' => 'V, S, M',
            'material' => 'a tiny ball of bat guano and sulfur',
            'duration' => 'Instantaneous',
            'desc' => 'A bright streak flashes.',
            'higher_level' => 'The damage increases by 1d6.',
        ]);

        $this->assertSame('3rd', $fields['level']);
        $this->assertSame('Evocation', $fields['school']);
        $this->assertSame('150 feet', $fields['range']);
        $this->assertSame('V, S, M (a tiny ball of bat guano and sulfur)', $fields['components']);
        $this->assertSame('A bright streak flashes.', $fields['description']);
        $this->assertSame('The damage increases by 1d6.', $fields['higher_levels']);
    }

    public function test_a_cantrip_maps_to_the_cantrip_level(): void
    {
        $fields = Open5eClient::toFields('spell', ['name' => 'Fire Bolt', 'level_int' => 0, 'school' => 'evocation']);

        $this->assertSame('Cantrip', $fields['level']);
    }

    public function test_it_maps_an_open5e_armor_record_into_the_equipment_type(): void
    {
        $fields = Open5eClient::toFields('equipment', [
            'name' => 'Plate',
            'category' => 'Heavy',
            'ac_string' => '18',
            'strength_requirement' => 15,
            'stealth_disadvantage' => true,
            'weight' => '65 lb.',
            'cost' => '1500 gp',
        ]);

        $this->assertSame('Heavy', $fields['category']);
        $this->assertSame('18', $fields['ac']);
        $this->assertSame('Str 15', $fields['strength']);
        $this->assertSame('Disadvantage', $fields['stealth']);
        // A weapon's damage keys stay absent for an armor record (empties are stripped).
        $this->assertArrayNotHasKey('damage', $fields);
    }

    public function test_it_maps_an_open5e_weapon_record_into_the_equipment_type(): void
    {
        $fields = Open5eClient::toFields('equipment', [
            'name' => 'Longsword',
            'category' => 'Martial Melee',
            'damage_dice' => '1d8',
            'damage_type' => 'slashing',
            'properties' => ['Versatile (1d10)'],
            'weight' => '3 lb.',
            'cost' => '15 gp',
        ]);

        $this->assertSame('Martial Melee', $fields['category']);
        $this->assertSame('1d8', $fields['damage']);
        $this->assertSame('slashing', $fields['damage_type']);
        $this->assertSame('Versatile (1d10)', $fields['properties']);
        $this->assertArrayNotHasKey('ac', $fields);
    }
}
