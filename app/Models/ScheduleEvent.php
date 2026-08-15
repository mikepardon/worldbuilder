<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real-world scheduling entry for a {@see Campaign} — "when the group next plays". Managed by the GM
 * across all their campaigns; players only see the upcoming ones. Distinct from the in-world
 * {@see CalendarEvent} (fictional dates).
 *
 * @property-read Campaign $campaign
 * @property-read Collection<int, ScheduleEventResponse> $responses
 */
class ScheduleEvent extends Model
{
    protected $fillable = ['campaign_id', 'title', 'starts_at', 'notes'];

    protected $casts = [
        'campaign_id' => 'int',
        'starts_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<ScheduleEventResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(ScheduleEventResponse::class)->chaperone();
    }
}
