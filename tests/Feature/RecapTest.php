<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Session;
use App\Models\User;
use App\Services\Transcription\Transcriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase;

    public const TRANSCRIPT = 'The party rang the drowned bell and the Ashen Concord answered from below.';

    private function fakeTranscriber(): void
    {
        $this->app->bind(Transcriber::class, fn () => new class implements Transcriber
        {
            #[\Override]
            public function transcribe(string $disk, string $path): string
            {
                return RecapTest::TRANSCRIPT;
            }
        });
    }

    private function fakeAnalysis(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => json_encode([
                'recap_full' => '## The Drowned Bell'."\n\n".'The party rang the bell.',
                'recap_short' => 'They rang the bell.',
                'recap_stylized' => 'Previously, on the Sundered Coast…',
                'moments' => [
                    ['type' => 'epic', 'description' => 'The bell tolled.', 'context' => 'At low tide.'],
                ],
                'outline' => [
                    ['title' => 'The Causeway', 'detail' => 'They crossed at low tide.'],
                    ['title' => 'The Bell', 'detail' => 'They rang it.'],
                ],
                'next_steps' => [
                    'Return to the drowned bell at the next low tide.',
                    'Hunt down the Ashen Concord.',
                ],
                'entities' => [
                    ['name' => 'Maren', 'type' => 'npc', 'description' => 'The tide-priestess.'],
                    ['name' => 'The Ashen Concord', 'type' => 'faction', 'description' => 'Scuttled the town.'],
                    ['name' => 'Lantern of Low Water', 'type' => 'item', 'description' => 'Burns underwater.'],
                ],
            ])]],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 800],
        ], 200)]);
    }

    private function sessionFor(User $gm, string $visibility = 'private'): Session
    {
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => $visibility]);

        return $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session 3']);
    }

    public function test_storing_a_recap_transcribes_analyses_and_stores_the_full_result(): void
    {
        Storage::fake('s3');
        $this->fakeTranscriber();
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key,
            'original_name' => 'Timeline 1.wav',
            'duration_seconds' => 3600, // 1 hour → 12 credits at 12/hour
            'detail_level' => 'comprehensive',
        ])->assertStatus(202)->assertJsonPath('status', 'queued');

        $recap = $session->fresh()->recap;
        $this->assertSame('done', $recap->status);
        $this->assertSame(self::TRANSCRIPT, $recap->transcript);
        $this->assertStringContainsString('The Drowned Bell', $recap->recap_full);
        $this->assertSame('They rang the bell.', $recap->recap_short);
        $this->assertNotEmpty($recap->recap_stylized);
        $this->assertCount(1, $recap->moments);
        $this->assertSame('epic', $recap->moments[0]['type']);
        $this->assertCount(2, $recap->outline);
        $this->assertCount(2, $recap->next_steps);
        $this->assertSame('Return to the drowned bell at the next low tide.', $recap->next_steps[0]);
        $this->assertCount(3, $recap->entities);
        // A 1-hour recap costs 12 credits: 5 daily + 7 of the top-up balance, leaving 993 available.
        $this->assertSame(993, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_a_session_keeps_only_one_recap_and_re_uploading_replaces_it(): void
    {
        Storage::fake('s3');
        $this->fakeTranscriber();
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);

        foreach (['first.wav', 'second.wav'] as $name) {
            $key = "recaps/{$session->id}/{$name}";
            Storage::disk('s3')->put($key, 'audio');
            $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
                'key' => $key,
                'original_name' => $name,
                'duration_seconds' => 3600,
                'detail_level' => 'brief',
            ])->assertStatus(202);
        }

        $this->assertSame(1, $session->recap()->count());
        $this->assertSame('second.wav', $session->fresh()->recap->original_name);
        // The superseded recording is cleaned up.
        Storage::disk('s3')->assertMissing("recaps/{$session->id}/first.wav");
    }

    public function test_it_refuses_a_key_outside_the_session_prefix(): void
    {
        Storage::fake('s3');

        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => 'recaps/999999/evil.wav',
            'detail_level' => 'comprehensive',
        ])->assertForbidden();

        $this->assertNull($session->fresh()->recap);
    }

    public function test_status_reports_none_when_there_is_no_recap(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->getJson(route('sessions.recap.status', $session))
            ->assertOk()->assertJsonPath('status', 'none');
    }

    public function test_a_gm_can_rate_the_recap(): void
    {
        Storage::fake('s3');
        $this->fakeTranscriber();
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');
        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key, 'duration_seconds' => 3600, 'detail_level' => 'brief',
        ]);

        $this->actingAs($gm)->putJson(route('sessions.recap.rate', $session), ['rating' => 4])
            ->assertOk()->assertJsonPath('rating', 4);
        $this->assertSame(4, $session->fresh()->recap->rating);
    }

    public function test_the_recap_page_renders_for_a_gm(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->get(route('sessions.recap.show', [$session->campaign->world_id, $session->campaign_id, $session->id]))
            ->assertInertia(fn ($page) => $page->component('Recaps/Show')->where('recap', null));
    }

    public function test_the_legacy_flat_recap_url_redirects_to_the_nested_one(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->get("/sessions/{$session->id}/recap")
            ->assertRedirect(route('sessions.recap.show', [$session->campaign->world_id, $session->campaign_id, $session->id]));
    }

    public function test_a_non_gm_cannot_upload_or_view_a_recap(): void
    {
        Storage::fake('s3');

        $gm = User::factory()->create();
        $session = $this->sessionFor($gm, 'public');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('sessions.recap.show', [$session->campaign->world_id, $session->campaign_id, $session->id]))->assertForbidden();
        $this->actingAs($intruder)->postJson(route('sessions.recap.store', $session), [
            'key' => "recaps/{$session->id}/x.wav",
            'detail_level' => 'comprehensive',
        ])->assertForbidden();
    }

    public function test_retrying_re_runs_transcription_and_analysis_for_an_existing_recap(): void
    {
        Storage::fake('s3');
        $this->fakeTranscriber();
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');
        // A recap that previously failed, with a stale request id to be cleared.
        $recap = $session->recap()->create([
            'user_id' => $gm->id,
            'disk' => 's3',
            'path' => $key,
            'transcription_request_id' => 'dead-request',
            'duration_seconds' => 3600,
            'detail_level' => 'comprehensive',
            'status' => 'failed',
            'error' => 'The transcript never came back from the provider. Please try again.',
        ]);

        $this->actingAs($gm)->postJson(route('sessions.recap.retry', $session))
            ->assertStatus(202);

        $recap->refresh();
        $this->assertSame('done', $recap->status);
        $this->assertSame(self::TRANSCRIPT, $recap->transcript);
        $this->assertNull($recap->error);
        $this->assertNull($recap->transcription_request_id);
        // Re-running a 1-hour recap charges its 12 credits.
        $this->assertSame(993, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_retrying_a_recap_whose_audio_is_missing_returns_422(): void
    {
        Storage::fake('s3');

        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        // The recap points at a key that is not in storage.
        $session->recap()->create([
            'user_id' => $gm->id,
            'disk' => 's3',
            'path' => "recaps/{$session->id}/gone.wav",
            'detail_level' => 'comprehensive',
            'status' => 'failed',
        ]);

        $this->actingAs($gm)->postJson(route('sessions.recap.retry', $session))
            ->assertStatus(422);
    }

    public function test_retrying_without_a_recap_is_not_found(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.recap.retry', $session))
            ->assertNotFound();
    }

    public function test_a_recap_can_be_created_from_pasted_text_without_transcription(): void
    {
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $creditsBefore = $gm->aiCreditsRemaining();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.recap.text', $session), [
            'text' => str_repeat('The party rang the drowned bell and fought the Ashen Concord. ', 20),
            'detail_level' => 'comprehensive',
        ])->assertStatus(202);

        $recap = $session->fresh()->recap;
        $this->assertSame('done', $recap->status);
        $this->assertFalse($recap->hasAudio());
        $this->assertSame('', $recap->path);
        $this->assertStringContainsString('drowned bell', (string) $recap->transcript);
        // The same analysis ran and produced the recap variants.
        $this->assertNotNull($recap->recap_full);
        // Billed on success like an audio recap.
        $this->assertLessThan($creditsBefore, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_a_text_recap_is_blocked_when_out_of_credits(): void
    {
        $this->fakeAnalysis();

        $gm = User::factory()->create([
            'ai_credit_balance' => 0, 'daily_ai_used' => 5, 'daily_ai_reset_on' => now()->toDateString(),
        ]);
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.recap.text', $session), [
            'text' => str_repeat('word ', 400),
        ])->assertStatus(402);

        $this->assertNull($session->fresh()->recap);
    }

    public function test_analysis_auto_links_an_entity_that_already_exists_in_the_world(): void
    {
        Storage::fake('s3');
        $this->fakeTranscriber();
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $world = $session->campaign->world;
        $maren = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Maren', 'slug' => 'maren',
            'kind' => 'npc', 'content' => '# Maren', 'is_private' => false,
        ]);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key, 'duration_seconds' => 3600, 'detail_level' => 'comprehensive',
        ])->assertStatus(202);

        $recap = $session->fresh()->recap;
        $marenEntity = $recap->entities()->where('name', 'Maren')->first();
        $this->assertSame('linked', $marenEntity->status);
        $this->assertSame($maren->id, $marenEntity->linked_document_id);

        // A name with no existing entry stays in the review queue.
        $ashen = $recap->entities()->where('name', 'The Ashen Concord')->first();
        $this->assertSame('unmatched', $ashen->status);
    }

    public function test_a_gm_can_edit_the_recap_analysis(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $recap = $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_full' => 'old full', 'recap_short' => 'old short', 'recap_stylized' => 'old stylized',
            'moments' => [['type' => 'epic', 'description' => 'old', 'context' => '']],
            'outline' => [['title' => 'Old scene', 'detail' => '']],
        ]);

        $this->actingAs($gm)->putJson(route('sessions.recap.content', $session), [
            'recap_full' => 'New full recap.',
            'recap_short' => 'New short.',
            'recap_stylized' => 'New stylized.',
            'moments' => [['type' => 'funny', 'description' => 'A funny bit', 'context' => 'after the fight']],
            'outline' => [['title' => 'Scene One', 'detail' => 'They arrived.'], ['title' => 'Scene Two', 'detail' => '']],
            'next_steps' => ['Find the ledger', '', '  Bribe the harbourmaster  '],
            'facts' => ['Brian is the character Clayton', ''],
        ])->assertOk()->assertJsonPath('recap_full', 'New full recap.');

        $recap->refresh();
        $this->assertSame('New full recap.', $recap->recap_full);
        $this->assertSame('New short.', $recap->recap_short);
        $this->assertCount(1, $recap->moments);
        $this->assertSame('funny', $recap->moments[0]['type']);
        $this->assertSame('after the fight', $recap->moments[0]['context']);
        $this->assertCount(2, $recap->outline);
        $this->assertSame('Scene One', $recap->outline[0]['title']);
        // Blank steps are dropped and surviving ones trimmed.
        $this->assertSame(['Find the ledger', 'Bribe the harbourmaster'], $recap->next_steps);
        $this->assertSame(['Brian is the character Clayton'], $recap->facts);
    }

    public function test_reanalysing_applies_facts_to_the_ai_without_charging_again(): void
    {
        Storage::fake('s3');
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $recap = $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'duration_seconds' => 3600, 'detail_level' => 'comprehensive', 'status' => 'done',
            'transcript' => 'The party met Brian at the gate.',
            'facts' => ['The players say “Brian” but mean the character Clayton'],
        ]);
        $before = $gm->fresh()->aiCreditsRemaining();

        $this->actingAs($gm)->postJson(route('sessions.recap.reanalyse', $session))->assertStatus(202);

        $this->assertSame('done', $recap->fresh()->status);
        // Re-analysis reuses the paid transcript, so nothing is charged.
        $this->assertSame($before, $gm->fresh()->aiCreditsRemaining());
        // The fact reached the model.
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Clayton'));
    }

    public function test_reanalysing_without_a_transcript_is_rejected(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
        ]);

        $this->actingAs($gm)->postJson(route('sessions.recap.reanalyse', $session))->assertStatus(422);
    }

    public function test_a_gm_can_save_campaign_recap_guidance(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->putJson(route('campaigns.recap-guidance', $campaign), [
            'facts' => ['Brian is the character Clayton', ''],
            'instructions' => ['  Never mention dice rolls  ', ''],
        ])->assertOk()
            ->assertJsonPath('recap_facts.0', 'Brian is the character Clayton')
            ->assertJsonPath('recap_instructions.0', 'Never mention dice rolls');

        $campaign->refresh();
        $this->assertSame(['Brian is the character Clayton'], $campaign->recap_facts);
        $this->assertSame(['Never mention dice rolls'], $campaign->recap_instructions);
    }

    public function test_campaign_guidance_is_applied_to_the_analysis(): void
    {
        Storage::fake('s3');
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $campaign->update([
            'recap_facts' => ['Brian is the character Clayton'],
            'recap_instructions' => ['Never mention dice rolls'],
        ]);
        $session = $campaign->sessions()->create(['title' => 'Session 3']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'duration_seconds' => 3600, 'detail_level' => 'comprehensive', 'status' => 'done',
            'transcript' => 'Brian rolled a natural 20.',
        ]);

        $this->actingAs($gm)->postJson(route('sessions.recap.reanalyse', $session))->assertStatus(202);

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Clayton')
            && str_contains(json_encode($request->data()), 'Never mention dice rolls'));
    }

    public function test_a_non_gm_cannot_edit_campaign_recap_guidance(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->putJson(route('campaigns.recap-guidance', $campaign), [
            'facts' => [], 'instructions' => [],
        ])->assertForbidden();
    }

    public function test_editing_rejects_an_unknown_moment_type(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
        ]);

        $this->actingAs($gm)->putJson(route('sessions.recap.content', $session), [
            'moments' => [['type' => 'nonsense', 'description' => 'A moment']],
            'outline' => [],
        ])->assertStatus(422);
    }

    public function test_a_non_gm_cannot_edit_the_recap(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm, 'public');
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->putJson(route('sessions.recap.content', $session), [
            'moments' => [], 'outline' => [],
        ])->assertForbidden();
    }

    public function test_a_user_out_of_credits_cannot_store_a_recap(): void
    {
        Storage::fake('s3');

        $gm = User::factory()->create(['ai_credit_balance' => 0]);
        while ($gm->canSpendAiCredit()) {
            $gm->spendAiCredit();
        }
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key,
            'duration_seconds' => 3600, // needs 12 credits; the user has only the 5 daily free
            'detail_level' => 'comprehensive',
        ])->assertStatus(402)->assertJsonPath('outOfCredits', true);

        $this->assertNull($session->fresh()->recap);
    }

    public function test_storing_a_recap_without_a_readable_duration_is_rejected(): void
    {
        Storage::fake('s3');

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key,
            'duration_seconds' => 0,
            'detail_level' => 'comprehensive',
        ])->assertStatus(422);

        $this->assertNull($session->fresh()->recap);
    }
}
