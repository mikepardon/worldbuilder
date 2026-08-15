<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's own note — a personal scratchpad kept on their dashboard, independent of any single entry.
 *
 * @property-read User $user
 * @property-read Campaign|null $campaign
 */
class UserNote extends Model
{
    protected $fillable = ['user_id', 'world_id', 'title', 'body'];

    protected $casts = [
        'user_id' => 'int',
        'world_id' => 'int',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
