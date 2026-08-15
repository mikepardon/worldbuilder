<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row holding Stripe billing configuration. Both key sets live here at once; `mode` picks
 * the active one. Secret + webhook values are encrypted at rest and never exposed to the frontend.
 */
class BillingSetting extends Model
{
    protected $fillable = [
        'mode',
        'test_publishable_key', 'test_secret_key', 'test_webhook_secret', 'test_price_basic', 'test_price_pro',
        'live_publishable_key', 'live_secret_key', 'live_webhook_secret', 'live_price_basic', 'live_price_pro',
    ];

    protected $attributes = [
        'mode' => 'sandbox',
    ];

    protected $casts = [
        'test_secret_key' => 'encrypted',
        'test_webhook_secret' => 'encrypted',
        'live_secret_key' => 'encrypted',
        'live_webhook_secret' => 'encrypted',
    ];

    /** The one settings row, created with defaults on first access. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
