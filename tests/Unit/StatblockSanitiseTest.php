<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Statblock;
use Tests\TestCase;

class StatblockSanitiseTest extends TestCase
{
    public function test_it_keeps_valid_string_fields_and_clamps_ability_scores(): void
    {
        $block = Statblock::sanitise([
            'ac' => '17 (natural armor)',
            'cr' => '8',
            'abilities' => ['str' => 25, 'dex' => 0, 'con' => 14],
        ]);

        $this->assertSame('17 (natural armor)', $block['ac']);
        $this->assertSame('8', $block['cr']);
        $this->assertSame(25, $block['abilities']['str']); // within 1–30
        $this->assertSame(1, $block['abilities']['dex']); // clamped up from 0
        $this->assertSame(14, $block['abilities']['con']);
        $this->assertSame(10, $block['abilities']['wis']); // untouched default
    }

    public function test_it_normalises_action_groups_and_drops_empty_rows(): void
    {
        $block = Statblock::sanitise([
            'actions' => [
                ['name' => 'Multiattack', 'desc' => 'Two claw attacks.'],
                ['name' => '', 'desc' => ''],
                'not-an-array',
            ],
        ]);

        $this->assertCount(1, $block['actions']);
        $this->assertSame('Multiattack', $block['actions'][0]['name']);
        $this->assertSame('Two claw attacks.', $block['actions'][0]['desc']);
    }

    public function test_it_falls_back_to_an_empty_block_for_non_array_input(): void
    {
        $this->assertSame(Statblock::empty(), Statblock::sanitise(null));
        $this->assertSame(Statblock::empty(), Statblock::sanitise('garbage'));
    }
}
