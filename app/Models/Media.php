<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'user_id', 'world_id', 'external_key', 'disk', 'path', 'filename', 'mime', 'size', 'alt',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['url'];

    /** Public URL for the stored file, served via the CDN base URL when one is configured. */
    public function getUrlAttribute(): string
    {
        $cdn = config('media.cdn_url');
        if (filled($cdn)) {
            return rtrim((string) $cdn, '/').'/'.ltrim($this->path, '/');
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /** Delete the underlying file when the record is removed. */
    protected static function booted(): void
    {
        static::deleting(function (Media $media) {
            Storage::disk($media->disk)->delete($media->path);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
