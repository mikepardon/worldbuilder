<?php

namespace Tests\Feature;

use App\Mail\CampaignInviteMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_join_a_public_world_by_code(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->update(['code' => 'pub001']);
        $player = User::factory()->create();

        $this->actingAs($player)->post(route('join.store', 'pub001'))->assertRedirect('/w/'.$world->slug);
        $this->assertTrue($campaign->fresh()->hasMember($player));
    }

    public function test_a_user_cannot_join_a_private_world_by_code(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->update(['code' => 'prv001']);
        $player = User::factory()->create();

        $this->actingAs($player)->post(route('join.store', 'prv001'))->assertForbidden();
    }

    public function test_inviting_creates_a_pending_invite_and_sends_mail(): void
    {
        Mail::fake();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)
            ->post(route('invites.store', $campaign), ['email' => 'friend@example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('campaign_invites', ['email' => 'friend@example.com', 'status' => 'pending']);
        Mail::assertSent(CampaignInviteMail::class);
    }

    public function test_accepting_an_invite_joins_even_a_private_world(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->update(['code' => 'prv002']);
        $invite = $campaign->invites()->create([
            'email' => 'friend@example.com', 'role' => 'player', 'token' => 'tok-123',
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
        $player = User::factory()->create();

        $this->actingAs($player)
            ->get(route('join.show', ['code' => 'prv002', 'invite' => 'tok-123']))
            ->assertRedirect('/w/'.$world->slug);

        $this->assertTrue($campaign->fresh()->hasMember($player));
        $this->assertSame('accepted', $invite->fresh()->status);
    }

    public function test_only_the_owner_can_manage_players(): void
    {
        $gm = User::factory()->create();
        $other = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($other)->get(route('players.index', $campaign))->assertForbidden();
    }
}
