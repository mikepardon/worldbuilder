<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A world's custom calendar: its own months and weekdays. Days are numbered absolutely from year 1,
 * day 1, so a weekday can be derived deterministically for any date (see App\Support\CalendarMath).
 *
 * @property-read World $world
 * @property-read Collection<int, CalendarEvent> $events
 */
class Calendar extends Model
{
    protected $fillable = ['world_id', 'name', 'slug', 'months', 'weekdays', 'leap_rules', 'moons', 'current_year', 'sort'];

    protected $casts = [
        'world_id' => 'int',
        'months' => 'array',
        'weekdays' => 'array',
        'leap_rules' => 'array',
        'moons' => 'array',
        'current_year' => 'int',
        'sort' => 'int',
    ];

    protected static function booted(): void
    {
        static::creating(function (Calendar $calendar) {
            if (blank($calendar->slug)) {
                $calendar->slug = static::uniqueSlug((int) $calendar->world_id, (string) $calendar->name);
            }
        });
    }

    public static function uniqueSlug(int $worldId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'calendar';
        $slug = $base;
        $n = 2;
        while (static::where('world_id', $worldId)->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** Total days in a year of this calendar. */
    public function yearLength(): int
    {
        return (int) collect($this->months ?? [])->sum(fn (array $month) => (int) ($month['days'] ?? 0));
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return HasMany<CalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class)->chaperone();
    }
}
