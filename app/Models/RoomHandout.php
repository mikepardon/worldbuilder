<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GM handout shared with everyone in a battle {@see Room}: an image, a note, or both (a map fragment,
 * a letter, a clue). Unlike a {@see RoomNote} (private to its author), handouts are visible to the table.
 *
 * @property-read Room $room
 * @property-read User $creator
 * @property-read Media|null $image
 */
class RoomHandout extends Model
{
    protected $fillable = ['room_id', 'created_by', 'image_media_id', 'title', 'body'];

    protected $casts = [
        'room_id' => 'int',
        'created_by' => 'int',
        'image_media_id' => 'int',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }
}
