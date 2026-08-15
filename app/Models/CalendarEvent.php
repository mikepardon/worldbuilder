<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An event pinned to a specific date in a custom calendar.
 *
 * @property-read Calendar $calendar
 */
class CalendarEvent extends Model
{
    protected $fillable = ['calendar_id', 'year', 'month', 'day', 'title', 'description'];

    protected $casts = [
        'calendar_id' => 'int',
        'year' => 'int',
        'month' => 'int',
        'day' => 'int',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }
}
