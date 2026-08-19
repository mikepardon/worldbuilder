<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proposed change within a {@see WorldIngestion}: create a new entry or update an existing one, in the
 * wiki (documents) or the compendium. Carries the AI's rationale for the review list and the instruction
 * the credit-gated apply pass uses to generate the actual content.
 *
 * @property-read WorldIngestion $ingestion
 * @property-read Document|null $document
 * @property-read CampaignCompendiumItem|null $compendiumItem
 */
class WorldIngestionChange extends Model
{
    protected $fillable = [
        'world_ingestion_id', 'action', 'target', 'kind', 'title', 'rationale', 'instruction',
        'document_id', 'campaign_compendium_item_id', 'decision', 'status', 'result',
    ];

    protected $casts = [
        'world_ingestion_id' => 'int',
        'document_id' => 'int',
        'campaign_compendium_item_id' => 'int',
    ];

    public function ingestion(): BelongsTo
    {
        return $this->belongsTo(WorldIngestion::class, 'world_ingestion_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function compendiumItem(): BelongsTo
    {
        return $this->belongsTo(CampaignCompendiumItem::class, 'campaign_compendium_item_id');
    }

    /**
     * The shape the review/progress UI consumes for a single proposed change.
     *
     * @return array<string, mixed>
     */
    public function toReviewArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'target' => $this->target,
            'kind' => $this->kind,
            'title' => $this->title,
            'rationale' => $this->rationale,
            'decision' => $this->decision,
            'status' => $this->status,
            'result' => $this->result,
        ];
    }
}
