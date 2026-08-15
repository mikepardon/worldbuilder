<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's private journal entry for a battle {@see Room} — session notes only they can see.
 *
 * @property-read Room $room
 * @property-read User $user
 */
class RoomNote extends Model
{
    protected $fillable = ['room_id', 'user_id', 'body'];

    protected $casts = [
        'room_id' => 'int',
        'user_id' => 'int',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
