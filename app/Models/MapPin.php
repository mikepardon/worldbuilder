<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clickable marker on a {@see Map}, positioned as a percentage of the image and optionally linked to
 * a world entry.
 *
 * @property-read Map $map
 * @property-read Document|null $document
 * @property-read CampaignCompendiumItem|null $compendiumItem
 */
class MapPin extends Model
{
    protected $fillable = ['map_id', 'document_id', 'compendium_item_id', 'behavior', 'style', 'x', 'y', 'label', 'note'];

    protected $casts = [
        'map_id' => 'int',
        'document_id' => 'int',
        'compendium_item_id' => 'int',
        'x' => 'float',
        'y' => 'float',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function compendiumItem(): BelongsTo
    {
        return $this->belongsTo(CampaignCompendiumItem::class);
    }
}
