<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDoc(User $gm)
    {
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        return $world->documents()->create(['title' => 'Saltmere', 'slug' => 's', 'kind' => 'location']);
    }

    public function test_ai_returns_a_reply_when_configured(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A misty harbour town of salt and secrets.']],
        ], 200)]);

        $gm = User::factory()->create();
        $doc = $this->ownedDoc($gm);

        $this->actingAs($gm)
            ->postJson(route('documents.ai', $doc), ['prompt' => 'Describe it'])
            ->assertOk()
            ->assertJson(['reply' => 'A misty harbour town of salt and secrets.']);
    }

    public function test_ai_reports_a_clear_error_without_a_key(): void
    {
        config(['services.anthropic.key' => null]);
        $gm = User::factory()->create();
        $doc = $this->ownedDoc($gm);

        $this->actingAs($gm)
            ->postJson(route('documents.ai', $doc), ['prompt' => 'hi'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "AI isn't set up on this server yet."]);
    }

    public function test_non_owner_cannot_use_ai(): void
    {
        $gm = User::factory()->create();
        $doc = $this->ownedDoc($gm);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson(route('documents.ai', $doc), ['prompt' => 'hi'])
            ->assertForbidden();
    }

    public function test_a_successful_reply_spends_one_daily_credit(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A reply.']],
        ], 200)]);

        $gm = User::factory()->create();
        $doc = $this->ownedDoc($gm);

        // Free plan grants 5 credits/day; spending one leaves four remaining today.
        $this->actingAs($gm)
            ->postJson(route('documents.ai', $doc), ['prompt' => 'Describe it'])
            ->assertOk()
            ->assertJson(['creditsRemaining' => 4]);

        $this->assertSame(1, $gm->fresh()->daily_ai_used);
    }

    public function test_a_heavier_entry_draft_spends_its_content_type_weight(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"reply":"Done","content":"An old sword.","fields":{}}']],
        ], 200)]);

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        // An "item" is a heavier content type (2 credits) than a light entry (1); free plan grants 5/day.
        $item = $world->documents()->create(['title' => 'Sword', 'slug' => 'sw', 'kind' => 'item']);

        $this->actingAs($gm)
            ->postJson(route('documents.draft', $item), ['prompt' => 'a rusty blade'])
            ->assertOk()
            ->assertJson(['creditsRemaining' => 3]);

        $this->assertSame(2, $gm->fresh()->daily_ai_used);
    }

    public function test_ai_is_refused_and_no_request_is_sent_when_out_of_credits(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake();

        // Free plan's five daily credits are all used today, with no purchased balance to fall back on.
        $gm = User::factory()->create([
            'daily_ai_used' => 5,
            'daily_ai_reset_on' => now()->toDateString(),
            'ai_credit_balance' => 0,
        ]);
        $doc = $this->ownedDoc($gm);

        $this->actingAs($gm)
            ->postJson(route('documents.ai', $doc), ['prompt' => 'Describe it'])
            ->assertStatus(402)
            ->assertJson(['outOfCredits' => true]);

        Http::assertNothingSent();
        $this->assertSame(5, $gm->fresh()->daily_ai_used);
    }

    public function test_a_purchased_credit_is_spent_once_the_daily_allowance_is_exhausted(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A reply.']],
        ], 200)]);

        // Daily allowance spent, but one purchased top-up credit remains.
        $gm = User::factory()->create([
            'daily_ai_used' => 5,
            'daily_ai_reset_on' => now()->toDateString(),
            'ai_credit_balance' => 1,
        ]);
        $doc = $this->ownedDoc($gm);

        $this->actingAs($gm)
            ->postJson(route('documents.ai', $doc), ['prompt' => 'Describe it'])
            ->assertOk()
            ->assertJson(['creditsRemaining' => 0]);

        $fresh = $gm->fresh();
        $this->assertSame(0, $fresh->ai_credit_balance);
        $this->assertSame(5, $fresh->daily_ai_used);
    }
}
