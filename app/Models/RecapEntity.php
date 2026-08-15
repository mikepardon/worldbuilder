<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entity the analysis pulled out of a session {@see Recap} — a person, place, faction, item, monster or
 * spell that appeared. It starts unmatched; an exact-name auto-match or the GM links it to a real world
 * entry (a lore {@see Document} or a {@see CampaignCompendiumItem}), creates a fresh one, or dismisses it.
 *
 * @property-read Recap $recap
 * @property-read Document|null $linkedDocument
 * @property-read CampaignCompendiumItem|null $linkedCompendiumItem
 */
class RecapEntity extends Model
{
    protected $fillable = [
        'recap_id', 'name', 'type', 'description', 'status',
        'linked_document_id', 'linked_compendium_item_id',
    ];

    protected $casts = [
        'recap_id' => 'int',
        'linked_document_id' => 'int',
        'linked_compendium_item_id' => 'int',
    ];

    public function recap(): BelongsTo
    {
        return $this->belongsTo(Recap::class);
    }

    public function linkedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'linked_document_id');
    }

    public function linkedCompendiumItem(): BelongsTo
    {
        return $this->belongsTo(CampaignCompendiumItem::class, 'linked_compendium_item_id');
    }
}
