<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A narrative arc within a {@see Campaign}: a run of sessions telling one movement of the story.
 * Stored in `campaign_arcs`. A session belongs to an arc, or floats free when its arc_id is null.
 *
 * @property-read Campaign $campaign
 * @property-read Collection<int, Session> $sessions
 */
class Arc extends Model
{
    protected $table = 'campaign_arcs';

    protected $fillable = ['campaign_id', 'title', 'slug', 'summary', 'status', 'sort'];

    protected $casts = [
        'campaign_id' => 'int',
        'status' => SessionStatus::class,
        'sort' => 'int',
    ];

    protected static function booted(): void
    {
        static::creating(function (Arc $arc) {
            if (empty($arc->slug)) {
                $base = Str::slug((string) $arc->title) ?: 'arc';
                $slug = $base;
                $n = 2;
                while (static::where('campaign_id', $arc->campaign_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }
                $arc->slug = $slug;
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<Session, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class)->chaperone();
    }
}
