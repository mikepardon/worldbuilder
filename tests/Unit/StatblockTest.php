<?php

namespace Tests\Unit;

use App\Support\Statblock;
use PHPUnit\Framework\TestCase;

class StatblockTest extends TestCase
{
    private array $goblin = [
        'name' => 'Goblin', 'size' => 'Small', 'type' => 'humanoid', 'subtype' => 'goblinoid',
        'alignment' => 'neutral evil', 'armor_class' => 15, 'armor_desc' => 'leather armor, shield',
        'hit_points' => 7, 'hit_dice' => '2d6', 'speed' => ['walk' => 30],
        'strength' => 8, 'dexterity' => 14, 'constitution' => 10, 'intelligence' => 10,
        'wisdom' => 8, 'charisma' => 8, 'skills' => ['stealth' => 6],
        'senses' => 'darkvision 60 ft.', 'languages' => 'Common, Goblin', 'challenge_rating' => '1/4',
        'special_abilities' => [['name' => 'Nimble Escape', 'desc' => 'Disengage or Hide as a bonus action.']],
        'actions' => [['name' => 'Scimitar', 'desc' => '+4 to hit, 5 (1d6 + 2) slashing.']],
    ];

    public function test_maps_open5e_monster(): void
    {
        $b = Statblock::fromOpen5e($this->goblin);

        $this->assertNotNull($b);
        $this->assertSame('Small', $b['size']);
        $this->assertSame('humanoid (goblinoid)', $b['type']);
        $this->assertSame('15 (leather armor, shield)', $b['ac']);
        $this->assertSame('7 (2d6)', $b['hp']);
        $this->assertSame('walk 30 ft.', $b['speed']);
        $this->assertSame(['str' => 8, 'dex' => 14, 'con' => 10, 'int' => 10, 'wis' => 8, 'cha' => 8], $b['abilities']);
        $this->assertSame('Stealth +6', $b['skills']);
        $this->assertSame('1/4', $b['cr']);
        $this->assertSame('Nimble Escape', $b['traits'][0]['name']);
        $this->assertSame('Scimitar', $b['actions'][0]['name']);
    }

    public function test_formats_saves_and_hover_speed(): void
    {
        $b = Statblock::fromOpen5e(array_merge($this->goblin, [
            'dexterity_save' => 4, 'constitution_save' => 2,
            'speed' => ['walk' => 10, 'fly' => 60, 'hover' => true],
        ]));
        $this->assertSame('Dex +4, Con +2', $b['saves']);
        $this->assertSame('walk 10 ft., fly 60 ft. (hover)', $b['speed']);
    }

    public function test_returns_null_without_ability_scores(): void
    {
        $this->assertNull(Statblock::fromOpen5e(['name' => 'A Spell', 'level' => 3]));
        $this->assertNull(Statblock::fromOpen5e('nonsense'));
    }

    public function test_renders_markdown(): void
    {
        $md = Statblock::toMarkdown(Statblock::fromOpen5e($this->goblin), 'Goblin');
        $this->assertStringContainsString('# Goblin', $md);
        $this->assertStringContainsString('**Armor Class** 15', $md);
        $this->assertStringContainsString('8 (-1)', $md); // STR 8 modifier
        $this->assertStringContainsString('### Actions', $md);
    }
}
