<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DraftBrewPage;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Support\AiReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BrewDraftTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(string $reply): void
    {
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chatReply')->andReturn(new AiReply($reply, false));
        $this->app->instance(AnthropicClient::class, $ai);
    }

    private function world(User $gm)
    {
        return $gm->worlds()->create([
            'name' => 'World', 'visibility' => 'public', 'ai_generation_limit' => 5, 'ai_generations_used' => 0,
        ]);
    }

    public function test_an_append_draft_returns_the_new_markdown_and_mode(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '# Running Zeus',
        ]);

        $this->fakeAi(json_encode([
            'mode' => 'append',
            'content' => "\\page\n## Zeus, Sundered\nA second sheet.",
            'reply' => 'Added the sundered form.',
        ]));

        // The draft runs on the queue (sync in tests, so it's already done) and the result is nested under `result`.
        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'add a weakened form'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.mode', 'append')
            ->assertJsonPath('result.content', "\\page\n## Zeus, Sundered\nA second sheet.")
            ->assertJsonPath('result.reply', 'Added the sundered form.');
    }

    public function test_a_replace_draft_returns_the_full_new_body(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '# Old',
        ]);

        $this->fakeAi(json_encode(['mode' => 'replace', 'content' => '# New body', 'reply' => 'Rewrote it.']));

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'rewrite the page'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.mode', 'replace')
            ->assertJsonPath('result.content', '# New body');
    }

    public function test_an_unrecognised_mode_falls_back_to_replace(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '# Old',
        ]);

        $this->fakeAi(json_encode(['mode' => 'sideways', 'content' => '# New', 'reply' => 'Done.']));

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'rewrite'])
            ->assertStatus(202)
            ->assertJsonPath('result.mode', 'replace');
    }

    public function test_a_clarifying_reply_carries_no_content(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '# Zeus',
        ]);

        $this->fakeAi(json_encode(['mode' => 'clarify', 'content' => '', 'reply' => 'Which form should fade first?']));

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'weaken him'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.content', '')
            ->assertJsonPath('result.reply', 'Which form should fade first?');
    }

    public function test_a_successful_draft_is_billed_against_the_users_credits(): void
    {
        // 5 free daily credits + 10 balance = 15; an article draft costs 1.
        $gm = User::factory()->create(['ai_credit_balance' => 10]);
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        $this->fakeAi(json_encode(['mode' => 'replace', 'content' => '# Zeus', 'reply' => 'done']));

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'draft it'])
            ->assertStatus(202)
            ->assertJsonPath('result.ai.creditsRemaining', 14);

        $this->assertSame(14, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_a_failed_generation_stores_the_error_and_bills_nothing(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 10]);
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chatReply')->andThrow(new RuntimeException('The AI took too long to respond — please try again.'));
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'draft it'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'The AI took too long to respond — please try again.');

        $this->assertSame(15, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_an_unparseable_reply_fails_with_a_retry_message(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        $this->fakeAi('Sure! Here is your statblock in prose instead of JSON.');

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'draft it'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'The AI returned an unexpected response. Please try again.');
    }

    public function test_a_draft_is_queued_and_polled_via_the_ai_request_endpoint(): void
    {
        Queue::fake();
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        // The controller only checks the AI reports it's configured; the job that would call it is faked off.
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $this->app->instance(AnthropicClient::class, $ai);

        $start = $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'draft it'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(DraftBrewPage::class);

        // The browser polls this handle until the worker finishes; here it's still pending (job not run).
        $this->actingAs($gm)->getJson(route('ai.requests.show', $start->json('id')))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }

    public function test_a_draft_is_blocked_when_the_user_is_out_of_credits(): void
    {
        // Daily allowance spent and no top-up balance → 0 credits available.
        $gm = User::factory()->create([
            'ai_credit_balance' => 0,
            'daily_ai_used' => 5,
            'daily_ai_reset_on' => now()->toDateString(),
        ]);
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chatReply')->never();
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'draft it'])
            ->assertStatus(402);
    }

    public function test_a_draft_is_forbidden_for_a_user_who_cannot_edit_the_document(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('chatReply')->never();
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($stranger)->postJson(route('documents.ai.draft', $document), ['prompt' => 'draft it'])
            ->assertStatus(403);
    }

    public function test_a_draft_cut_off_by_the_token_budget_is_continued_and_stitched_back_together(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '# Running Zeus',
        ]);

        // One JSON document, delivered across two model calls: the first hits the output budget
        // mid-string, the second carries on from the exact character it stopped at.
        $full = json_encode([
            'mode' => 'append',
            'content' => "\\page\n## Zeus, Sundered\nA long second sheet that outgrew one call.",
            'reply' => 'Added the sundered form. Want lair actions next?',
        ]);
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chatReply')->twice()->andReturn(
            new AiReply(mb_substr($full, 0, 45), true),
            new AiReply(mb_substr($full, 45), false),
        );
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'add a weakened form'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.mode', 'append')
            ->assertJsonPath('result.content', "\\page\n## Zeus, Sundered\nA long second sheet that outgrew one call.")
            ->assertJsonPath('result.reply', 'Added the sundered form. Want lair actions next?');
    }

    public function test_a_draft_still_truncated_after_the_continuation_limit_fails_and_bills_nothing(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 10]);
        $world = $this->world($gm);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Zeus', 'kind' => 'article', 'content' => '',
        ]);

        // Every call runs out of budget — the job stops after its call limit instead of looping forever.
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chatReply')->times(4)->andReturn(new AiReply('{"mode": "append", "content": "endless', true));
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('documents.ai.draft', $document), ['prompt' => 'write everything'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'That draft was too long to finish in one go — ask for it a section at a time.');

        $this->assertSame(15, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_a_session_draft_runs_through_the_session_endpoint(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'The Sunken Bell']);

        $this->fakeAi(json_encode(['mode' => 'append', 'content' => '## Act Two', 'reply' => 'Added act two.']));

        $this->actingAs($gm)->postJson(route('sessions.ai.draft', $session), ['prompt' => 'add act two'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('result.mode', 'append')
            ->assertJsonPath('result.content', '## Act Two')
            ->assertJsonPath('result.reply', 'Added act two.');
    }
}
