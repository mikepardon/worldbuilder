<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReaderPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_bounced_to_the_unlock_screen_on_a_gated_world(): void
    {
        $world = $this->gatedWorld();

        $this->get(route('public.world', $world))
            ->assertRedirect(route('public.world.locked', $world));
    }

    public function test_the_correct_password_unlocks_the_world_for_the_session(): void
    {
        $world = $this->gatedWorld();

        $this->post(route('public.world.unlock', $world), ['password' => 'sea-secret'])
            ->assertRedirect(route('public.world', $world));

        $this->get(route('public.world', $world))->assertOk();
    }

    public function test_a_wrong_password_is_rejected_and_keeps_the_world_locked(): void
    {
        $world = $this->gatedWorld();

        $this->post(route('public.world.unlock', $world), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->get(route('public.world', $world))
            ->assertRedirect(route('public.world.locked', $world));
    }

    public function test_the_owner_bypasses_the_reader_password(): void
    {
        $owner = User::factory()->create();
        $world = $this->gatedWorld($owner);

        $this->actingAs($owner)->get(route('public.world', $world))->assertOk();
    }

    public function test_a_campaign_member_bypasses_the_reader_password(): void
    {
        $world = $this->gatedWorld();
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'hidden']);
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($player)->get(route('public.world', $world))->assertOk();
    }

    public function test_a_public_world_is_never_gated_by_a_reader_password(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'reader_password' => 'sea-secret',
        ]);

        $this->get(route('public.world', $world))->assertOk();
    }

    private function gatedWorld(?User $owner = null): World
    {
        $owner ??= User::factory()->create();

        return $owner->worlds()->create([
            'name' => 'Saltmere', 'visibility' => 'hidden', 'reader_password' => 'sea-secret',
        ]);
    }
}
