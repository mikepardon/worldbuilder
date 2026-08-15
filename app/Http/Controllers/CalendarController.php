<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\World;
use App\Support\CalendarMath;
use App\Support\WorldNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Custom in-world calendars — define months and weekdays, and pin events to dates. */
class CalendarController extends Controller
{
    /** A sensible starting structure (Gregorian shape) that GMs then rename/reshape. */
    private const DEFAULT_MONTHS = [
        ['name' => 'January', 'days' => 31], ['name' => 'February', 'days' => 28],
        ['name' => 'March', 'days' => 31], ['name' => 'April', 'days' => 30],
        ['name' => 'May', 'days' => 31], ['name' => 'June', 'days' => 30],
        ['name' => 'July', 'days' => 31], ['name' => 'August', 'days' => 31],
        ['name' => 'September', 'days' => 30], ['name' => 'October', 'days' => 31],
        ['name' => 'November', 'days' => 30], ['name' => 'December', 'days' => 31],
    ];

    private const DEFAULT_WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function index(Request $request, World $world): Response
    {
        $this->authorize('editContent', $world);

        $calendars = $world->calendars()->orderBy('sort')->orderBy('name')->get();
        $selected = $calendars->firstWhere('id', (int) $request->query('calendar')) ?? $calendars->first();

        $year = (int) ($request->query('year') ?? $selected?->current_year ?? 1);

        return Inertia::render('Worlds/Calendars', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'calendars' => $calendars->map(fn (Calendar $c) => [
                'id' => $c->id, 'name' => $c->name, 'current_year' => $c->current_year,
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'name' => $selected->name,
                'months' => array_values($selected->months ?? []),
                'weekdays' => array_values($selected->weekdays ?? []),
                'leap_rules' => array_values($selected->leap_rules ?? []),
                'moons' => array_values($selected->moons ?? []),
                'current_year' => $selected->current_year,
            ] : null,
            'grid' => $selected ? CalendarMath::year($selected, $year) : null,
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('editContent', $world);

        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $calendar = $world->calendars()->create([
            'name' => $data['name'],
            'months' => self::DEFAULT_MONTHS,
            'weekdays' => self::DEFAULT_WEEKDAYS,
            'current_year' => 1,
        ]);

        return redirect()->route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id]);
    }

    public function update(Request $request, Calendar $calendar): RedirectResponse
    {
        $this->authorize('manage', $calendar->world);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'current_year' => ['required', 'integer', 'min:0', 'max:1000000'],
            'months' => ['required', 'array', 'min:1', 'max:48'],
            'months.*.name' => ['required', 'string', 'max:40'],
            'months.*.days' => ['required', 'integer', 'min:1', 'max:1000'],
            'months.*.intercalary' => ['boolean'],
            'weekdays' => ['present', 'array', 'max:20'],
            'weekdays.*' => ['required', 'string', 'max:40'],
            'leap_rules' => ['nullable', 'array', 'max:20'],
            'leap_rules.*.name' => ['required', 'string', 'max:60'],
            'leap_rules.*.month' => ['required', 'integer', 'min:0'],
            'leap_rules.*.add' => ['required', 'integer', 'min:1', 'max:100'],
            'leap_rules.*.every' => ['required', 'integer', 'min:1', 'max:100000'],
            'leap_rules.*.offset' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'leap_rules.*.except_every' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'leap_rules.*.unless_every' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'moons' => ['nullable', 'array', 'max:12'],
            'moons.*.name' => ['required', 'string', 'max:60'],
            'moons.*.cycle' => ['required', 'integer', 'min:1', 'max:100000'],
            'moons.*.offset' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'moons.*.colour' => ['nullable', 'string', 'max:20'],
        ]);

        $months = array_map(fn (array $month) => [
            'name' => $month['name'],
            'days' => (int) $month['days'],
            'intercalary' => (bool) ($month['intercalary'] ?? false),
        ], $data['months']);

        // A leap rule can only target a month that exists; drop any that point past the end.
        $monthCount = count($months);
        $leapRules = collect($data['leap_rules'] ?? [])
            ->filter(fn (array $rule) => (int) $rule['month'] < $monthCount)
            ->map(fn (array $rule) => [
                'name' => $rule['name'],
                'month' => (int) $rule['month'],
                'add' => (int) $rule['add'],
                'every' => (int) $rule['every'],
                'offset' => (int) ($rule['offset'] ?? 0),
                'except_every' => (int) ($rule['except_every'] ?? 0),
                'unless_every' => (int) ($rule['unless_every'] ?? 0),
            ])
            ->values()
            ->all();

        $moons = array_map(fn (array $moon) => [
            'name' => $moon['name'],
            'cycle' => (int) $moon['cycle'],
            'offset' => (int) ($moon['offset'] ?? 0),
            'colour' => filled($moon['colour'] ?? null) ? $moon['colour'] : null,
        ], $data['moons'] ?? []);

        $calendar->update([
            'name' => $data['name'],
            'current_year' => $data['current_year'],
            'months' => $months,
            'weekdays' => array_values($data['weekdays']),
            'leap_rules' => $leapRules,
            'moons' => $moons,
        ]);

        return back()->with('success', 'Calendar saved.');
    }

    public function destroy(Request $request, Calendar $calendar): RedirectResponse
    {
        $this->authorize('manage', $calendar->world);
        $worldId = $calendar->world_id;
        $calendar->delete();

        return redirect()->route('calendars.index', ['world' => $worldId]);
    }

    public function eventStore(Request $request, Calendar $calendar): RedirectResponse
    {
        $this->authorize('manage', $calendar->world);

        $monthCount = count($calendar->months ?? []);
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:0', 'max:1000000'],
            'month' => ['required', 'integer', 'min:0', 'lt:'.max($monthCount, 1)],
            'day' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        // The day must exist within the chosen month.
        $monthDays = (int) ($calendar->months[$data['month']]['days'] ?? 0);
        if ($data['day'] > $monthDays) {
            return back()->with('error', 'That day is beyond the end of the month.');
        }

        $calendar->events()->create($data);

        return back()->with('success', 'Event added.');
    }

    public function eventDestroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        $this->authorize('manage', $event->calendar->world);
        $event->delete();

        return back()->with('success', 'Event removed.');
    }
}
