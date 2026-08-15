<?php

namespace Tests\Unit;

use App\Support\Chronicle;
use PHPUnit\Framework\TestCase;

class ChronicleTest extends TestCase
{
    public function test_parses_year_with_bc_and_undated(): void
    {
        $this->assertSame(1204, Chronicle::parseYear('1204 DR'));
        $this->assertSame(-500, Chronicle::parseYear('500 BC'));
        $this->assertSame(-120, Chronicle::parseYear('120 BCE'));
        $this->assertSame(PHP_INT_MAX, Chronicle::parseYear('long ago'));
    }

    public function test_parses_table_rows(): void
    {
        $content = "# History\n\n| Year | Event | Consequence |\n| --- | --- | --- |\n| 1204 | The Sundering | Seas rose |\n\n## Notes\n- ignored";
        $rows = Chronicle::parseTimelineTable($content);
        $this->assertCount(1, $rows);
        $this->assertSame(['when' => '1204', 'title' => 'The Sundering', 'detail' => 'Seas rose'], $rows[0]);
    }

    public function test_builds_ages_sorted_with_events_oldest_first(): void
    {
        $docs = [
            (object) ['id' => 'b', 'title' => 'Second', 'slug' => 'second', 'kind' => 'timeline', 'summary' => '',
                'data' => ['events' => [['when' => '880', 'title' => 'A war', 'detail' => '']]]],
            (object) ['id' => 'a', 'title' => 'First', 'slug' => 'first', 'kind' => 'timeline', 'summary' => '',
                'data' => ['events' => [
                    ['when' => '500', 'title' => 'A fall', 'detail' => ''],
                    ['when' => '120', 'title' => 'A founding', 'detail' => ''],
                ]]],
        ];
        $ages = Chronicle::build($docs);

        // Ages sort by their earliest event, events within an age sort oldest first.
        $this->assertSame(['First', 'Second'], array_column($ages, 'title'));
        $this->assertSame(['120', '500'], array_column($ages[0]['events'], 'when'));
        $this->assertSame('A founding', $ages[0]['events'][0]['title']);
    }

    public function test_resolves_an_event_link_only_when_the_target_is_visible(): void
    {
        $docs = [(object) ['id' => 't', 'title' => 'Age', 'slug' => 'age', 'kind' => 'timeline', 'summary' => '',
            'data' => ['events' => [
                ['when' => '1', 'title' => 'Visible', 'detail' => '', 'link' => 5],
                ['when' => '2', 'title' => 'Hidden', 'detail' => '', 'link' => 9],
            ]]]];
        $linkMap = [5 => ['type' => 'person', 'slug' => 'merrow', 'title' => 'Lady Merrow']];

        $events = Chronicle::build($docs, $linkMap)[0]['events'];

        $this->assertSame(['type' => 'person', 'slug' => 'merrow', 'title' => 'Lady Merrow'], $events[0]['link']);
        // An id absent from the visible map never resolves — no private target leaks.
        $this->assertNull($events[1]['link']);
    }

    public function test_a_legacy_table_timeline_still_produces_events(): void
    {
        $docs = [(object) ['id' => 'a', 'title' => 'First', 'slug' => 'first', 'kind' => 'timeline', 'summary' => '',
            'data' => [], 'content' => "| Year | Event |\n| --- | --- |\n| 120 | A founding |"]];

        $ages = Chronicle::build($docs);

        $this->assertCount(1, $ages);
        $this->assertSame(['120'], array_column($ages[0]['events'], 'when'));
        $this->assertSame('A founding', $ages[0]['events'][0]['title']);
    }

    public function test_uses_the_document_itself_when_it_has_no_events(): void
    {
        $docs = [(object) ['id' => 'x', 'title' => 'The Long Night', 'slug' => 'night', 'kind' => 'timeline',
            'summary' => 'Darkness fell.', 'data' => ['span' => '1000 DR'], 'content' => '']];

        $ages = Chronicle::build($docs);

        $this->assertCount(1, $ages);
        $this->assertSame('1000 DR', $ages[0]['span']);
        $this->assertSame('The Long Night', $ages[0]['events'][0]['title']);
        $this->assertSame(1000, $ages[0]['events'][0]['sort']);
    }
}
