<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Subscription plans and their entitlements. Prices are in pence (GBP). Storage/credit numbers are
 * indicative placeholders until finalised. Stripe price IDs are filled in when the keys arrive.
 */
class Plans
{
    public const PLANS = [
        'free' => [
            'key' => 'free',
            'name' => 'Free',
            'price' => 0,
            'worlds' => 1,
            'daily_credits' => 5,
            'monthly_credits' => 0,
            'storage_gb' => 2,
            'custom_domain' => false,
            'stripe_price_id' => null,
            'blurb' => 'Everything to build one world and share it with your table.',
        ],
        'basic' => [
            'key' => 'basic',
            'name' => 'Basic',
            'price' => 500,
            'worlds' => 3,
            'daily_credits' => 5,
            'monthly_credits' => 300,
            'storage_gb' => 10,
            'custom_domain' => true,
            'stripe_price_id' => null,
            'blurb' => 'More worlds, more AI, more storage, and a custom domain.',
        ],
        'pro' => [
            'key' => 'pro',
            'name' => 'Pro',
            'price' => 1500,
            'worlds' => 10,
            'daily_credits' => 5,
            'monthly_credits' => 1000,
            'storage_gb' => 30,
            'custom_domain' => true,
            'stripe_price_id' => null,
            'blurb' => 'For prolific GMs running many tables at once.',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(string $key): array
    {
        return self::PLANS[$key] ?? self::PLANS['free'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(self::PLANS);
    }

    public static function isPlan(string $key): bool
    {
        return isset(self::PLANS[$key]);
    }
}
