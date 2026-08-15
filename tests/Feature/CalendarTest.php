<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function ownerWithWorld(): array
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        return [$owner, $world];
    }

    private function twoMonthCalendar(World $world): Calendar
    {
        return $world->calendars()->create([
            'name' => 'Tidal Reckoning',
            'months' => [['name' => 'First', 'days' => 10], ['name' => 'Second', 'days' => 10]],
            'weekdays' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'], // 7-day week
            'current_year' => 1,
        ]);
    }

    public function test_creating_a_calendar_seeds_a_default_structure(): void
    {
        [$owner, $world] = $this->ownerWithWorld();

        $this->actingAs($owner)
            ->post(route('calendars.store', $world), ['name' => 'The Reckoning'])
            ->assertRedirect();

        $calendar = Calendar::sole();
        $this->assertSame('The Reckoning', $calendar->name);
        $this->assertCount(12, $calendar->months);
        $this->assertCount(7, $calendar->weekdays);
    }

    public function test_the_grid_computes_weekday_offsets_within_a_year(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world);

        // Year 1: month 0 starts on weekday 0; month 1 starts after 10 days → 10 % 7 = 3.
        $this->actingAs($owner)
            ->get(route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id, 'year' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/Calendars')
                ->where('grid.year', 1)
                ->where('grid.months.0.firstWeekday', 0)
                ->where('grid.months.1.firstWeekday', 3));
    }

    public function test_the_grid_carries_weekday_offsets_across_years(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world); // year length = 20

        // Year 2 starts 20 days in → 20 % 7 = 6; month 1 → 30 % 7 = 2.
        $this->actingAs($owner)
            ->get(route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id, 'year' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.months.0.firstWeekday', 6)
                ->where('grid.months.1.firstWeekday', 2));
    }

    public function test_the_structure_can_be_edited(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world);

        $this->actingAs($owner)->put(route('calendars.update', $calendar), [
            'name' => 'Renamed',
            'current_year' => 1204,
            'months' => [['name' => 'Frostmoon', 'days' => 40]],
            'weekdays' => ['One', 'Two'],
        ])->assertRedirect();

        $fresh = $calendar->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(1204, $fresh->current_year);
        $this->assertSame(40, $fresh->months[0]['days']);
        $this->assertCount(2, $fresh->weekdays);
    }

    public function test_an_event_can_be_pinned_to_a_date_and_removed(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world);

        $this->actingAs($owner)->post(route('calendars.events.store', $calendar), [
            'year' => 1, 'month' => 1, 'day' => 5, 'title' => 'The Drowning',
        ])->assertRedirect();

        $this->assertDatabaseHas('calendar_events', [
            'calendar_id' => $calendar->id, 'year' => 1, 'month' => 1, 'day' => 5, 'title' => 'The Drowning',
        ]);

        // It shows up on that month in the grid.
        $this->actingAs($owner)
            ->get(route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id, 'year' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('grid.months.1.events.0.title', 'The Drowning'));

        $event = $calendar->events()->sole();
        $this->actingAs($owner)->delete(route('calendars.events.destroy', $event))->assertRedirect();
        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
    }

    public function test_an_event_beyond_the_end_of_the_month_is_rejected(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world); // months have 10 days

        $this->actingAs($owner)->post(route('calendars.events.store', $calendar), [
            'year' => 1, 'month' => 0, 'day' => 11, 'title' => 'Nope',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_a_co_author_can_manage_calendars(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);

        $this->actingAs($editor)
            ->post(route('calendars.store', $world), ['name' => 'Co-authored'])
            ->assertRedirect();

        $this->assertDatabaseHas('calendars', ['world_id' => $world->id, 'name' => 'Co-authored']);
    }

    public function test_a_stranger_cannot_manage_calendars(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('calendars.index', $world))->assertForbidden();
        $this->actingAs($stranger)->post(route('calendars.store', $world), ['name' => 'X'])->assertForbidden();
    }

    public function test_a_leap_rule_lengthens_its_month_on_cycle_years(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world);

        $this->actingAs($owner)->put(route('calendars.update', $calendar), [
            'name' => 'Tidal Reckoning',
            'current_year' => 1,
            'months' => [['name' => 'First', 'days' => 10], ['name' => 'Second', 'days' => 10]],
            'weekdays' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
            'leap_rules' => [['name' => 'Deepening', 'month' => 1, 'add' => 2, 'every' => 3, 'offset' => 0]],
        ])->assertRedirect();

        // Year 3 is a cycle year: Second gains 2 days (12); year length 22. Year 2 is untouched (20).
        $this->actingAs($owner)
            ->get(route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id, 'year' => 3]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.months.1.days', 12)
                ->where('grid.yearLength', 22));

        $this->actingAs($owner)
            ->get(route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id, 'year' => 2]))
            ->assertInertia(fn (Assert $page) => $page->where('grid.yearLength', 20));
    }

    public function test_a_leap_rule_targeting_a_missing_month_is_dropped(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world);

        $this->actingAs($owner)->put(route('calendars.update', $calendar), [
            'name' => 'Tidal Reckoning',
            'current_year' => 1,
            'months' => [['name' => 'Only', 'days' => 10]],
            'weekdays' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
            'leap_rules' => [['name' => 'Ghost', 'month' => 5, 'add' => 1, 'every' => 4, 'offset' => 0]],
        ])->assertRedirect();

        $this->assertSame([], $calendar->fresh()->leap_rules);
    }

    public function test_moons_and_intercalary_months_are_saved_and_reflected_in_the_grid(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $calendar = $this->twoMonthCalendar($world);

        $this->actingAs($owner)->put(route('calendars.update', $calendar), [
            'name' => 'Tidal Reckoning',
            'current_year' => 1,
            'months' => [
                ['name' => 'First', 'days' => 10],
                ['name' => 'Highfest', 'days' => 3, 'intercalary' => true],
                ['name' => 'Second', 'days' => 10],
            ],
            'weekdays' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
            'moons' => [['name' => 'Pale Sister', 'cycle' => 28, 'offset' => 3, 'colour' => '#c9d4ff']],
        ])->assertRedirect();

        $fresh = $calendar->fresh();
        $this->assertTrue($fresh->months[1]['intercalary']);
        $this->assertSame(28, $fresh->moons[0]['cycle']);

        // The festival month does not shift the weekday cycle: "Second" still starts as if only the
        // first 10 non-festival days preceded it (10 % 7 = 3).
        $this->actingAs($owner)
            ->get(route('calendars.index', ['world' => $world->id, 'calendar' => $calendar->id, 'year' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.months.1.intercalary', true)
                ->where('grid.months.2.firstWeekday', 3)
                ->where('grid.moons.0.name', 'Pale Sister'));
    }
}
