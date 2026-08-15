<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row global AI settings: the default model every world uses unless overridden, and the
 * assistant's product name shown to users. Edited by admins only.
 */
class AiSetting extends Model
{
    protected $fillable = ['default_model', 'assistant_name'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'default_model' => config('ai.default_model', 'claude-sonnet-4-6'),
            'assistant_name' => config('ai.name', 'Muse'),
        ]);
    }
}
