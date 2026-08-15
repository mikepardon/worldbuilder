<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's RSVP to a {@see ScheduleEvent}: whether they can make that session, and an optional note.
 * One row per player per event.
 *
 * @property-read ScheduleEvent $scheduleEvent
 * @property-read User $user
 */
class ScheduleEventResponse extends Model
{
    /** @var list<string> */
    public const STATUSES = ['attending', 'tentative', 'declined'];

    protected $fillable = ['schedule_event_id', 'user_id', 'status', 'note'];

    protected $casts = [
        'schedule_event_id' => 'int',
        'user_id' => 'int',
    ];

    public function scheduleEvent(): BelongsTo
    {
        return $this->belongsTo(ScheduleEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
