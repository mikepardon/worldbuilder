<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\World;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_user_can_set_a_custom_domain(): void
    {
        $user = User::factory()->create(['plan' => 'basic']);
        $world = $user->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($user)
            ->put(route('worlds.domain.update', $world), ['custom_domain' => 'World.Example.com'])
            ->assertRedirect();

        $fresh = $world->fresh();
        $this->assertSame('world.example.com', $fresh->custom_domain); // normalised to lowercase
        $this->assertNull($fresh->custom_domain_verified_at);
    }

    public function test_a_free_user_cannot_set_a_custom_domain(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $world = $user->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($user)
            ->put(route('worlds.domain.update', $world), ['custom_domain' => 'world.example.com'])
            ->assertSessionHas('error');

        $this->assertNull($world->fresh()->custom_domain);
    }

    public function test_an_invalid_domain_is_rejected(): void
    {
        $user = User::factory()->create(['plan' => 'pro']);
        $world = $user->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($user)
            ->put(route('worlds.domain.update', $world), ['custom_domain' => 'not a domain'])
            ->assertSessionHasErrors('custom_domain');
    }

    public function test_a_domain_already_used_by_another_world_is_rejected(): void
    {
        $other = User::factory()->create(['plan' => 'pro']);
        $other->worlds()->create(['name' => 'Taken', 'visibility' => 'public', 'custom_domain' => 'world.example.com']);

        $user = User::factory()->create(['plan' => 'pro']);
        $world = $user->worlds()->create(['name' => 'Mine', 'visibility' => 'public']);

        $this->actingAs($user)
            ->put(route('worlds.domain.update', $world), ['custom_domain' => 'world.example.com'])
            ->assertSessionHasErrors('custom_domain');
    }

    public function test_verifying_marks_the_domain_connected_when_the_a_record_matches(): void
    {
        config(['domains.ip' => '203.0.113.10']);
        $user = User::factory()->create(['plan' => 'basic']);
        $world = $user->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'custom_domain' => 'world.example.com',
        ]);

        $this->mock(DnsResolver::class, function ($mock) {
            $mock->shouldReceive('aRecords')->once()->andReturn(['203.0.113.10']);
        });

        $this->actingAs($user)
            ->post(route('worlds.domain.verify', $world))
            ->assertSessionHas('success');

        $this->assertNotNull($world->fresh()->custom_domain_verified_at);
    }

    public function test_verifying_fails_when_the_a_record_does_not_match(): void
    {
        config(['domains.ip' => '203.0.113.10']);
        $user = User::factory()->create(['plan' => 'basic']);
        $world = $user->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'custom_domain' => 'world.example.com',
        ]);

        $this->mock(DnsResolver::class, function ($mock) {
            $mock->shouldReceive('aRecords')->once()->andReturn(['198.51.100.1']);
        });

        $this->actingAs($user)
            ->post(route('worlds.domain.verify', $world))
            ->assertSessionHas('error');

        $this->assertNull($world->fresh()->custom_domain_verified_at);
    }

    public function test_a_verified_custom_domain_serves_the_world_at_the_root(): void
    {
        $user = User::factory()->create(['plan' => 'basic']);
        $user->worlds()->create([
            'name' => 'Saltmere',
            'visibility' => 'public',
            'custom_domain' => 'world.example.com',
            'custom_domain_verified_at' => now(),
        ]);

        $this->get('http://world.example.com/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Campaign')
                ->where('campaign.name', 'Saltmere'));
    }

    public function test_an_unverified_custom_domain_does_not_serve_the_world(): void
    {
        $user = User::factory()->create(['plan' => 'basic']);
        $user->worlds()->create([
            'name' => 'Saltmere',
            'visibility' => 'public',
            'custom_domain' => 'world.example.com',
            'custom_domain_verified_at' => null,
        ]);

        // Falls through to the marketing landing rather than the world.
        $this->get('http://world.example.com/')
            ->assertInertia(fn (Assert $page) => $page->component('Home'));
    }

    public function test_removing_a_custom_domain_clears_it(): void
    {
        $user = User::factory()->create(['plan' => 'basic']);
        $world = $user->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'custom_domain' => 'world.example.com', 'custom_domain_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('worlds.domain.destroy', $world))
            ->assertRedirect();

        $fresh = $world->fresh();
        $this->assertNull($fresh->custom_domain);
        $this->assertNull($fresh->custom_domain_verified_at);
    }
}
