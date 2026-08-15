<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ItemBlock;
use Tests\TestCase;

class ItemBlockTest extends TestCase
{
    public function test_it_fills_sensible_defaults_for_missing_fields(): void
    {
        $item = ItemBlock::sanitise(['mechanics' => 'Grants +1 AC.']);

        $this->assertSame('Wondrous item', $item['category']);
        $this->assertSame('Uncommon', $item['rarity']);
        $this->assertSame('No', $item['attunement']);
        $this->assertSame('Grants +1 AC.', $item['mechanics']);
    }

    public function test_it_folds_mechanics_and_flavour_into_the_description_field(): void
    {
        $fields = ItemBlock::toFields(
            ['category' => 'Armor', 'rarity' => 'Rare', 'attunement' => 'Yes', 'mechanics' => 'You gain a +1 bonus to AC.'],
            'A cracked bronze shield-disc.',
        );

        $this->assertSame('Armor', $fields['category']);
        $this->assertSame('Rare', $fields['rarity']);
        // Mechanics lead, flavour follows.
        $this->assertSame("You gain a +1 bonus to AC.\n\nA cracked bronze shield-disc.", $fields['description']);
    }

    public function test_it_falls_back_to_defaults_for_non_array_input(): void
    {
        $item = ItemBlock::sanitise('garbage');

        $this->assertSame('Wondrous item', $item['category']);
        $this->assertSame('', $item['mechanics']);
    }
}
