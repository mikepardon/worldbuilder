<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CritterDb;
use Tests\TestCase;

class CritterDbTest extends TestCase
{
    /** @return array<string, mixed> */
    private function goblin(): array
    {
        return [
            'name' => 'Goblin',
            'flavor' => ['description' => 'A small, black-hearted humanoid.'],
            'stats' => [
                'size' => 'Small',
                'race' => 'Humanoid',
                'alignment' => 'Neutral Evil',
                'armorClass' => 15,
                'armorType' => 'Leather Armor',
                'numHitDie' => 2,
                'hitDieSize' => 6,
                'speed' => '30 ft.',
                'abilityScores' => ['strength' => 8, 'dexterity' => 14, 'constitution' => 10, 'intelligence' => 10, 'wisdom' => 8, 'charisma' => 8],
                'challengeRating' => 0.25,
                'damageImmunities' => ['Poison'],
                'senses' => ['darkvision 60 ft.'],
                'languages' => ['Common', 'Goblin'],
                'additionalAbilities' => [['name' => 'Nimble Escape', 'description' => '<i>The goblin</i> can Disengage.']],
                'actions' => [['name' => 'Scimitar', 'description' => 'Melee: +4 to hit.']],
            ],
        ];
    }

    public function test_it_maps_ability_scores_size_type_and_alignment(): void
    {
        $block = CritterDb::toItem($this->goblin())['block'];

        $this->assertSame(['str' => 8, 'dex' => 14, 'con' => 10, 'int' => 10, 'wis' => 8, 'cha' => 8], $block['abilities']);
        $this->assertSame('Small', $block['size']);
        $this->assertSame('Humanoid', $block['type']);
        $this->assertSame('Neutral Evil', $block['alignment']);
    }

    public function test_it_combines_armor_class_with_armor_type(): void
    {
        $this->assertSame('15 (Leather Armor)', CritterDb::toItem($this->goblin())['block']['ac']);
    }

    public function test_it_derives_hit_points_from_dice_with_no_constitution_bonus(): void
    {
        // 2d6, CON 10 (+0): average 7, so "7 (2d6)".
        $this->assertSame('7 (2d6)', CritterDb::toItem($this->goblin())['block']['hp']);
    }

    public function test_it_adds_the_constitution_bonus_per_hit_die(): void
    {
        $creature = $this->goblin();
        $creature['stats']['numHitDie'] = 23;
        $creature['stats']['hitDieSize'] = 20;
        $creature['stats']['abilityScores']['constitution'] = 16; // +3 per die -> +69

        // 23 * 10.5 = 241.5 -> floor 241, plus 69 = 310.
        $this->assertSame('310 (23d20 + 69)', CritterDb::toItem($creature)['block']['hp']);
    }

    public function test_it_renders_fractional_challenge_ratings_as_fractions(): void
    {
        $this->assertSame('1/4', CritterDb::toItem($this->goblin())['block']['cr']);
    }

    public function test_it_strips_html_from_trait_and_action_descriptions(): void
    {
        $block = CritterDb::toItem($this->goblin())['block'];

        $this->assertSame('Nimble Escape', $block['traits'][0]['name']);
        $this->assertSame('The goblin can Disengage.', $block['traits'][0]['desc']);
        $this->assertSame('Melee: +4 to hit.', $block['actions'][0]['desc']);
    }

    public function test_it_joins_array_valued_fields_into_readable_strings(): void
    {
        $block = CritterDb::toItem($this->goblin())['block'];

        $this->assertSame('Poison', $block['immunities']);
        $this->assertSame('Common, Goblin', $block['languages']);
        $this->assertSame('darkvision 60 ft.', $block['senses']);
    }

    public function test_it_builds_a_summary_and_titled_markdown_document(): void
    {
        $mapped = CritterDb::toItem($this->goblin());

        $this->assertSame('Small Humanoid · CR 1/4', $mapped['summary']);
        $this->assertStringContainsString('# Goblin', $mapped['document']);
        $this->assertStringContainsString('15 (Leather Armor)', $mapped['document']);
        $this->assertStringContainsString('Nimble Escape', $mapped['document']);
    }

    public function test_it_extracts_a_bestiary_id_from_a_share_link(): void
    {
        $this->assertSame(
            '6512686e94b584b853ef1586',
            CritterDb::bestiaryIdFromReference('https://critterdb.com/#/publishedbestiary/view/6512686E94B584B853EF1586'),
        );
    }

    public function test_it_returns_null_when_a_reference_has_no_id(): void
    {
        $this->assertNull(CritterDb::bestiaryIdFromReference('https://example.com/not-a-bestiary'));
    }

    public function test_it_normalises_list_envelope_and_single_creature_exports(): void
    {
        $this->assertCount(1, CritterDb::creaturesFromExport([$this->goblin()]));
        $this->assertCount(1, CritterDb::creaturesFromExport(['creatures' => [$this->goblin()]]));
        $this->assertCount(1, CritterDb::creaturesFromExport($this->goblin()));
        $this->assertCount(0, CritterDb::creaturesFromExport([['stats' => []]])); // no name -> dropped
    }
}
