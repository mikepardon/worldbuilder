<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Session;
use App\Models\User;
use App\Services\S3\MultipartUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionUploadTest extends TestCase
{
    use RefreshDatabase;

    /** Swap the real S3 multipart wrapper for a double so the tests never touch AWS. */
    private function fakeUploads(): void
    {
        $this->app->bind(MultipartUploads::class, fn () => new class extends MultipartUploads
        {
            #[\Override]
            public function create(string $key, string $contentType): string
            {
                return 'upload-123';
            }

            #[\Override]
            public function signPart(string $key, string $uploadId, int $partNumber): string
            {
                return "https://bucket.example/{$key}?part={$partNumber}";
            }

            #[\Override]
            public function listParts(string $key, string $uploadId): array
            {
                return [];
            }

            #[\Override]
            public function complete(string $key, string $uploadId, array $parts): string
            {
                return "https://bucket.example/{$key}";
            }

            #[\Override]
            public function abort(string $key, string $uploadId): void {}
        });
    }

    private function sessionFor(User $gm): Session
    {
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);

        return $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session 3']);
    }

    public function test_starting_an_upload_returns_a_scoped_key_and_upload_id(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $response = $this->actingAs($gm)->postJson(route('sessions.uploads.create', $session), [
            'filename' => 'my session.m4a',
            'type' => 'audio/mp4',
        ])->assertOk()->assertJsonPath('uploadId', 'upload-123');

        $key = $response->json('key');
        $this->assertStringStartsWith("recaps/{$session->id}/", $key);
        $this->assertStringEndsWith('.m4a', $key);
    }

    public function test_starting_an_upload_rejects_a_non_audio_extension(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.uploads.create', $session), [
            'filename' => 'notes.pdf',
        ])->assertStatus(422);
    }

    public function test_signing_a_part_returns_a_url(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.uploads.sign', $session), [
            'key' => "recaps/{$session->id}/abc.m4a",
            'uploadId' => 'upload-123',
            'partNumber' => 2,
        ])->assertOk()->assertJsonPath('url', "https://bucket.example/recaps/{$session->id}/abc.m4a?part=2");
    }

    public function test_it_refuses_to_sign_a_key_outside_this_sessions_prefix(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);

        // A key belonging to a different session (or arbitrary path) must be rejected.
        $this->actingAs($gm)->postJson(route('sessions.uploads.sign', $session), [
            'key' => 'recaps/999999/evil.m4a',
            'uploadId' => 'upload-123',
            'partNumber' => 1,
        ])->assertForbidden();
    }

    public function test_completing_an_upload_returns_the_object_location(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create();
        $session = $this->sessionFor($gm);
        $key = "recaps/{$session->id}/abc.m4a";

        $this->actingAs($gm)->postJson(route('sessions.uploads.complete', $session), [
            'key' => $key,
            'uploadId' => 'upload-123',
            'parts' => [['PartNumber' => 1, 'ETag' => '"etag-1"']],
        ])->assertOk()->assertJsonPath('location', "https://bucket.example/{$key}");
    }

    public function test_a_user_out_of_credits_cannot_start_an_upload(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create(['ai_credit_balance' => 0]);
        while ($gm->canSpendAiCredit()) {
            $gm->spendAiCredit();
        }
        $session = $this->sessionFor($gm);

        $this->actingAs($gm)->postJson(route('sessions.uploads.create', $session), [
            'filename' => 'session.m4a',
        ])->assertStatus(402)->assertJsonPath('outOfCredits', true);
    }

    public function test_a_non_gm_cannot_sign_uploads(): void
    {
        $this->fakeUploads();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session 3']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('sessions.uploads.create', $session), [
            'filename' => 'session.m4a',
        ])->assertForbidden();
    }
}
