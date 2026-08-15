<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\World;

/**
 * Resolves which Claude model a call should use and the assistant's product name. The default model is
 * an admin setting; a world may override it. Selectable models are those with a price row, so adding a
 * price makes a model choosable. The model is deliberately server-side only — never sent to end users.
 */
class AiModels
{
    public static function default(): string
    {
        $model = AiSetting::current()->default_model;

        return filled($model) ? $model : (string) config('ai.default_model', 'claude-sonnet-4-6');
    }

    /** The assistant's product name (e.g. "Muse"). */
    public static function name(): string
    {
        $name = AiSetting::current()->assistant_name;

        return filled($name) ? $name : (string) config('ai.name', 'Muse');
    }

    /** The model for a world's calls: its own override, or the global default. */
    public static function forWorld(?int $worldId): string
    {
        if ($worldId === null) {
            return self::default();
        }

        $model = World::query()->whereKey($worldId)->value('ai_model');

        return filled($model) ? (string) $model : self::default();
    }

    /** @return list<string> Models an admin can choose from (those with a price row). */
    public static function available(): array
    {
        $models = AiModelPrice::query()->orderBy('model')->pluck('model')->all();

        return $models !== [] ? $models : array_keys(config('ai.prices', []));
    }
}
