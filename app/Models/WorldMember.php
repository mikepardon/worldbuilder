<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A co-author of a world — an account the owner has granted edit access to its content. */
class WorldMember extends Model
{
    protected $fillable = ['world_id', 'user_id', 'role'];

    protected $casts = [
        'world_id' => 'int',
        'user_id' => 'int',
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
