<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A live battle room (VTT): GM-created, not tied to a location. Players join by code and control their
 * own tokens; the GM runs the board (grid, fog, initiative).
 *
 * @property-read Campaign $campaign
 * @property-read Media|null $image
 * @property-read Collection<int, RoomToken> $tokens
 * @property-read Collection<int, User> $members
 */
class Room extends Model
{
    protected $fillable = [
        'campaign_id', 'created_by', 'image_media_id', 'name', 'code',
        'grid_visible', 'grid_size', 'unit_size', 'unit', 'fog_enabled', 'fog', 'round', 'active_token_id',
        'player_monster_ids', 'players_see_tracker', 'active_scene_id',
    ];

    protected $casts = [
        'campaign_id' => 'int',
        'created_by' => 'int',
        'image_media_id' => 'int',
        'active_token_id' => 'int',
        'active_scene_id' => 'int',
        'grid_visible' => 'boolean',
        'grid_size' => 'int',
        'unit_size' => 'float',
        'fog_enabled' => 'boolean',
        'fog' => 'array',
        'round' => 'int',
        'player_monster_ids' => 'array',
        'players_see_tracker' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            if (blank($room->code)) {
                $room->code = static::uniqueCode();
            }
        });

        // Every room needs at least one scene (its default map); create and activate it on creation.
        static::created(function (Room $room) {
            if ($room->active_scene_id === null) {
                $scene = $room->scenes()->create(['name' => 'Scene 1']);
                $room->forceFill(['active_scene_id' => $scene->id])->saveQuietly();
            }
        });
    }

    /** A short, unambiguous shareable join code (no easily-confused characters). */
    public static function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function isMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /** @return HasMany<RoomScene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(RoomScene::class);
    }

    public function activeScene(): BelongsTo
    {
        return $this->belongsTo(RoomScene::class, 'active_scene_id');
    }

    /** @return HasMany<RoomToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(RoomToken::class);
    }

    /** @return HasMany<RoomMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(RoomMessage::class);
    }

    /** @return HasMany<RoomNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(RoomNote::class);
    }

    /** @return HasMany<RoomTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(RoomTemplate::class);
    }

    /** @return HasMany<RoomHandout, $this> */
    public function handouts(): HasMany
    {
        return $this->hasMany(RoomHandout::class);
    }

    /** @return HasMany<RoomDrawing, $this> */
    public function drawings(): HasMany
    {
        return $this->hasMany(RoomDrawing::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'room_members')->withTimestamps();
    }
}
