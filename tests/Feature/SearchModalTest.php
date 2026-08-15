<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_live_search_returns_grouped_matches(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'The Amber Temple', 'slug' => 'the-amber-temple',
            'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);

        $this->actingAs($gm)->getJson(route('search.query', $world).'?q=Amber')
            ->assertOk()
            ->assertJsonPath('groups.0.items.0.title', 'The Amber Temple');
    }

    public function test_a_short_query_returns_no_groups(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->getJson(route('search.query', $world).'?q=a')
            ->assertOk()
            ->assertExactJson(['groups' => []]);
    }

    public function test_a_stranger_cannot_search_a_world(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($stranger)->getJson(route('search.query', $world).'?q=Amber')->assertForbidden();
    }
}
