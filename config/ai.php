<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant name & default model (fallbacks)
    |--------------------------------------------------------------------------
    |
    | The product name shown to users for the AI, and the default model, used to
    | seed the admin-editable ai_settings row and as a fallback if it's missing.
    |
    */

    'name' => 'Muse',

    'default_model' => 'claude-sonnet-4-6',

    /*
    |--------------------------------------------------------------------------
    | Default model pricing
    |--------------------------------------------------------------------------
    |
    | Fallback Claude prices in US cents per million tokens (input / output),
    | used to seed the admin-editable `ai_model_prices` table and to cost any
    | model that isn't in that table yet. Admins can override live prices in
    | Admin → AI usage; each call's cost is snapshotted at the price in effect
    | when it ran, so history stays accurate when prices change.
    |
    */

    'prices' => [
        'claude-sonnet-4-6' => ['input' => 300, 'output' => 1500],
        'claude-haiku-4-5' => ['input' => 80, 'output' => 400],
        'claude-opus-4-8' => ['input' => 1500, 'output' => 7500],
    ],

    'default_price' => ['input' => 300, 'output' => 1500],

];
