<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A placed area-of-effect template on a battle {@see Room} map (a spell's circle, cone, line or cube).
 * Persistent and shared with everyone in the room until its creator or the GM dismisses it.
 *
 * @property-read Room $room
 * @property-read User $creator
 */
class RoomTemplate extends Model
{
    protected $fillable = ['room_id', 'scene_id', 'created_by', 'kind', 'layer', 'x', 'y', 'length', 'angle', 'color'];

    protected $casts = [
        'room_id' => 'int',
        'created_by' => 'int',
        'x' => 'float',
        'y' => 'float',
        'length' => 'float',
        'angle' => 'float',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
