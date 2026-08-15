<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function world(User $gm): World
    {
        return $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
    }

    public function test_the_owner_can_open_world_settings(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);

        $this->actingAs($gm)->get(route('worlds.settings', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/Settings')
                ->where('settings.visibility', 'private')
                ->where('settings.default_campaign_visibility', 'private'));
    }

    public function test_a_stranger_cannot_open_world_settings(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $this->world($gm);

        $this->actingAs($stranger)->get(route('worlds.settings', $world))->assertForbidden();
    }

    public function test_saving_identity_and_visibility_updates_the_world(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'name' => 'Renamed World',
            'visibility' => 'public',
        ])->assertRedirect();

        $fresh = $world->fresh();
        $this->assertSame('Renamed World', $fresh->name);
        $this->assertSame('public', $fresh->visibility);
    }

    public function test_default_campaign_settings_persist_to_the_settings_bag(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'default_campaign_visibility' => 'public',
            'default_session_private' => true,
        ])->assertRedirect();

        $fresh = $world->fresh();
        $this->assertSame('public', $fresh->defaultCampaignVisibility());
        $this->assertTrue($fresh->newSessionsPrivate());
    }

    public function test_an_invalid_default_visibility_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'default_campaign_visibility' => 'everyone',
        ])->assertSessionHasErrors('default_campaign_visibility');
    }

    public function test_a_new_campaign_inherits_the_world_default_visibility(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $world->update(['settings' => ['default_campaign_visibility' => 'public']]);

        $this->actingAs($gm)->post(route('campaigns.store', $world), ['name' => 'Fresh Campaign'])->assertRedirect();

        $campaign = $world->campaigns()->where('name', 'Fresh Campaign')->firstOrFail();
        $this->assertSame('public', $campaign->visibility);
    }

    public function test_a_new_session_inherits_the_world_default_privacy(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $world->update(['settings' => ['default_session_private' => true]]);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->post(route('sessions.store', $campaign), ['title' => 'Opening Night'])->assertRedirect();

        $this->assertTrue($campaign->sessions()->firstOrFail()->is_private);
    }

    public function test_a_manager_can_open_campaign_settings(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->get(route('campaigns.settings', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Settings')
                ->where('campaign.visibility', 'private'));
    }

    public function test_a_stranger_cannot_open_campaign_settings(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $this->world($gm);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($stranger)->get(route('campaigns.settings', [$world, $campaign]))->assertForbidden();
    }

    public function test_saving_campaign_settings_updates_its_visibility(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->put(route('campaigns.update', $campaign), ['visibility' => 'public'])->assertRedirect();

        $this->assertSame('public', $campaign->fresh()->visibility);
    }
}
