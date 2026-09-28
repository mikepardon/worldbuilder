<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldWheelTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_kind_belongs_to_exactly_one_family(): void
    {
        $grouped = collect(Sections::families())->pluck('kinds')->flatten();

        $this->assertEqualsCanonicalizing(Sections::KINDS, $grouped->all());
        $this->assertSame($grouped->count(), $grouped->unique()->count());
    }

    public function test_kinds_without_a_reader_section_fall_into_the_other_family(): void
    {
        $other = collect(Sections::families())->firstWhere('slug', 'other');

        $this->assertNotNull($other);
        $this->assertEqualsCanonicalizing(['spell', 'statblock'], $other['kinds']);
    }

    public function test_people_groups_folk_factions_and_bloodlines(): void
    {
        $people = collect(Sections::families())->firstWhere('slug', 'people');

        $this->assertNotNull($people);
        $this->assertSame(['npc', 'faction', 'bloodline'], $people['kinds']);
    }

    public function test_the_world_web_page_exposes_the_family_grouping(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Glieda', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.web', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/Web')
                ->has('graph.nodes')
                ->has('families', count(Sections::SECTIONS) + 1)
                ->where('families.2.slug', 'people')
                ->where('families.2.kinds', fn ($kinds) => collect($kinds)->contains('npc')));
    }
}
