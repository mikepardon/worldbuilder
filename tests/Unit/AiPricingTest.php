<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AiPricing;
use Tests\TestCase;

class AiPricingTest extends TestCase
{
    public function test_cost_in_nanodollars_is_exact_for_split_input_output_pricing(): void
    {
        // $3/M input, $15/M output → 300 / 1500 cents per million.
        $cost = AiPricing::costNanos(1000, 500, ['input' => 300, 'output' => 1500]);

        // (1000×300 + 500×1500) × 10 = 10,500,000 nanodollars = $0.0105.
        $this->assertSame(10_500_000, $cost);
    }

    public function test_sub_dollar_rates_stay_integer(): void
    {
        // Haiku $0.80/M input → 80 cents per million; per token that is 800 nanodollars.
        $cost = AiPricing::costNanos(1, 0, ['input' => 80, 'output' => 400]);

        $this->assertSame(800, $cost);
    }
}
