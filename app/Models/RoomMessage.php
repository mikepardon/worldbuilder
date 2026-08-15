<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chat message in a battle {@see Room}. Carries a `roll` payload when it was a /roll command.
 *
 * @property-read Room $room
 * @property-read User|null $user
 * @property-read User|null $recipient
 */
class RoomMessage extends Model
{
    protected $fillable = ['room_id', 'user_id', 'to_user_id', 'body', 'roll'];

    protected $casts = [
        'room_id' => 'int',
        'user_id' => 'int',
        'to_user_id' => 'int',
        'roll' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
