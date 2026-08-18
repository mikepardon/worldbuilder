<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorageBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function sessionFor(User $gm): Session
    {
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);

        return $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'The Drowned Bell']);
    }

    private function recordingFor(User $gm, int $sizeBytes = 1000, string $status = 'done'): \App\Models\Recap
    {
        $session = $this->sessionFor($gm);
        $path = "recaps/{$session->id}/audio.wav";
        Storage::disk('s3')->put($path, str_repeat('a', $sizeBytes));

        return $session->recap()->create([
            'user_id' => $gm->id,
            'disk' => 's3',
            'path' => $path,
            'original_name' => 'session.wav',
            'duration_seconds' => 3600,
            'size_bytes' => $sizeBytes,
            'status' => $status,
            'transcript' => 'The party rang the drowned bell.',
            'recap_full' => 'They rang the bell.',
        ]);
    }

    public function test_the_breakdown_page_reports_storage_split_across_recordings_media_and_avatar(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create(['plan' => 'free', 'avatar_size' => 200]);
        $this->recordingFor($user, 1000);

        $world = $user->worlds()->firstOrFail();
        Media::create([
            'user_id' => $user->id, 'world_id' => $world->id, 'disk' => 'public',
            'path' => 'media/a.png', 'filename' => 'a.png', 'mime' => 'image/png', 'size' => 500,
        ]);

        $this->actingAs($user)->get(route('storage.index'))->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('Storage/Index')
                ->where('usage.used_bytes', 1700)
                ->where('categories.0.bytes', 1000)
                ->where('categories.1.bytes', 500)
                ->where('categories.2.bytes', 200)
                ->has('recordings', 1)
                ->has('media', 1)
        );
    }

    public function test_deleting_a_recordings_audio_removes_the_file_and_reclaims_the_storage(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create(['plan' => 'free']);
        $recap = $this->recordingFor($user, 1000);

        $this->assertSame(1000, $user->fresh()->storageUsedBytes());

        $this->actingAs($user)
            ->delete(route('storage.recordings.destroy', $recap))
            ->assertRedirect();

        Storage::disk('s3')->assertMissing($recap->path);
        $this->assertSame(0, $user->fresh()->storageUsedBytes());
    }

    public function test_deleting_a_recordings_audio_keeps_the_transcript_and_recap(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create(['plan' => 'free']);
        $recap = $this->recordingFor($user, 1000);

        $this->actingAs($user)->delete(route('storage.recordings.destroy', $recap))->assertRedirect();

        $fresh = $recap->fresh();
        $this->assertSame(0, $fresh->size_bytes);
        $this->assertSame('', $fresh->path);
        $this->assertSame('done', $fresh->status);
        $this->assertSame('The party rang the drowned bell.', $fresh->transcript);
        $this->assertFalse($fresh->hasAudio());
    }

    public function test_a_user_cannot_delete_another_users_recording_audio(): void
    {
        Storage::fake('s3');
        $owner = User::factory()->create(['plan' => 'free']);
        $recap = $this->recordingFor($owner, 1000);

        $intruder = User::factory()->create(['plan' => 'free']);

        $this->actingAs($intruder)
            ->delete(route('storage.recordings.destroy', $recap))
            ->assertForbidden();

        Storage::disk('s3')->assertExists($recap->path);
        $this->assertSame(1000, $recap->fresh()->size_bytes);
    }

    public function test_re_transcribing_is_blocked_once_the_audio_has_been_deleted(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create(['plan' => 'free', 'ai_credit_balance' => 1000]);
        $recap = $this->recordingFor($user, 1000);
        $session = $recap->session;

        $this->actingAs($user)->delete(route('storage.recordings.destroy', $recap))->assertRedirect();

        $this->actingAs($user)
            ->postJson(route('sessions.recap.retry', $session))
            ->assertStatus(422);
    }
}
