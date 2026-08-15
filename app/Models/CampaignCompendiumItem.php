<?php

namespace App\Models;

use App\Queries\DocumentQuery;
use App\Support\Viewer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignCompendiumItem extends Model
{
    protected $fillable = [
        'world_id', 'user_id', 'item_type', 'slug', 'name', 'summary',
        'document', 'fields', 'data', 'source_item_id', 'provider', 'origin', 'is_private',
        'tags', 'is_active', 'image_media_id',
    ];

    protected $casts = [
        'fields' => 'array',
        'data' => 'array',
        'tags' => 'array',
        'is_private' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Restrict to what a viewer may see: the GM sees everything, players only public, active entries.
     * Mirrors the document visibility rule in {@see DocumentQuery}.
     */
    public function scopeVisibleTo(Builder $query, Viewer $viewer): void
    {
        if (! $viewer->seesPrivate()) {
            $query->where('is_private', false)->where('is_active', true);
        }
    }

    /** Case-insensitive search over an item's name and summary (portable LOWER(...) LIKE). */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower(trim($term)).'%';
        $query->where(function (Builder $builder) use ($like): void {
            $builder->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$like]);
        });
    }

    /** Imported-from-global items are locked: the GM can toggle visibility but not edit content. */
    public function isLocked(): bool
    {
        return $this->provider === 'imported';
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** The image shown for this entry — a shared DDB image or a per-world override upload. */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }
}
