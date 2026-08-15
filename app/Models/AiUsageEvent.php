<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One metered Claude call: which feature triggered it, for which world/user, the model and token
 * counts, and the cost (nanodollars, snapshotted at the price in effect then). See the
 * create_ai_pricing_and_usage_tables migration.
 *
 * @property-read World|null $world
 * @property-read User|null $user
 */
class AiUsageEvent extends Model
{
    protected $fillable = [
        'user_id', 'world_id', 'feature', 'detail', 'model',
        'input_tokens', 'output_tokens', 'cost_nanos',
    ];

    protected $casts = [
        'user_id' => 'int',
        'world_id' => 'int',
        'input_tokens' => 'int',
        'output_tokens' => 'int',
        'cost_nanos' => 'int',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
