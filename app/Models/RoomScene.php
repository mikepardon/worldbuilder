<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One map within a battle {@see Room}. A room can hold several scenes and switch the active one; each
 * scene owns its map image, grid, unit and fog, and its own tokens, templates and drawings.
 *
 * @property-read Room $room
 * @property-read Media|null $image
 */
class RoomScene extends Model
{
    protected $fillable = [
        'room_id', 'name', 'image_media_id', 'grid_visible', 'grid_size',
        'unit_size', 'unit', 'fog_enabled', 'fog', 'sort',
    ];

    protected $casts = [
        'room_id' => 'int',
        'image_media_id' => 'int',
        'grid_visible' => 'boolean',
        'grid_size' => 'int',
        'unit_size' => 'float',
        'fog_enabled' => 'boolean',
        'fog' => 'array',
        'sort' => 'int',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /** @return HasMany<RoomToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(RoomToken::class, 'scene_id');
    }

    /** @return HasMany<RoomTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(RoomTemplate::class, 'scene_id');
    }

    /** @return HasMany<RoomDrawing, $this> */
    public function drawings(): HasMany
    {
        return $this->hasMany(RoomDrawing::class, 'scene_id');
    }
}
