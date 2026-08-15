<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_entry_in_a_valid_category_succeeds(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($owner)
            ->post(route('documents.store', $world), ['title' => 'Saltmere Docks', 'kind' => 'location'])
            ->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'world_id' => $world->id, 'kind' => 'location', 'title' => 'Saltmere Docks',
        ]);
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($owner)
            ->post(route('documents.store', $world), ['title' => 'Bad', 'kind' => 'not-a-kind'])
            ->assertSessionHasErrors('kind');

        $this->assertDatabaseMissing('documents', ['title' => 'Bad']);
    }

    public function test_the_world_dashboard_exposes_the_category_catalogue(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($owner)
            ->get(route('worlds.entries', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/Entries')
                ->has('categories', count(Sections::KINDS))
                ->where('categories.0.kind', Sections::KINDS[0])
            );
    }
}
