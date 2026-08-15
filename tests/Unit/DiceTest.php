<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dice;
use PHPUnit\Framework\TestCase;

class DiceTest extends TestCase
{
    public function test_it_rolls_and_adds_a_modifier(): void
    {
        // d1 always rolls 1, so this is deterministic.
        $result = Dice::roll('1d1+5');

        $this->assertSame(6, $result['total']);
        $this->assertSame('1d1+5', $result['expr']);
    }

    public function test_it_sums_multiple_dice_and_number_terms(): void
    {
        $result = Dice::roll('2d1+1d1+3');

        $this->assertSame(6, $result['total']);
    }

    public function test_it_subtracts_negative_terms(): void
    {
        $this->assertSame(-1, Dice::roll('1d1-2')['total']);
    }

    public function test_it_stays_within_bounds(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $total = Dice::roll('3d6')['total'];
            $this->assertGreaterThanOrEqual(3, $total);
            $this->assertLessThanOrEqual(18, $total);
        }
    }

    public function test_advantage_keeps_the_higher_die_and_reports_the_dropped_one(): void
    {
        // 2d6kh1: keep the highest of two d6. Deterministic bounds; assert the mode + dropped shape.
        for ($i = 0; $i < 40; $i++) {
            $result = Dice::roll('2d6kh1+2');
            $this->assertSame('advantage', $result['mode']);
            $this->assertCount(1, $result['dropped']);
            // Total = highest die + 2; the dropped die is never greater than the total-minus-modifier.
            $this->assertLessThanOrEqual($result['total'] - 2, $result['dropped'][0]);
        }
    }

    public function test_disadvantage_keeps_the_lower_die(): void
    {
        $result = Dice::roll('2d1kl1'); // both dice are 1
        $this->assertSame(1, $result['total']);
        $this->assertSame('disadvantage', $result['mode']);
        $this->assertSame([1], $result['dropped']);
    }

    public function test_a_plain_roll_has_no_advantage_mode(): void
    {
        $result = Dice::roll('1d1+5');
        $this->assertNull($result['mode']);
        $this->assertSame([], $result['dropped']);
    }

    public function test_a_d20_test_never_totals_below_one(): void
    {
        // A d20 Test reduced far below zero (e.g. heavy exhaustion) still can't total under 1.
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(1, Dice::roll('1d20-100')['total']);
        }
    }

    public function test_it_reports_the_natural_d20_for_highlighting(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $natural = Dice::roll('1d20+3')['d20'];
            $this->assertNotNull($natural);
            $this->assertGreaterThanOrEqual(1, $natural);
            $this->assertLessThanOrEqual(20, $natural);
        }

        $this->assertNotNull(Dice::roll('2d20kh1')['d20'], 'advantage keeps one die, so the natural is known');
        $this->assertNull(Dice::roll('2d6+1')['d20'], 'a non-d20 roll has no natural d20');
        $this->assertNull(Dice::roll('2d20')['d20'], 'two unkept d20s are ambiguous');
    }

    public function test_a_non_d20_roll_is_not_floored_at_one(): void
    {
        $this->assertSame(-1, Dice::roll('1d1-2')['total']);
    }

    public function test_it_rejects_non_dice_input(): void
    {
        $this->assertNull(Dice::roll('hello'));
        $this->assertNull(Dice::roll('5'));      // a bare number is not a roll
        $this->assertNull(Dice::roll(''));
        $this->assertNull(Dice::roll('1d'));     // malformed
    }
}
