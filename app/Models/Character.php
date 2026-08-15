<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A player character in a campaign — the party roster. Owned by a player (null = a GM-kept NPC), it
 * may be linked to a D&D Beyond character (portrait + cached stats) and is what @-mentions and battle
 * room tokens point at.
 *
 * @property-read Campaign $campaign
 * @property-read User|null $owner
 * @property-read Media|null $image
 */
class Character extends Model
{
    protected $fillable = [
        'campaign_id', 'user_id', 'image_media_id', 'name', 'slug', 'ddb_character_id', 'ddb_url', 'ddb_unreadable',
        'level', 'class', 'race', 'speed', 'passive_perception', 'ac', 'hp', 'max_hp', 'stats', 'sheet', 'notes',
    ];

    protected $casts = [
        'campaign_id' => 'int',
        'user_id' => 'int',
        'image_media_id' => 'int',
        'ddb_unreadable' => 'boolean',
        'level' => 'int',
        'speed' => 'int',
        'passive_perception' => 'int',
        'ac' => 'int',
        'hp' => 'int',
        'max_hp' => 'int',
        'stats' => 'array',
        'sheet' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Character $character) {
            if (blank($character->slug)) {
                $character->slug = static::uniqueSlug((int) $character->campaign_id, (string) $character->name);
            }
        });
    }

    /** A readable slug for a name, unique within its campaign (aria, aria-2, …). */
    public static function uniqueSlug(int $campaignId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'character';
        $slug = $base;
        $n = 2;
        while (static::where('campaign_id', $campaignId)->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * How a user should read in a room: the GM as "Game Master", a player as their assigned
     * character's name, falling back to their account name.
     */
    public static function roomDisplayName(User $user, Room $room): string
    {
        if ($user->can('manage', $room->campaign)) {
            return 'Game Master';
        }

        $character = static::where('campaign_id', $room->campaign_id)
            ->where('user_id', $user->id)->orderBy('id')->first();

        return $character?->name ?? $user->name;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }
}
