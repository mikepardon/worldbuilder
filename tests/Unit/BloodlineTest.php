<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Bloodline;
use PHPUnit\Framework\TestCase;

class BloodlineTest extends TestCase
{
    public function test_builds_members_and_drops_bad_parent_references(): void
    {
        $doc = (object) ['data' => ['members' => [
            ['id' => 'a', 'name' => 'Zhikuvar', 'subtitle' => 'The Savage', 'parents' => []],
            ['id' => 'b', 'name' => 'Alya', 'parents' => ['a', 'ghost', 'b']],
        ]]];

        $members = Bloodline::members($doc);

        $this->assertCount(2, $members);
        $this->assertSame('The Savage', $members[0]['subtitle']);
        // A legacy bare-id parent normalises to biological; "ghost" and the self-reference drop.
        $this->assertSame([['id' => 'a', 'type' => 'biological']], $members[1]['parents']);
    }

    public function test_keeps_parent_types_and_partner_links(): void
    {
        $doc = (object) ['data' => ['members' => [
            ['id' => 'a', 'name' => 'Steve', 'partners' => ['b', 'ghost']],
            ['id' => 'b', 'name' => 'Alya'],
            ['id' => 'c', 'name' => 'Kid', 'parents' => [
                ['id' => 'a', 'type' => 'adopted'],
                ['id' => 'b', 'type' => 'nonsense'],
            ]],
        ]]];

        $members = Bloodline::members($doc);

        $this->assertSame(['b'], $members[0]['partners']); // "ghost" dropped
        $this->assertSame([
            ['id' => 'a', 'type' => 'adopted'],
            ['id' => 'b', 'type' => 'biological'], // unknown type falls back
        ], $members[2]['parents']);
    }

    public function test_resolves_a_member_link_only_when_the_target_is_visible(): void
    {
        $doc = (object) ['data' => ['members' => [
            ['id' => 'a', 'name' => 'Alya', 'link' => 5],
            ['id' => 'b', 'name' => 'Hidden', 'link' => 9],
        ]]];
        $linkMap = [5 => ['type' => 'person', 'slug' => 'alya', 'title' => 'Alya']];

        $members = Bloodline::members($doc, $linkMap);

        $this->assertSame('alya', $members[0]['link']['slug']);
        $this->assertNull($members[1]['link']);
    }

    public function test_returns_an_empty_list_when_there_are_no_members(): void
    {
        $this->assertSame([], Bloodline::members((object) ['data' => []]));
    }
}
