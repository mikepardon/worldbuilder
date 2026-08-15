<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GM annotation drawn on a battle {@see Room} map — a freehand stroke, line, rectangle or ellipse,
 * stored as map-percentage points and shared with the whole table in real time.
 *
 * @property-read Room $room
 * @property-read User $creator
 */
class RoomDrawing extends Model
{
    protected $fillable = ['room_id', 'scene_id', 'created_by', 'kind', 'layer', 'points', 'color', 'fill'];

    protected $casts = [
        'room_id' => 'int',
        'created_by' => 'int',
        'points' => 'array',
        'fill' => 'boolean',
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
