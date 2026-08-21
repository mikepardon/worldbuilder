<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_create_a_token(): void
    {
        $this->postJson(route('profile.api-tokens.store'), ['name' => 'x'])->assertUnauthorized();
    }

    public function test_a_user_can_create_a_token_and_sees_the_plain_text_once(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('profile.api-tokens.store'), ['name' => 'Claude Desktop'])
            ->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('Claude Desktop', $response->json('accessToken.name'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('Claude Desktop', $user->tokens()->firstOrFail()->name);
    }

    public function test_creating_a_token_requires_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('profile.api-tokens.store'), ['name' => ''])
            ->assertStatus(422);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_user_can_revoke_their_own_token(): void
    {
        $user = User::factory()->create();
        $tokenId = $user->createToken('Temp')->accessToken->id;

        $this->actingAs($user)
            ->deleteJson(route('profile.api-tokens.destroy', $tokenId))
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_user_cannot_revoke_another_users_token(): void
    {
        $owner = User::factory()->create();
        $tokenId = $owner->createToken('Owner token')->accessToken->id;

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->deleteJson(route('profile.api-tokens.destroy', $tokenId))
            ->assertOk();

        // The route is scoped to the caller's own tokens, so the owner's token is untouched.
        $this->assertSame(1, $owner->tokens()->count());
    }
}
