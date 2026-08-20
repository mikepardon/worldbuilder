<?php

namespace Tests\Feature;

use App\Jobs\DraftCompendiumEntry;
use App\Models\User;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CompendiumDraftTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(string $reply): void
    {
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->andReturn($reply);
        $this->app->instance(AnthropicClient::class, $ai);
    }

    private function world(User $gm, int $budget = 5)
    {
        return $gm->worlds()->create([
            'name' => 'World', 'visibility' => 'public', 'ai_generation_limit' => $budget, 'ai_generations_used' => 0,
        ]);
    }

    public function test_draft_fills_a_monster_stat_block_and_keeps_untouched_fields(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->world($gm);
        $monster = $campaign->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'rat', 'name' => 'Dock Rat', 'provider' => 'custom',
            'fields' => ['block' => ['type' => 'beast', 'ac' => '11', 'cr' => '0', 'abilities' => ['str' => 2, 'dex' => 14]]],
        ]);

        // Claude changes CR/AC/STR; "type" is not returned and must be preserved from the current block.
        $this->fakeAi(json_encode([
            'block' => ['ac' => '16', 'cr' => '5', 'abilities' => ['str' => 18]],
            'summary' => 'A hulking wharf brute.',
            'reply' => 'Beefed it up to CR 5.',
        ]));

        // The draft runs on the queue (sync in tests, so it's already done) and the result is nested under `result`.
        $this->actingAs($gm)->postJson(route('compendium.draft', $monster), ['prompt' => 'make it CR 5'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.block.ac', '16')
            ->assertJsonPath('result.block.cr', '5')
            ->assertJsonPath('result.block.abilities.str', 18)
            ->assertJsonPath('result.block.abilities.dex', 14) // untouched ability preserved
            ->assertJsonPath('result.block.type', 'beast')     // untouched field preserved
            ->assertJsonPath('result.summary', 'A hulking wharf brute.')
            ->assertJsonPath('result.document', '');           // monsters carry no free document from the draft
    }

    public function test_draft_fills_structured_fields_for_a_non_monster_entry(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->world($gm);
        $spell = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball', 'provider' => 'custom',
        ]);

        // Spells edit structured fields; a bogus level (not a select option) is dropped.
        $this->fakeAi(json_encode([
            'fields' => ['level' => '3rd', 'school' => 'Evocation', 'description' => 'A bright streak flashes.', 'bogus' => 'x'],
            'summary' => 'A roaring ball of flame.',
            'reply' => 'Drafted the spell.',
        ]));

        $this->actingAs($gm)->postJson(route('compendium.draft', $spell), ['prompt' => 'draft this spell'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.fields.level', '3rd')
            ->assertJsonPath('result.fields.school', 'Evocation')
            ->assertJsonPath('result.fields.description', 'A bright streak flashes.')
            ->assertJsonMissingPath('result.fields.bogus')
            ->assertJsonPath('result.summary', 'A roaring ball of flame.')
            ->assertJsonPath('result.block', null);
    }

    public function test_draft_can_ask_a_clarifying_question_without_changing_anything(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->world($gm);
        $spell = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball', 'provider' => 'custom',
        ]);

        $this->fakeAi(json_encode(['document' => '', 'summary' => '', 'reply' => 'What level should this spell be?']));

        $this->actingAs($gm)->postJson(route('compendium.draft', $spell), ['prompt' => 'adjust it'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.reply', 'What level should this spell be?')
            ->assertJsonPath('result.document', '')
            ->assertJsonPath('result.block', null);
    }

    public function test_a_successful_draft_is_billed_against_the_users_credits(): void
    {
        // 5 free daily credits + 10 balance = 15; a spell draft costs 1.
        $gm = User::factory()->create(['ai_credit_balance' => 10]);
        $campaign = $this->world($gm);
        $spell = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball', 'provider' => 'custom',
        ]);

        $this->fakeAi(json_encode(['document' => '## Fireball', 'summary' => '', 'reply' => 'done']));

        $this->actingAs($gm)->postJson(route('compendium.draft', $spell), ['prompt' => 'draft it'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.ai.creditsRemaining', 14);

        $this->assertSame(14, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_a_draft_is_queued_and_polled_via_the_ai_request_endpoint(): void
    {
        Queue::fake();
        $gm = User::factory()->create(['ai_credit_balance' => 10]);
        $campaign = $this->world($gm);
        $spell = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball', 'provider' => 'custom',
        ]);

        // The controller only checks the AI reports it's configured; the job that would call it is faked off.
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $this->app->instance(AnthropicClient::class, $ai);

        $start = $this->actingAs($gm)->postJson(route('compendium.draft', $spell), ['prompt' => 'draft it'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(DraftCompendiumEntry::class);

        // The browser polls this handle until the worker finishes; here it's still pending (job not run).
        $this->actingAs($gm)->getJson(route('ai.requests.show', $start->json('id')))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }

    public function test_draft_is_blocked_when_the_user_is_out_of_credits(): void
    {
        // Daily allowance spent and no top-up balance → 0 credits available.
        $gm = User::factory()->create([
            'ai_credit_balance' => 0,
            'daily_ai_used' => 5,
            'daily_ai_reset_on' => now()->toDateString(),
        ]);
        $campaign = $this->world($gm);
        $spell = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball', 'provider' => 'custom',
        ]);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->never();
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('compendium.draft', $spell), ['prompt' => 'draft it'])
            ->assertStatus(402);
    }

    public function test_draft_is_rejected_for_an_imported_read_only_entry(): void
    {
        $gm = User::factory()->create();
        $campaign = $this->world($gm);
        $imported = $campaign->compendiumItems()->create([
            'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball', 'provider' => 'imported',
        ]);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('chat')->never();
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('compendium.draft', $imported), ['prompt' => 'change it'])
            ->assertStatus(403);
    }
}
