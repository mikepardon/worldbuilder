<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReapStuckTranscription;
use App\Models\Recap;
use App\Models\Session;
use App\Models\User;
use App\Services\Transcription\CallbackTranscriber;
use App\Services\Transcription\Transcriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DeepgramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT = 'The party rang the drowned bell and the Ashen Concord answered from below.';

    /** A Deepgram-shaped callback body; the transcriber reads the paragraph transcript out of this. */
    private const CALLBACK_PAYLOAD = [
        'metadata' => ['request_id' => 'req_123', 'duration' => 9000.4],
        'results' => ['channels' => [['alternatives' => [['paragraphs' => ['transcript' => self::TRANSCRIPT]]]]]],
    ];

    private function fakeAnalysis(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => json_encode([
                'recap_full' => 'The party rang the bell.',
                'recap_short' => 'They rang the bell.',
                'recap_stylized' => 'Previously…',
                'moments' => [['type' => 'epic', 'description' => 'The bell tolled.', 'context' => 'At low tide.']],
                'outline' => [['title' => 'The Bell', 'detail' => 'They rang it.']],
                'entities' => [['name' => 'Maren', 'type' => 'npc', 'description' => 'The tide-priestess.']],
            ])]],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 800],
        ], 200)]);
    }

    private function sessionFor(User $gm): Session
    {
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);

        return $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session 3']);
    }

    /** A recap sitting mid-pipeline: audio submitted, awaiting Deepgram's callback with the transcript. */
    private function awaitingCallback(User $gm, Session $session): Recap
    {
        return $session->recap()->create([
            'user_id' => $gm->id,
            'disk' => 's3',
            'path' => "recaps/{$session->id}/abc.wav",
            'transcription_request_id' => 'req_123',
            'detail_level' => 'comprehensive',
            'status' => 'transcribing',
        ]);
    }

    private function signedCallback(Recap $recap): string
    {
        return URL::signedRoute('recaps.deepgram.webhook', ['recap' => $recap->id], absolute: false);
    }

    public function test_a_configured_callback_url_makes_transcription_submit_asynchronously_without_analysing_yet(): void
    {
        Storage::fake('s3');
        config(['services.deepgram.callback_url' => 'https://tunnel.test']);

        // A callback-capable transcriber that records the submitted URL rather than calling out.
        $fake = new class implements CallbackTranscriber
        {
            public ?string $submittedCallbackUrl = null;

            #[\Override]
            public function transcribe(string $disk, string $path): string
            {
                return DeepgramWebhookTest::TRANSCRIPT;
            }

            #[\Override]
            public function submit(string $disk, string $path, string $callbackUrl): string
            {
                $this->submittedCallbackUrl = $callbackUrl;

                return 'req_123';
            }

            #[\Override]
            public function transcriptFromCallback(array $payload): string
            {
                return DeepgramWebhookTest::TRANSCRIPT;
            }

            #[\Override]
            public function durationSecondsFromCallback(array $payload): int
            {
                return 0;
            }
        };
        $this->app->bind(Transcriber::class, fn () => $fake);

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key,
            'duration_seconds' => 3600,
            'detail_level' => 'comprehensive',
        ])->assertStatus(202);

        $recap = $session->fresh()->recap;
        $this->assertSame('transcribing', $recap->status);
        $this->assertSame('req_123', $recap->transcription_request_id);
        $this->assertNull($recap->transcript);
        // Nothing spent yet — analysis hasn't run.
        $this->assertSame(0, $gm->fresh()->daily_ai_used);

        // Deepgram was handed a signed callback on the configured public host, bound to this recap.
        $this->assertNotNull($fake->submittedCallbackUrl);
        $this->assertStringStartsWith("https://tunnel.test/webhooks/deepgram/{$recap->id}?", $fake->submittedCallbackUrl);
        $this->assertStringContainsString('signature=', $fake->submittedCallbackUrl);
    }

    public function test_the_signed_callback_saves_the_transcript_and_completes_the_analysis(): void
    {
        // The real Deepgram transcriber is bound (key present); only its pure callback parser runs here.
        config(['services.deepgram.key' => 'dg-key']);
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $recap = $this->awaitingCallback($gm, $session);

        $this->postJson($this->signedCallback($recap), self::CALLBACK_PAYLOAD)->assertNoContent();

        $recap->refresh();
        $this->assertSame('done', $recap->status, (string) $recap->error);
        $this->assertSame(self::TRANSCRIPT, $recap->transcript);
        // The audio duration is captured from Deepgram's payload (9000.4s → 9000).
        $this->assertSame(9000, $recap->duration_seconds);
        $this->assertSame('They rang the bell.', $recap->recap_short);
        $this->assertCount(1, $recap->entities);
        // Billed by length only now the analysis ran: 9000s ≈ 2.5h → 30 credits (5 daily + 25 balance).
        $this->assertSame(975, $gm->fresh()->aiCreditsRemaining());
    }

    public function test_an_unsigned_callback_is_rejected_and_leaves_the_recap_untouched(): void
    {
        config(['services.deepgram.key' => 'dg-key']);

        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $recap = $this->awaitingCallback($gm, $session);

        // The bare route carries no signature.
        $this->postJson(route('recaps.deepgram.webhook', $recap), self::CALLBACK_PAYLOAD)->assertForbidden();

        $this->assertNull($recap->fresh()->transcript);
        $this->assertSame('transcribing', $recap->fresh()->status);
    }

    public function test_async_submission_schedules_a_reaper_in_case_the_callback_is_dropped(): void
    {
        Storage::fake('s3');
        config(['services.deepgram.callback_url' => 'https://tunnel.test']);
        Bus::fake([ReapStuckTranscription::class]);

        $fake = new class implements CallbackTranscriber
        {
            #[\Override]
            public function transcribe(string $disk, string $path): string
            {
                return DeepgramWebhookTest::TRANSCRIPT;
            }

            #[\Override]
            public function submit(string $disk, string $path, string $callbackUrl): string
            {
                return 'req_123';
            }

            #[\Override]
            public function transcriptFromCallback(array $payload): string
            {
                return DeepgramWebhookTest::TRANSCRIPT;
            }

            #[\Override]
            public function durationSecondsFromCallback(array $payload): int
            {
                return 0;
            }
        };
        $this->app->bind(Transcriber::class, fn () => $fake);

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.wav";
        Storage::disk('s3')->put($key, 'audio');

        $this->actingAs($gm)->postJson(route('sessions.recap.store', $session), [
            'key' => $key,
            'duration_seconds' => 3600,
            'detail_level' => 'comprehensive',
        ])->assertStatus(202);

        Bus::assertDispatched(ReapStuckTranscription::class, fn ($job) => $job->delay !== null);
    }

    public function test_the_reaper_fails_a_recap_still_awaiting_its_callback(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $recap = $this->awaitingCallback($gm, $session);
        // Backdate past the callback deadline (bypassing auto-timestamps) so it counts as genuinely stuck.
        Recap::where('id', $recap->id)->update(['updated_at' => now()->subHours(2)]);

        (new ReapStuckTranscription($recap))->handle();

        $recap->refresh();
        $this->assertSame('failed', $recap->status);
        $this->assertStringContainsString('never came back', (string) $recap->error);
    }

    public function test_the_reaper_leaves_a_recap_that_already_moved_on(): void
    {
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $recap = $this->awaitingCallback($gm, $session);
        $recap->update(['status' => 'done']);

        (new ReapStuckTranscription($recap))->handle();

        $this->assertSame('done', $recap->fresh()->status);
    }

    public function test_a_duplicate_callback_for_an_already_transcribed_recap_is_a_no_op(): void
    {
        config(['services.deepgram.key' => 'dg-key']);
        $this->fakeAnalysis();

        $gm = User::factory()->create(['ai_credit_balance' => 1000]);
        $session = $this->sessionFor($gm);
        $recap = $this->awaitingCallback($gm, $session);

        $signed = $this->signedCallback($recap);
        $this->postJson($signed, self::CALLBACK_PAYLOAD)->assertNoContent();
        // 9000s ≈ 2.5h → 30 credits spent once, leaving 975.
        $this->assertSame(975, $gm->fresh()->aiCreditsRemaining());

        // A re-delivery must not run the analysis (or spend credits) a second time.
        $this->postJson($signed, self::CALLBACK_PAYLOAD)->assertNoContent();
        $this->assertSame(975, $gm->fresh()->aiCreditsRemaining());
    }
}
