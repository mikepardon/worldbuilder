<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_gm_can_view_their_campaigns_and_dashboard(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Test World', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->get(route('campaigns.index', $world))->assertOk();
        $this->actingAs($gm)->get(route('campaigns.show', [$world, $campaign]))->assertOk();
    }

    public function test_non_owner_cannot_open_gm_dashboard(): void
    {
        $gm = User::factory()->create();
        $other = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($other)->get(route('campaigns.show', [$world, $campaign]))->assertForbidden();
    }

    public function test_public_reader_404s_private_entries_and_strips_secrets(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $public = $world->documents()->create([
            'title' => 'Pub', 'slug' => 'pub', 'kind' => 'location',
            'content' => 'Open text {{secret}}hidden truth{{/}} more.', 'is_private' => false,
        ]);
        $private = $world->documents()->create([
            'title' => 'Priv', 'slug' => 'priv', 'kind' => 'lore', 'content' => 'x', 'is_private' => true,
        ]);

        $this->get(route('public.world', $world))->assertOk();
        $this->get(route('public.article', [$world, 'lore', $private->slug]))->assertNotFound();
        $this->get(route('public.article', [$world, 'location', $public->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Article')
                ->where('entry.content', fn ($c) => ! str_contains($c, 'hidden truth')));
    }

    public function test_private_campaign_is_not_publicly_readable(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Secret', 'visibility' => 'private']);

        $this->get(route('public.world', $world))->assertNotFound();
    }

    public function test_admin_area_is_gated(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }
}
