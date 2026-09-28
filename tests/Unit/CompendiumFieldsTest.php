<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CompendiumFields;
use PHPUnit\Framework\TestCase;

class CompendiumFieldsTest extends TestCase
{
    /** @var array<string, mixed> */
    private const LEVELED_SPELL = [
        'level' => 'Cantrip',
        'school' => 'Evocation',
        'levels' => [
            ['level' => 1, 'mana' => 2, 'min_level' => 1, 'name' => 'Firebolt', 'range' => '120 feet', 'description' => 'A dart of fire.'],
            ['level' => 4, 'mana' => 8, 'min_level' => 5, 'name' => 'Fireball', 'range' => '150 feet', 'description' => 'A roaring blast.'],
        ],
    ];

    public function test_meta_summarises_a_leveled_spell_as_a_rank_and_mana_range(): void
    {
        $this->assertSame('L1–L4 · 2–8 mana', CompendiumFields::meta('spell', self::LEVELED_SPELL));
    }

    public function test_meta_uses_a_single_rank_label_when_a_spell_has_one_rank(): void
    {
        $this->assertSame('L2 · 3 mana', CompendiumFields::meta('spell', ['levels' => [['level' => 2, 'mana' => 3]]]));
    }

    public function test_meta_falls_back_to_the_flat_dnd_level_when_no_ranks_are_defined(): void
    {
        $this->assertSame('3rd', CompendiumFields::meta('spell', ['level' => '3rd']));
    }

    public function test_meta_is_blank_for_non_spell_types(): void
    {
        $this->assertSame('', CompendiumFields::meta('feat', ['level' => '3rd']));
    }

    public function test_the_document_renders_each_rank_with_its_mana_name_and_level_requirement(): void
    {
        $document = CompendiumFields::toMarkdown('spell', self::LEVELED_SPELL, 'Fire');

        $this->assertStringContainsString('#### Fire', $document);
        $this->assertStringContainsString('***Ranks.***', $document);
        $this->assertStringContainsString('**Level 1 · 2 mana · Firebolt**', $document);
        $this->assertStringContainsString('**Level 4 · 8 mana · Fireball**', $document);
        $this->assertStringContainsString('Requires level 5', $document);
        $this->assertStringContainsString('A roaring blast.', $document);
    }

    public function test_a_flat_spell_without_ranks_renders_no_ranks_section(): void
    {
        $document = CompendiumFields::toMarkdown('spell', ['level' => '1st', 'description' => 'Just a spell.'], 'Bless');

        $this->assertStringNotContainsString('***Ranks.***', $document);
        $this->assertStringContainsString('Just a spell.', $document);
    }
}
