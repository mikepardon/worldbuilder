<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Document extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Document $document) {
            if (blank($document->slug)) {
                $document->slug = static::uniqueSlug($document->world_id, $document->kind, (string) $document->title);
            }
        });
    }

    /** A readable slug for a title, unique within its world + kind (person/zelenios, person/zelenios-2, …). */
    public static function uniqueSlug(int $worldId, string $kind, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'entry';
        $slug = $base;
        $n = 2;
        while (static::where('world_id', $worldId)->where('kind', $kind)->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    protected $fillable = [
        'world_id', 'user_id', 'title', 'slug', 'kind',
        'content', 'summary', 'data', 'is_private', 'tags',
        'access_password', 'share_token', 'card_media_id', 'banner_media_id',
        'is_featured', 'hide_from_search', 'cover_mode', 'template_id',
        'accent', 'publish_at', 'comments_enabled', 'show_toc', 'related_ids', 'slug_aliases',
    ];

    protected $casts = [
        'data' => 'array',
        'tags' => 'array',
        'is_private' => 'boolean',
        'is_featured' => 'boolean',
        'hide_from_search' => 'boolean',
        // Hashed at rest; verify reader input with Hash::check.
        'access_password' => 'hashed',
        'card_media_id' => 'int',
        'banner_media_id' => 'int',
        'template_id' => 'int',
        'publish_at' => 'datetime',
        'comments_enabled' => 'boolean',
        'show_toc' => 'boolean',
        'related_ids' => 'array',
        'slug_aliases' => 'array',
    ];

    /** Whether this entry is still scheduled for future publication (GM-only until then). */
    public function isScheduled(): bool
    {
        return $this->publish_at !== null && $this->publish_at->isFuture();
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'card_media_id');
    }

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'banner_media_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorldTemplate::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ArticleNote::class);
    }

    /** Connections drawn from this entry to others. */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(DocumentLink::class, 'from_document_id');
    }

    /** Connections drawn from other entries to this one (backlinks). */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(DocumentLink::class, 'to_document_id');
    }
}
