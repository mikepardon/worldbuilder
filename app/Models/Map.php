<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Viewer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An interactive map image within a world, with clickable pins that link to entries.
 *
 * @property-read Campaign $campaign
 * @property-read Media|null $image
 * @property-read Collection<int, MapPin> $pins
 */
class Map extends Model
{
    protected $fillable = [
        'world_id', 'image_media_id', 'document_id', 'name', 'slug', 'is_private', 'sort',
        'grid_visible', 'grid_size', 'unit_size', 'unit', 'real_width', 'distance_unit', 'fog_enabled', 'fog',
    ];

    protected $casts = [
        'world_id' => 'int',
        'image_media_id' => 'int',
        'is_private' => 'boolean',
        'sort' => 'int',
        'grid_visible' => 'boolean',
        'grid_size' => 'int',
        'unit_size' => 'float',
        'real_width' => 'float',
        'fog_enabled' => 'boolean',
        'fog' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Map $map) {
            if (blank($map->slug)) {
                $map->slug = static::uniqueSlug((int) $map->world_id, (string) $map->name);
            }
        });
    }

    /** A readable slug for a name, unique within its world (harbour, harbour-2, …). */
    public static function uniqueSlug(int $worldId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'map';
        $slug = $base;
        $n = 2;
        while (static::where('world_id', $worldId)->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** The GM sees every map; players see only the ones that aren't private. */
    public function scopeVisibleTo(Builder $query, Viewer $viewer): void
    {
        if (! $viewer->seesPrivate()) {
            $query->where('is_private', false);
        }
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /** The location this map depicts, if any. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<MapPin, $this> */
    public function pins(): HasMany
    {
        return $this->hasMany(MapPin::class);
    }
}
