<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Calendar;
use App\Support\CalendarMath;
use Tests\TestCase;

/**
 * Note: these instantiate an unsaved Calendar purely to exercise the pure day-maths in CalendarMath.
 * The one place that touches the database (the events relation) resolves to an empty collection for a
 * calendar with no key, so no RefreshDatabase is needed.
 */
class CalendarMathTest extends TestCase
{
    private function calendar(array $attributes): Calendar
    {
        return new Calendar(array_merge([
            'name' => 'Test',
            'weekdays' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'], // 7-day week
            'current_year' => 1,
        ], $attributes));
    }

    private function gregorian(): Calendar
    {
        return $this->calendar([
            'months' => [
                ['name' => 'Jan', 'days' => 31], ['name' => 'Feb', 'days' => 28],
                ['name' => 'Mar', 'days' => 31], ['name' => 'Apr', 'days' => 30],
                ['name' => 'May', 'days' => 31], ['name' => 'Jun', 'days' => 30],
                ['name' => 'Jul', 'days' => 31], ['name' => 'Aug', 'days' => 31],
                ['name' => 'Sep', 'days' => 30], ['name' => 'Oct', 'days' => 31],
                ['name' => 'Nov', 'days' => 30], ['name' => 'Dec', 'days' => 31],
            ],
            'leap_rules' => [[
                'name' => 'Leap Day', 'month' => 1, 'add' => 1,
                'every' => 4, 'offset' => 0, 'except_every' => 100, 'unless_every' => 400,
            ]],
        ]);
    }

    public function test_a_leap_rule_adds_a_day_to_its_month_on_the_cycle_year(): void
    {
        $grid = CalendarMath::year($this->gregorian(), 2024);

        $this->assertSame(29, $grid['months'][1]['days']);
        $this->assertSame(366, $grid['yearLength']);
    }

    public function test_a_leap_rule_does_not_fire_outside_the_cycle(): void
    {
        $grid = CalendarMath::year($this->gregorian(), 2023);

        $this->assertSame(28, $grid['months'][1]['days']);
        $this->assertSame(365, $grid['yearLength']);
    }

    public function test_the_century_exception_suppresses_a_leap_year(): void
    {
        $this->assertSame(28, CalendarMath::year($this->gregorian(), 1900)['months'][1]['days']);
    }

    public function test_the_four_hundred_rule_re_enables_a_suppressed_leap_year(): void
    {
        $this->assertSame(29, CalendarMath::year($this->gregorian(), 2000)['months'][1]['days']);
    }

    public function test_days_before_a_distant_year_account_for_every_leap_day(): void
    {
        // Years 1..2000 hold 500 - 20 + 5 = 485 Gregorian leap days, so year 2001 starts here.
        $expected = 2000 * 365 + 485;

        $this->assertSame($expected, CalendarMath::year($this->gregorian(), 2001)['months'][0]['firstAbsoluteDay']);
    }

    public function test_intercalary_days_never_shift_the_weekday_cycle(): void
    {
        $withFestival = $this->calendar([
            'months' => [
                ['name' => 'First', 'days' => 10],
                ['name' => 'Highfest', 'days' => 5, 'intercalary' => true],
                ['name' => 'Second', 'days' => 10],
            ],
        ]);
        $withoutFestival = $this->calendar([
            'months' => [
                ['name' => 'First', 'days' => 10],
                ['name' => 'Second', 'days' => 10],
            ],
        ]);

        // "Second" begins on the same weekday whether or not five festival days precede it.
        $second = CalendarMath::year($withFestival, 1)['months'][2];
        $this->assertSame(3, $second['firstWeekday']); // 10 non-festival days elapsed, 10 % 7 = 3
        $this->assertSame(3, CalendarMath::year($withoutFestival, 1)['months'][1]['firstWeekday']);
    }

    public function test_intercalary_days_still_advance_the_absolute_day_count_for_moons(): void
    {
        $calendar = $this->calendar([
            'months' => [
                ['name' => 'First', 'days' => 10],
                ['name' => 'Highfest', 'days' => 5, 'intercalary' => true],
                ['name' => 'Second', 'days' => 10],
            ],
        ]);

        $grid = CalendarMath::year($calendar, 1);

        $this->assertSame(0, $grid['months'][0]['firstAbsoluteDay']);
        $this->assertSame(10, $grid['months'][1]['firstAbsoluteDay']);
        $this->assertSame(15, $grid['months'][2]['firstAbsoluteDay']); // festival days are counted here
    }

    public function test_the_first_month_of_year_one_starts_on_the_first_weekday(): void
    {
        $grid = CalendarMath::year($this->gregorian(), 1);

        $this->assertSame(0, $grid['months'][0]['firstWeekday']);
        $this->assertSame(0, $grid['months'][0]['firstAbsoluteDay']);
        $this->assertSame(3, $grid['months'][1]['firstWeekday']); // 31 % 7 = 3
    }

    public function test_moons_are_passed_through_with_sanitised_bounds(): void
    {
        $calendar = $this->calendar([
            'months' => [['name' => 'Only', 'days' => 30]],
            'moons' => [
                ['name' => 'Pale Sister', 'cycle' => 28, 'offset' => 3, 'colour' => '#cfd8ff'],
                ['name' => 'Broken', 'cycle' => 0, 'offset' => 0], // cycle floored to 1
            ],
        ]);

        $moons = CalendarMath::year($calendar, 1)['moons'];

        $this->assertSame('Pale Sister', $moons[0]['name']);
        $this->assertSame(28, $moons[0]['cycle']);
        $this->assertSame(3, $moons[0]['offset']);
        $this->assertSame('#cfd8ff', $moons[0]['colour']);
        $this->assertSame(1, $moons[1]['cycle']);
        $this->assertNull($moons[1]['colour']);
    }

    public function test_a_calendar_with_no_weekdays_reports_zero_first_weekday(): void
    {
        $calendar = $this->calendar([
            'weekdays' => [],
            'months' => [['name' => 'Only', 'days' => 30]],
        ]);

        $grid = CalendarMath::year($calendar, 5);

        $this->assertSame([], $grid['weekdays']);
        $this->assertSame(0, $grid['months'][0]['firstWeekday']);
        $this->assertSame(120, $grid['months'][0]['firstAbsoluteDay']); // 4 prior years × 30 days
    }
}
