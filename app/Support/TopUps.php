<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One-off AI-credit bundles. Prices are in pence (GBP). These are sold via ad-hoc Stripe Checkout
 * line items (inline price_data), so — unlike subscription plans — they need no pre-created Stripe
 * price IDs and nothing to configure in the admin area.
 */
class TopUps
{
    public const BUNDLES = [
        'small' => ['key' => 'small', 'credits' => 50, 'price' => 300, 'name' => '50 AI credits'],
        'medium' => ['key' => 'medium', 'credits' => 150, 'price' => 800, 'name' => '150 AI credits'],
        'large' => ['key' => 'large', 'credits' => 500, 'price' => 2000, 'name' => '500 AI credits'],
    ];

    /** @return list<array{key: string, credits: int, price: int, name: string}> */
    public static function all(): array
    {
        return array_values(self::BUNDLES);
    }

    /** @return array{key: string, credits: int, price: int, name: string}|null */
    public static function find(string $key): ?array
    {
        return self::BUNDLES[$key] ?? null;
    }
}
