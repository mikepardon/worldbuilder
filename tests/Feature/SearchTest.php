<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function world(): array
    {
        $owner = User::factory()->create();
        $campaign = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Rusty Anchor', 'kind' => 'location', 'content' => 'A tavern by the docks.']);
        $campaign->compendiumItems()->create(['item_type' => 'monster', 'slug' => 'rust-monster', 'name' => 'Rust Monster', 'summary' => 'Eats metal', 'provider' => 'custom']);

        return [$owner, $campaign];
    }

    public function test_it_finds_both_entries_and_compendium_items(): void
    {
        [$owner, $campaign] = $this->world();

        $this->actingAs($owner)
            ->get(route('search.index', [$campaign, 'q' => 'rust']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Index')
                ->has('entries', 1)
                ->where('entries.0.title', 'The Rusty Anchor')
                ->has('items', 1)
                ->where('items.0.name', 'Rust Monster'));
    }

    public function test_it_searches_entry_content_case_insensitively(): void
    {
        [$owner, $campaign] = $this->world();

        $this->actingAs($owner)
            ->get(route('search.index', [$campaign, 'q' => 'TAVERN']))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->has('items', 0));
    }

    public function test_a_blank_query_returns_no_results(): void
    {
        [$owner, $campaign] = $this->world();

        $this->actingAs($owner)
            ->get(route('search.index', $campaign))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 0)->has('items', 0));
    }

    public function test_a_non_owner_cannot_search_the_world(): void
    {
        [, $campaign] = $this->world();

        $this->actingAs(User::factory()->create())
            ->get(route('search.index', [$campaign, 'q' => 'rust']))
            ->assertForbidden();
    }
}
