<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BillingSetting;

/**
 * Reads the active Stripe configuration from the admin-managed BillingSetting, picking the sandbox or
 * live key set based on the current mode. This is the single place the rest of the app asks "what are
 * my Stripe credentials?" — so nothing else needs to know about the sandbox/live toggle.
 */
class Billing
{
    public static function settings(): BillingSetting
    {
        return BillingSetting::current();
    }

    public static function mode(): string
    {
        return static::settings()->mode === 'live' ? 'live' : 'sandbox';
    }

    public static function isLive(): bool
    {
        return static::mode() === 'live';
    }

    public static function publishableKey(): ?string
    {
        return static::value('publishable_key');
    }

    public static function secretKey(): ?string
    {
        return static::value('secret_key');
    }

    public static function webhookSecret(): ?string
    {
        return static::value('webhook_secret');
    }

    /** The active Stripe price ID for a plan key ('basic'|'pro'), or null if unset / not a paid plan. */
    public static function priceId(string $plan): ?string
    {
        if ($plan !== 'basic' && $plan !== 'pro') {
            return null;
        }

        return static::value("price_{$plan}");
    }

    /** Map an active Stripe price ID back to its plan key — the reliable source of a subscription's plan. */
    public static function planForPrice(?string $priceId): ?string
    {
        if (blank($priceId)) {
            return null;
        }

        return match ($priceId) {
            static::priceId('basic') => 'basic',
            static::priceId('pro') => 'pro',
            default => null,
        };
    }

    /** Billing is usable only once the active set has both a publishable and a secret key. */
    public static function configured(): bool
    {
        return filled(static::publishableKey()) && filled(static::secretKey());
    }

    /** Read a field from the active ('test_'|'live_') key set. */
    private static function value(string $suffix): ?string
    {
        $prefix = static::isLive() ? 'live_' : 'test_';
        $value = static::settings()->{$prefix.$suffix};

        return filled($value) ? (string) $value : null;
    }
}
