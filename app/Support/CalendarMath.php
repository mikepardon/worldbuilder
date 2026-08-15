<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Calendar;
use App\Models\CalendarEvent;

/**
 * Lays a custom calendar's year out for rendering. Beyond plain months + weekdays it applies:
 *
 *  - Leap rules: extra days added to a specific month on a cycle ("every N years"), with optional
 *    Gregorian-style exceptions ("except every 100, unless every 400").
 *  - Intercalary months: festival days that belong to no week — they never advance the weekday cycle,
 *    so a given weekday date stays stable regardless of how many festival days precede it.
 *  - Moons: each month is anchored with the absolute day index of its first day, so the client can
 *    derive every day's lunar phase from the moon's cycle + offset without bloating the payload.
 *
 * All day counting is deterministic from year 1, day 1, so the same date is always the same weekday
 * and the same moon phase across the whole calendar. Year spans can be huge (up to a million), so the
 * "days before year Y" figures use closed-form congruence counting rather than iterating the years.
 */
class CalendarMath
{
    /**
     * @return array{
     *     year: int,
     *     weekdays: list<string>,
     *     yearLength: int,
     *     moons: list<array{name: string, cycle: int, offset: int, colour: ?string}>,
     *     months: list<array{
     *         index: int, name: string, days: int, intercalary: bool,
     *         firstWeekday: int, firstAbsoluteDay: int,
     *         events: list<array{id: int, day: int, title: string}>,
     *     }>,
     * }
     */
    public static function year(Calendar $calendar, int $year): array
    {
        $weekdays = array_values(array_filter(
            array_map(static fn ($weekday) => (string) $weekday, $calendar->weekdays ?? []),
            static fn (string $weekday) => $weekday !== '',
        ));
        $weekdayCount = count($weekdays);

        $months = self::monthsForYear($calendar, $year);
        $eventsByMonth = $calendar->exists
            ? $calendar->events()->where('year', $year)->get()->groupBy('month')
            : collect();

        // Running offsets within the year: absolute counts every day; weekday counts only non-festival
        // days (intercalary months are skipped so they can't shift the weekday cycle).
        $absoluteInYear = 0;
        $weekdayInYear = 0;
        $absoluteBeforeYear = self::daysBeforeYear($calendar, $year, includeIntercalary: true);
        $weekdayBeforeYear = self::daysBeforeYear($calendar, $year, includeIntercalary: false);

        $out = [];
        foreach ($months as $index => $month) {
            $firstWeekday = ($weekdayCount > 0 && ! $month['intercalary'])
                ? self::mod($weekdayBeforeYear + $weekdayInYear, $weekdayCount)
                : 0;

            $out[] = [
                'index' => $index,
                'name' => $month['name'],
                'days' => $month['days'],
                'intercalary' => $month['intercalary'],
                'firstWeekday' => $firstWeekday,
                'firstAbsoluteDay' => $absoluteBeforeYear + $absoluteInYear,
                'events' => ($eventsByMonth[$index] ?? collect())
                    ->sortBy('day')
                    ->map(static fn (CalendarEvent $event) => [
                        'id' => $event->id,
                        'day' => $event->day,
                        'title' => $event->title,
                    ])->values()->all(),
            ];

            $absoluteInYear += $month['days'];
            if (! $month['intercalary']) {
                $weekdayInYear += $month['days'];
            }
        }

        return [
            'year' => $year,
            'weekdays' => $weekdays,
            'yearLength' => $absoluteInYear,
            'moons' => self::moons($calendar),
            'months' => $out,
        ];
    }

    /**
     * The calendar's months for a specific year, with leap additions applied.
     *
     * @return list<array{name: string, days: int, intercalary: bool}>
     */
    private static function monthsForYear(Calendar $calendar, int $year): array
    {
        $out = [];
        foreach (array_values($calendar->months ?? []) as $index => $month) {
            $days = max(0, (int) ($month['days'] ?? 0)) + self::leapDaysForMonth($calendar, $index, $year);

            $out[] = [
                'name' => (string) ($month['name'] ?? 'Month '.($index + 1)),
                'days' => $days,
                'intercalary' => (bool) ($month['intercalary'] ?? false),
            ];
        }

        return $out;
    }

    /** Extra days a year's leap rules add to one month index. */
    private static function leapDaysForMonth(Calendar $calendar, int $monthIndex, int $year): int
    {
        $added = 0;
        foreach ($calendar->leap_rules ?? [] as $rule) {
            if ((int) ($rule['month'] ?? -1) === $monthIndex && self::ruleAppliesInYear($rule, $year)) {
                $added += max(0, (int) ($rule['add'] ?? 0));
            }
        }

        return $added;
    }

    /**
     * Whether a leap rule fires in a given year. Base cycle is "every N years (offset by O)"; an
     * optional `except_every` suppresses it (Gregorian centuries) and `unless_every` re-enables it
     * (Gregorian 400s).
     *
     * @param  array<string, mixed>  $rule
     */
    private static function ruleAppliesInYear(array $rule, int $year): bool
    {
        $every = max(1, (int) ($rule['every'] ?? 1));
        $offset = (int) ($rule['offset'] ?? 0);

        if (! self::congruent($year, $every, $offset)) {
            return false;
        }

        $except = (int) ($rule['except_every'] ?? 0);
        if ($except > 1 && self::congruent($year, $except, $offset)) {
            $unless = (int) ($rule['unless_every'] ?? 0);

            return $unless > 1 && self::congruent($year, $unless, $offset);
        }

        return true;
    }

    /**
     * Days elapsed before the first day of year Y (year 1 → 0). When $includeIntercalary is false,
     * festival days are excluded so the result drives the weekday cycle. Closed-form so it stays O(rules)
     * for any year.
     */
    private static function daysBeforeYear(Calendar $calendar, int $year, bool $includeIntercalary): int
    {
        $priorYears = $year - 1;
        if ($priorYears <= 0) {
            return 0;
        }

        $months = array_values($calendar->months ?? []);
        $basePerYear = 0;
        foreach ($months as $month) {
            if ($includeIntercalary || ! ($month['intercalary'] ?? false)) {
                $basePerYear += max(0, (int) ($month['days'] ?? 0));
            }
        }

        $total = $basePerYear * $priorYears;

        foreach ($calendar->leap_rules ?? [] as $rule) {
            $monthIndex = (int) ($rule['month'] ?? -1);
            $month = $months[$monthIndex] ?? null;
            if ($month === null) {
                continue;
            }
            if (! $includeIntercalary && ($month['intercalary'] ?? false)) {
                continue;
            }

            $total += max(0, (int) ($rule['add'] ?? 0)) * self::countRuleYears($rule, $priorYears);
        }

        return $total;
    }

    /**
     * How many years in [1, $upTo] a leap rule fires, by inclusion-exclusion over its cycles.
     *
     * @param  array<string, mixed>  $rule
     */
    private static function countRuleYears(array $rule, int $upTo): int
    {
        if ($upTo < 1) {
            return 0;
        }

        $every = max(1, (int) ($rule['every'] ?? 1));
        $offset = (int) ($rule['offset'] ?? 0);
        $count = self::countCongruent($upTo, $every, $offset);

        $except = (int) ($rule['except_every'] ?? 0);
        if ($except > 1) {
            $count -= self::countCongruent($upTo, $except, $offset);

            $unless = (int) ($rule['unless_every'] ?? 0);
            if ($unless > 1) {
                $count += self::countCongruent($upTo, $unless, $offset);
            }
        }

        return $count;
    }

    /** Count of y in [1, $n] with y ≡ $offset (mod $mod). */
    private static function countCongruent(int $n, int $mod, int $offset): int
    {
        if ($n < 1 || $mod < 1) {
            return 0;
        }

        $residue = self::mod($offset, $mod);
        // Represent the residue as the smallest positive year that matches (mod itself when residue 0).
        $first = $residue === 0 ? $mod : $residue;

        return $first > $n ? 0 : intdiv($n - $first, $mod) + 1;
    }

    /**
     * Moon definitions, sanitised to sensible bounds for client-side phase maths.
     *
     * @return list<array{name: string, cycle: int, offset: int, colour: ?string}>
     */
    private static function moons(Calendar $calendar): array
    {
        return collect($calendar->moons ?? [])
            ->map(static fn ($moon) => [
                'name' => (string) ($moon['name'] ?? 'Moon'),
                'cycle' => max(1, (int) ($moon['cycle'] ?? 1)),
                'offset' => (int) ($moon['offset'] ?? 0),
                'colour' => isset($moon['colour']) && $moon['colour'] !== '' ? (string) $moon['colour'] : null,
            ])
            ->values()
            ->all();
    }

    /** Always-positive modulo (PHP's % keeps the sign of the dividend). */
    private static function mod(int $value, int $modulus): int
    {
        return (($value % $modulus) + $modulus) % $modulus;
    }

    private static function congruent(int $year, int $mod, int $offset): bool
    {
        return self::mod($year - $offset, $mod) === 0;
    }
}
