<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wizard_page_renders_with_system_and_feature_options(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('worlds.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/Create')
                ->has('systemOptions')
                ->has('featureOptions'));
    }

    public function test_the_wizard_creates_a_world_with_its_answers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('worlds.store'), [
            'name' => 'The Sundered Coast',
            'audience' => 'players',
            'features' => ['campaigns', 'vtt'],
            'game_system' => 'D&D 5e',
            'house_rules' => 'Crits deal max damage.',
            'setting' => 'A drowned coast of salt-pans and smuggler ports.',
            'visibility' => 'private',
        ])->assertRedirect();

        $world = $user->worlds()->firstOrFail();
        $this->assertSame('The Sundered Coast', $world->name);
        $this->assertSame('A drowned coast of salt-pans and smuggler ports.', $world->setting);
        $this->assertSame('private', $world->visibility);
        $this->assertSame('D&D 5e', data_get($world->settings, 'default_game_system'));
        $this->assertSame('Crits deal max damage.', data_get($world->settings, 'house_rules'));
        $this->assertSame('players', data_get($world->settings, 'audience'));
        $this->assertSame(['campaigns', 'vtt'], data_get($world->settings, 'features'));
        // Inviting players → members join as players by default.
        $this->assertSame('player', data_get($world->settings, 'default_join_role'));
    }

    public function test_an_unknown_feature_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('worlds.create'))
            ->post(route('worlds.store'), ['name' => 'W', 'features' => ['not-a-feature']])
            ->assertSessionHasErrors('features.0');

        $this->assertSame(0, $user->worlds()->count());
    }

    public function test_an_empty_world_offers_a_kickstart_a_populated_one_does_not(): void
    {
        $user = User::factory()->create();
        $world = $user->worlds()->create(['name' => 'Empty', 'visibility' => 'private']);

        $this->actingAs($user)->get(route('worlds.show', $world))
            ->assertInertia(fn (Assert $page) => $page->where('kickstart.show', true));

        $world->documents()->create(['user_id' => $user->id, 'title' => 'A', 'slug' => 'a', 'kind' => 'location', 'is_private' => false]);

        $this->actingAs($user)->get(route('worlds.show', $world))
            ->assertInertia(fn (Assert $page) => $page->where('kickstart.show', false));
    }

    public function test_the_setup_checklist_reflects_a_fresh_world(): void
    {
        $user = User::factory()->create();
        $world = $user->worlds()->create(['name' => 'Fresh', 'visibility' => 'private']);

        $this->actingAs($user)->get(route('worlds.show', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.complete', false)
                ->where('setup.done', 0)
                ->where('setup.total', 5)
                ->where('setup.steps.0.key', 'entry')
                ->where('setup.steps.0.done', false));
    }

    public function test_the_setup_checklist_ticks_off_the_first_entry(): void
    {
        $user = User::factory()->create();
        $world = $user->worlds()->create(['name' => 'Fresh', 'visibility' => 'private']);
        $world->documents()->create(['user_id' => $user->id, 'title' => 'A', 'slug' => 'a', 'kind' => 'location', 'is_private' => false]);

        $this->actingAs($user)->get(route('worlds.show', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.done', 1)
                ->where('setup.steps.0.key', 'entry')
                ->where('setup.steps.0.done', true));
    }

    public function test_the_setup_checklist_completes_when_every_step_is_done(): void
    {
        $user = User::factory()->create();
        $collaborator = User::factory()->create();
        $world = $user->worlds()->create(['name' => 'Ready', 'visibility' => 'private']);
        $logo = Media::create([
            'user_id' => $user->id, 'disk' => 'public', 'path' => 'logos/x.png', 'filename' => 'x.png',
        ]);
        $world->update(['logo_media_id' => $logo->id]);

        $world->documents()->create(['user_id' => $user->id, 'title' => 'A', 'slug' => 'a', 'kind' => 'location', 'is_private' => false]);
        // Worlds auto-create a "Main Campaign" on creation; run a session on it.
        $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session One', 'slug' => 'session-one']);
        $world->compendiumItems()->create(['user_id' => $user->id, 'item_type' => 'item', 'slug' => 'longsword', 'name' => 'Longsword']);
        $world->members()->create(['user_id' => $collaborator->id, 'role' => 'editor']);

        $this->actingAs($user)->get(route('worlds.show', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.complete', true)
                ->where('setup.done', 5));
    }
}
