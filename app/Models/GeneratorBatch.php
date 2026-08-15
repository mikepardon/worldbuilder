<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved run of the AI generators (see the create_generator_batches migration). `items` holds the
 * results as generated (and as later tweaked by the GM); `options` records the tick-box/select choices.
 *
 * @property-read World $world
 * @property-read User|null $user
 */
class GeneratorBatch extends Model
{
    protected $fillable = ['world_id', 'user_id', 'kind', 'context', 'options', 'items'];

    protected $casts = [
        'world_id' => 'int',
        'user_id' => 'int',
        'options' => 'array',
        'items' => 'array',
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
