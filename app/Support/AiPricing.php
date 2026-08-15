<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AiModelPrice;
use Brick\Math\BigInteger;

/**
 * Prices a Claude call. Prices are US cents per million tokens (input/output), read from the
 * admin-editable ai_model_prices table and falling back to config('ai'). Costs are returned in
 * nanodollars (1e-9 USD): per token that is `cents_per_million × 10`, an exact integer, so sub-cent
 * call costs stay precise and sum without rounding loss.
 */
class AiPricing
{
    /** @return array{input: int, output: int} US cents per million tokens */
    public static function for(string $model): array
    {
        $price = AiModelPrice::query()->where('model', $model)->first();
        if ($price !== null) {
            return ['input' => $price->input_price, 'output' => $price->output_price];
        }

        $configured = config("ai.prices.{$model}");

        return is_array($configured)
            ? ['input' => (int) $configured['input'], 'output' => (int) $configured['output']]
            : ['input' => (int) config('ai.default_price.input'), 'output' => (int) config('ai.default_price.output')];
    }

    /**
     * Cost of a call in nanodollars.
     *
     * @param  array{input: int, output: int}  $price  US cents per million tokens
     */
    public static function costNanos(int $inputTokens, int $outputTokens, array $price): int
    {
        return BigInteger::of($inputTokens)->multipliedBy($price['input'])
            ->plus(BigInteger::of($outputTokens)->multipliedBy($price['output']))
            ->multipliedBy(10)
            ->toInt();
    }
}
