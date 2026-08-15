<?php

namespace Tests\Unit;

use App\Support\Ddb;
use PHPUnit\Framework\TestCase;

class DdbMapperTest extends TestCase
{
    public function test_it_maps_a_ddb_monster_record_to_a_stat_block(): void
    {
        $lookups = [
            'sizes' => [4 => 'Large'],
            'monsterTypes' => [7 => 'Giant'],
            'alignments' => [10 => 'chaotic evil'],
            'challengeRatings' => [5 => '5'],
            'movements' => [1 => 'Walk', 4 => 'Fly'],
            'senses' => [2 => 'Darkvision'],
            'conditions' => [9 => 'Frightened'],
        ];
        $raw = [
            'name' => 'Ogre Mage',
            'sizeId' => 4, 'typeId' => 7, 'alignmentId' => 10,
            'armorClass' => 16, 'armorClassDescription' => '(natural armor)',
            'averageHitPoints' => 110, 'hitPointDice' => ['diceCount' => 13, 'diceValue' => 10, 'fixedValue' => 39],
            'movements' => [['movementId' => 1, 'speed' => 30], ['movementId' => 4, 'speed' => 60]],
            'stats' => [
                ['statId' => 1, 'value' => 19], ['statId' => 2, 'value' => 14], ['statId' => 3, 'value' => 17],
                ['statId' => 4, 'value' => 14], ['statId' => 5, 'value' => 12], ['statId' => 6, 'value' => 12],
            ],
            'challengeRatingId' => 5,
            'passivePerception' => 11,
            'senses' => [['senseId' => 2, 'notes' => '60 ft.']],
            'conditionImmunities' => [['conditionId' => 9]],
            'languageNote' => 'Common, Giant',
            'specialTraitsDescription' => '<p><strong>Regeneration.</strong> The ogre regains 10 hit points at the start of its turn.</p>',
            'actionsDescription' => '<p><strong>Multiattack.</strong> The ogre makes two attacks.</p><p><strong>Claw.</strong> Melee Weapon Attack: 2d6+4 slashing.</p>',
        ];

        $block = Ddb::toBlock($raw, $lookups);

        $this->assertSame('Large', $block['size']);
        $this->assertSame('Giant', $block['type']);
        $this->assertSame('chaotic evil', $block['alignment']);
        $this->assertSame('16 (natural armor)', $block['ac']);
        $this->assertSame('110 (13d10 + 39)', $block['hp']);
        $this->assertStringContainsString('30 ft.', $block['speed']);
        $this->assertStringContainsString('fly 60 ft.', $block['speed']);
        $this->assertSame(19, $block['abilities']['str']);
        $this->assertSame(12, $block['abilities']['cha']);
        $this->assertSame('5', $block['cr']);
        $this->assertStringContainsString('Darkvision 60 ft.', $block['senses']);
        $this->assertStringContainsString('passive Perception 11', $block['senses']);
        $this->assertStringContainsString('Frightened', $block['conditionImmunities']);
        $this->assertSame('Regeneration', $block['traits'][0]['name']);
        $this->assertCount(2, $block['actions']);
        $this->assertSame('Claw', $block['actions'][1]['name']);
        $this->assertStringContainsString('2d6+4 slashing', $block['actions'][1]['desc']);
    }

    public function test_it_computes_a_character_profile_with_ac_speed_and_passive_perception(): void
    {
        $raw = [
            'name' => 'Zelen', 'decorations' => ['avatarUrl' => ''],
            'baseHitPoints' => 30, 'removedHitPoints' => 0,
            'classes' => [['level' => 7, 'definition' => ['name' => 'Wizard']]],
            'race' => ['fullName' => 'Human', 'weightSpeeds' => ['normal' => ['walk' => 30]]],
            // Base 8 DEX + a racial +2 modifier and a WIS proficiency in Perception.
            'stats' => [['id' => 2, 'value' => 8], ['id' => 5, 'value' => 14]],
            'modifiers' => [
                'race' => [['type' => 'bonus', 'subType' => 'dexterity-score', 'value' => 2]],
                'class' => [['type' => 'proficiency', 'subType' => 'perception']],
            ],
            // Equipped leather (light) armour, base AC 11.
            'inventory' => [
                ['equipped' => true, 'definition' => ['armorClass' => 11, 'armorTypeId' => 1]],
            ],
        ];

        $profile = Ddb::characterProfile($raw, '139264657');

        $this->assertSame(7, $profile['level']);
        $this->assertSame('Wizard', $profile['class']);
        $this->assertSame('Human', $profile['race']);
        $this->assertSame(30, $profile['speed']);
        $this->assertSame(10, $profile['stats']['dex'], 'base 8 + racial 2');
        // Light armour 11 + Dex mod (+0 at DEX 10).
        $this->assertSame(11, $profile['ac']);
        // 10 + WIS mod (+2) + proficiency (+3 at level 7).
        $this->assertSame(15, $profile['passive_perception']);
    }

    public function test_it_extracts_a_full_character_sheet(): void
    {
        $raw = [
            'classes' => [[
                'level' => 5,
                'definition' => ['name' => 'Rogue'],
                'classFeatures' => [['definition' => ['name' => 'Sneak Attack', 'snippet' => 'Extra <b>damage</b>.']]],
            ]],
            'stats' => [['id' => 2, 'value' => 16], ['id' => 5, 'value' => 14]], // DEX 16, WIS 14
            'race' => ['racialTraits' => [['definition' => ['name' => 'Darkvision']]]],
            'feats' => [['definition' => ['name' => 'Lucky']]],
            'modifiers' => [
                'class' => [
                    ['type' => 'proficiency', 'subType' => 'stealth'],
                    ['type' => 'proficiency', 'subType' => 'perception'],
                    ['type' => 'expertise', 'subType' => 'perception'],
                    ['type' => 'proficiency', 'subType' => 'dexterity-saving-throws'],
                    ['type' => 'language', 'subType' => 'thieves-cant'],
                ],
                'race' => [['type' => 'resistance', 'subType' => 'poison']],
            ],
            'inventory' => [['equipped' => true, 'quantity' => 1, 'definition' => ['name' => 'Rapier', 'filterType' => 'Weapon']]],
            'classSpells' => [['spells' => [['definition' => ['name' => 'Minor Illusion', 'level' => 0]]]]],
        ];

        $sheet = Ddb::characterSheet($raw);

        $this->assertSame(3, $sheet['proficiency']);            // level 5 → +3
        $this->assertSame(3, $sheet['initiative']);             // DEX 16 → +3
        $this->assertSame('Passive Perception 18', $sheet['senses']); // 10 + 2 + 3 (prof) + 3 (expertise)

        $stealth = collect($sheet['skills'])->firstWhere('label', 'Stealth');
        $this->assertSame(6, $stealth['mod']);                  // +3 DEX + 3 prof
        $this->assertTrue($stealth['proficient']);

        $perception = collect($sheet['skills'])->firstWhere('label', 'Perception');
        $this->assertSame(8, $perception['mod']);               // +2 WIS + 6 (prof + expertise)

        $this->assertTrue(collect($sheet['saves'])->firstWhere('ability', 'DEX')['proficient']);
        $this->assertContains('Poison', $sheet['defenses']['resistances']);
        $this->assertContains('Thieves Cant', $sheet['languages']);
        $this->assertSame('Sneak Attack', $sheet['features'][0]['name']);
        $this->assertSame('Rapier', $sheet['inventory'][0]['name']);
        $this->assertSame('Minor Illusion', $sheet['spells'][0]['name']);
    }

    public function test_it_computes_hp_with_constitution_and_ac_with_mage_armor(): void
    {
        $raw = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/ddb-character-zelen.json'), true)['data'];

        $profile = Ddb::characterProfile($raw, '139264657');

        // HP: base 30 + CON mod (+1) × 7 levels = 37 (matches D&D Beyond).
        $this->assertSame(37, $profile['max_hp']);
        // AC: Mage Armor base 13 + Dex (+0) + a +1 item bonus = 14 (matches D&D Beyond).
        $this->assertSame(14, $profile['ac']);
    }

    public function test_it_extracts_a_real_ddb_character_sheet_in_depth(): void
    {
        $raw = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/ddb-character-zelen.json'), true)['data'];

        $sheet = Ddb::characterSheet($raw);

        // Level 7 wizard → +3 proficiency; INT caster (17 → +3): attack +6, DC 14.
        $this->assertSame(3, $sheet['proficiency']);
        $this->assertSame('INT', $sheet['spellcasting']['ability']);
        $this->assertSame(6, $sheet['spellcasting']['attack']);
        $this->assertSame(14, $sheet['spellcasting']['dc']);

        // Spell slots for a level-7 wizard: 4 / 3 / 3 / 1, with recorded usage preserved.
        $slots = collect($sheet['spell_slots'])->keyBy('level');
        $this->assertSame(4, $slots[1]['max']);
        $this->assertSame(3, $slots[3]['max']);
        $this->assertSame(2, $slots[3]['used']);
        $this->assertSame(1, $slots[4]['max']);

        // A cantrip carries its save ability, damage and rules text.
        $mindSliver = collect($sheet['spells'])->firstWhere('name', 'Mind Sliver');
        $this->assertSame(0, $mindSliver['level']);
        $this->assertSame('INT', $mindSliver['save']);
        $this->assertSame('1d6 psychic', $mindSliver['damage']);
        $this->assertStringContainsString('psychic energy', $mindSliver['description']);

        $this->assertNotNull(collect($sheet['spells'])->firstWhere('name', 'Magic Missile'), 'the prepared spell list is extracted');

        // A weapon attack: Dagger (finesse) uses the better of STR/DEX (+0) plus proficiency (+3).
        $dagger = collect($sheet['actions'])->firstWhere('name', 'Dagger');
        $this->assertSame(3, $dagger['to_hit']);
        $this->assertSame('1d4', $dagger['damage']);
        $this->assertSame('Piercing', $dagger['damage_type']);

        // Features keep their full rules text and their source.
        $illusionSavant = collect($sheet['features'])->firstWhere('name', 'Illusion Savant');
        $this->assertSame('Wizard', $illusionSavant['source']);
        $this->assertStringContainsString('Illusion', $illusionSavant['desc']);

        $this->assertSame(67, $sheet['currency']['gp']);
        // Base 30 + CON mod (+1) × 7 levels = 37 (DDB excludes CON from baseHitPoints).
        $this->assertSame(37, $sheet['hp']['max']);
        $this->assertSame(0, $sheet['death_saves']['failures']);

        // A level-7 wizard has 7d6 hit dice (none spent) and no pact magic.
        $this->assertSame([['die' => 6, 'total' => 7, 'used' => 0]], $sheet['hit_dice']);
        $this->assertNull($sheet['pact_slots']);

        $potion = collect($sheet['inventory'])->firstWhere('name', 'Potion of Healing (Greater)');
        $this->assertSame('Uncommon', $potion['rarity']);
    }

    public function test_a_magical_item_maps_to_the_magicitem_type(): void
    {
        $raw = ['definition' => [
            'name' => 'Bag of Holding', 'magic' => true, 'rarity' => 'Uncommon',
            'filterType' => 'Wondrous item', 'canAttune' => false,
            'description' => '<p>This bag has an interior space larger than its outside.</p>',
        ]];

        $mapped = Ddb::itemToItem($raw);

        $this->assertSame('magicitem', $mapped['item_type']);
        $this->assertSame('Bag of Holding', $mapped['name']);
        $this->assertSame('Wondrous item', $mapped['fields']['category']);
        $this->assertSame('Uncommon', $mapped['fields']['rarity']);
        $this->assertSame('No', $mapped['fields']['attunement']);
        $this->assertStringContainsString('interior space', $mapped['fields']['description']);
        $this->assertSame('Uncommon Wondrous item', $mapped['summary']);
        $this->assertStringContainsString('Bag of Holding', $mapped['document']);
    }

    public function test_a_mundane_weapon_maps_to_the_equipment_type(): void
    {
        // Top-level record (no `definition` wrapper), as the items endpoint returns for gear.
        $raw = [
            'name' => 'Longsword', 'magic' => false, 'filterType' => 'Weapon', 'type' => 'Longsword',
            'cost' => 15, 'weight' => 3, 'damage' => ['diceString' => '1d8'], 'damageType' => 'Slashing',
            'properties' => [['name' => 'Versatile']], 'description' => '<p>A versatile blade.</p>',
        ];

        $mapped = Ddb::itemToItem($raw);

        $this->assertSame('equipment', $mapped['item_type']);
        $this->assertSame('Longsword', $mapped['name']);
        $this->assertSame('Weapon', $mapped['fields']['category']);
        $this->assertSame('15 gp', $mapped['fields']['cost']);
        $this->assertSame('3 lb.', $mapped['fields']['weight']);
        $this->assertSame('1d8', $mapped['fields']['damage']);
        $this->assertSame('Slashing', $mapped['fields']['damage_type']);
        $this->assertSame('Versatile', $mapped['fields']['properties']);
    }

    public function test_mundane_armour_maps_its_ac_strength_and_stealth(): void
    {
        $raw = [
            'name' => 'Plate Armor', 'magic' => false, 'filterType' => 'Armor',
            'armorClass' => 18, 'strengthRequirement' => 15, 'stealthCheck' => 2,
            'cost' => 1500, 'weight' => 65, 'description' => '<p>Heavy plate.</p>',
        ];

        $mapped = Ddb::itemToItem($raw);

        $this->assertSame('equipment', $mapped['item_type']);
        $this->assertSame('18', $mapped['fields']['ac']);
        $this->assertSame('Str 15', $mapped['fields']['strength']);
        $this->assertSame('Disadvantage', $mapped['fields']['stealth']);
        $this->assertSame('1500 gp', $mapped['fields']['cost']);
    }

    public function test_a_levelled_spell_maps_to_the_spell_type(): void
    {
        $raw = ['definition' => [
            'name' => 'Fireball', 'level' => 3, 'school' => 'Evocation',
            'activation' => ['activationType' => 1, 'activationTime' => 1],
            'range' => ['origin' => 'Ranged', 'rangeValue' => 150],
            'components' => [1, 2, 3], 'componentsDescription' => 'a tiny ball of bat guano and sulfur',
            'concentration' => false, 'duration' => ['durationType' => 'Instantaneous'],
            'description' => '<p>A bright streak flashes to a point you choose.</p>',
        ]];

        $mapped = Ddb::spellToItem($raw);

        $this->assertSame('spell', $mapped['item_type']);
        $this->assertSame('Fireball', $mapped['name']);
        $this->assertSame('3rd', $mapped['fields']['level']);
        $this->assertSame('Evocation', $mapped['fields']['school']);
        $this->assertSame('1 action', $mapped['fields']['casting_time']);
        $this->assertSame('150 feet', $mapped['fields']['range']);
        $this->assertSame('V, S, M (a tiny ball of bat guano and sulfur)', $mapped['fields']['components']);
        $this->assertSame('Instantaneous', $mapped['fields']['duration']);
        $this->assertSame('3rd-level Evocation', $mapped['summary']);
    }

    public function test_a_cantrip_maps_level_and_summary_without_a_number(): void
    {
        $raw = ['definition' => [
            'name' => 'Spare the Dying', 'level' => 0, 'school' => 'Necromancy',
            'activation' => ['activationType' => 1], 'range' => ['origin' => 'Touch'],
            'components' => [1, 2], 'concentration' => false,
            'duration' => ['durationType' => 'Instantaneous'], 'description' => '<p>You stabilise a creature.</p>',
        ]];

        $mapped = Ddb::spellToItem($raw);

        $this->assertSame('Cantrip', $mapped['fields']['level']);
        $this->assertSame('Touch', $mapped['fields']['range']);
        $this->assertSame('V, S', $mapped['fields']['components']);
        $this->assertSame('Necromancy cantrip', $mapped['summary']);
    }

    public function test_a_concentration_spell_maps_its_duration(): void
    {
        $raw = ['definition' => [
            'name' => 'Hold Person', 'level' => 2, 'school' => 'Enchantment',
            'activation' => ['activationType' => 1], 'range' => ['origin' => 'Ranged', 'rangeValue' => 60],
            'components' => [1, 2, 3], 'componentsDescription' => 'a straight piece of iron',
            'concentration' => true, 'duration' => ['durationType' => 'Concentration', 'durationInterval' => 1, 'durationUnit' => 'Minute'],
            'description' => '<p>Choose a humanoid.</p>',
        ]];

        $mapped = Ddb::spellToItem($raw);

        $this->assertSame('Concentration, up to 1 minute', $mapped['fields']['duration']);
    }

    public function test_a_monster_maps_its_largest_avatar_and_a_stable_dedup_key(): void
    {
        $raw = [
            'name' => 'Ogre', 'id' => 17, 'sizeId' => 0, 'typeId' => 0, 'stats' => [],
            'largeAvatarUrl' => 'https://ddb/large.png', 'avatarUrl' => 'https://ddb/small.png',
        ];

        $image = Ddb::toItem($raw, [])['image'];

        $this->assertSame('https://ddb/large.png', $image['url']); // prefers the largest available
        $this->assertSame('ddb-monster-17', $image['key']);
    }

    public function test_an_item_maps_its_avatar_and_dedup_key(): void
    {
        $raw = ['definition' => ['name' => 'Wand of Magic Missiles', 'id' => 5, 'magic' => true, 'avatarUrl' => 'https://ddb/wand.png']];

        $image = Ddb::itemToItem($raw)['image'];

        $this->assertSame('https://ddb/wand.png', $image['url']);
        $this->assertSame('ddb-item-5', $image['key']);
    }

    public function test_a_spell_has_no_image(): void
    {
        $raw = ['definition' => ['name' => 'Fireball', 'level' => 3, 'school' => 'Evocation']];

        $image = Ddb::spellToItem($raw)['image'];

        $this->assertSame('', $image['url']);
        $this->assertSame('', $image['key']);
    }
}
