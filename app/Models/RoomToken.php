<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A token on a battle {@see Room} — also the combatant that appears on the initiative tracker. Owned
 * by the player who placed it (null owner = GM-placed); an owner may move only their own, the GM any.
 *
 * @property-read Room $room
 * @property-read User|null $owner
 * @property-read CampaignCompendiumItem|null $compendiumItem
 * @property-read Document|null $document
 * @property-read Character|null $character
 * @property-read Media|null $image
 */
class RoomToken extends Model
{
    protected $fillable = [
        'room_id', 'scene_id', 'user_id', 'compendium_item_id', 'document_id', 'character_id', 'image_media_id', 'x', 'y', 'size', 'label', 'color',
        'kind', 'layer', 'ddb_character_id', 'hp', 'max_hp', 'ac', 'notes', 'conditions', 'initiative', 'elevation', 'in_tracker',
        'temp_hp', 'death_success', 'death_fail', 'exhaustion', 'concentrating_on', 'spell_slots_used', 'hit_dice_used', 'sheet_state',
    ];

    protected $casts = [
        'room_id' => 'int',
        'user_id' => 'int',
        'compendium_item_id' => 'int',
        'document_id' => 'int',
        'character_id' => 'int',
        'image_media_id' => 'int',
        'x' => 'float',
        'y' => 'float',
        'size' => 'float',
        'hp' => 'int',
        'max_hp' => 'int',
        'ac' => 'int',
        'conditions' => 'array',
        'initiative' => 'int',
        'elevation' => 'int',
        'in_tracker' => 'boolean',
        'temp_hp' => 'int',
        'death_success' => 'int',
        'death_fail' => 'int',
        'exhaustion' => 'int',
        'spell_slots_used' => 'array',
        'hit_dice_used' => 'array',
        'sheet_state' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function compendiumItem(): BelongsTo
    {
        return $this->belongsTo(CampaignCompendiumItem::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }
}
