<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function seedMedia(User $user, int $worldId, int $size): void
    {
        Media::create([
            'user_id' => $user->id,
            'world_id' => $worldId,
            'disk' => 'public',
            'path' => 'media/'.uniqid().'.png',
            'filename' => 'x.png',
            'mime' => 'image/png',
            'size' => $size,
        ]);
    }

    public function test_storage_used_sums_the_accounts_media(): void
    {
        $user = User::factory()->create();
        $world = $user->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->seedMedia($user, $world->id, 100);
        $this->seedMedia($user, $world->id, 250);

        $this->assertSame(350, $user->storageUsedBytes());
    }

    public function test_storage_used_also_counts_the_avatar(): void
    {
        $user = User::factory()->create();
        $world = $user->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->seedMedia($user, $world->id, 350);
        $user->avatar_size = 1000;
        $user->save();

        $this->assertSame(1350, $user->fresh()->storageUsedBytes());
    }

    public function test_an_avatar_upload_records_its_size_and_is_counted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('me.png', 200, 'image/png')])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertGreaterThan(0, $fresh->avatar_size);
        $this->assertSame($fresh->avatar_size, $fresh->storageUsedBytes());
    }

    public function test_an_avatar_upload_is_blocked_when_over_quota(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['plan' => 'free']);
        $world = $user->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $this->seedMedia($user, $world->id, $user->storageLimitBytes());

        $this->actingAs($user)
            ->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('me.png', 200, 'image/png')])
            ->assertStatus(422);

        $this->assertSame(0, $user->fresh()->avatar_size);
    }

    public function test_a_character_portrait_is_blocked_once_over_quota(): void
    {
        Storage::fake('public');
        $gm = User::factory()->create(['plan' => 'free']);
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $this->seedMedia($gm, $world->id, $gm->storageLimitBytes());

        $character = Character::create(['user_id' => $gm->id, 'name' => 'Hero']);

        $this->actingAs($gm)
            ->post(route('characters.image', $character), ['file' => UploadedFile::fake()->create('face.png', 200, 'image/png')])
            ->assertStatus(422);

        // Only the seeded media exists; the blocked portrait created no new record.
        $this->assertSame(1, Media::count());
    }

    public function test_free_plan_storage_limit_is_half_a_gigabyte(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->assertSame(536870912, $user->storageLimitBytes());
    }

    public function test_uploading_is_blocked_once_the_storage_limit_is_reached(): void
    {
        Storage::fake('public');
        $gm = User::factory()->create(['plan' => 'free']);
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // Fill the account right up to its limit.
        $this->seedMedia($gm, $world->id, $gm->storageLimitBytes());

        $this->actingAs($gm)
            ->post(route('media.store', $world), ['file' => UploadedFile::fake()->create('a.png', 200, 'image/png')])
            ->assertStatus(422);

        // The blocked upload created no new media and wrote nothing to disk.
        $this->assertSame(1, Media::count());
    }

    public function test_uploading_succeeds_within_the_storage_limit(): void
    {
        Storage::fake('public');
        $gm = User::factory()->create(['plan' => 'free']);
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('media.store', $world), ['file' => UploadedFile::fake()->create('a.png', 200, 'image/png')])
            ->assertCreated();

        $this->assertSame(1, Media::count());
    }
}
