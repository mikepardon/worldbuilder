<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignCompendiumItem;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompendiumImageTest extends TestCase
{
    use RefreshDatabase;

    private function monster(User $gm, string $provider = 'custom'): CampaignCompendiumItem
    {
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);

        return $world->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'rat', 'name' => 'Dock Rat', 'provider' => $provider,
        ]);
    }

    public function test_uploading_an_image_attaches_media_and_counts_against_storage(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create(['plan' => 'free']);
        $item = $this->monster($gm);

        $this->actingAs($gm)->post(route('compendium.image', $item), [
            'file' => UploadedFile::fake()->image('rat.png', 10, 10),
        ])->assertOk()->assertJsonStructure(['image_url']);

        $item->refresh();
        $this->assertNotNull($item->image_media_id);
        $this->assertSame(1, Media::where('user_id', $gm->id)->count());
        $this->assertGreaterThan(0, $gm->fresh()->storageUsedBytes());
    }

    public function test_replacing_an_image_frees_the_previous_files_storage(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create(['plan' => 'free']);
        $item = $this->monster($gm);

        $this->actingAs($gm)->post(route('compendium.image', $item), ['file' => UploadedFile::fake()->image('a.png')])->assertOk();
        $firstMedia = $item->fresh()->image;

        $this->actingAs($gm)->post(route('compendium.image', $item), ['file' => UploadedFile::fake()->image('b.png')])->assertOk();

        // The old Media (and its stored file) is gone; only the replacement remains.
        $this->assertNull(Media::find($firstMedia->id));
        $this->assertSame(1, Media::where('user_id', $gm->id)->count());
        Storage::disk($firstMedia->disk)->assertMissing($firstMedia->path);
    }

    public function test_removing_an_image_detaches_it_and_frees_storage(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create(['plan' => 'free']);
        $item = $this->monster($gm);

        $this->actingAs($gm)->post(route('compendium.image', $item), ['file' => UploadedFile::fake()->image('a.png')])->assertOk();
        $media = $item->fresh()->image;

        $this->actingAs($gm)->delete(route('compendium.image.destroy', $item))
            ->assertOk()
            ->assertJsonPath('image_url', null);

        $this->assertNull($item->fresh()->image_media_id);
        $this->assertNull(Media::find($media->id));
        Storage::disk($media->disk)->assertMissing($media->path);
    }

    public function test_upload_is_blocked_when_over_the_storage_quota(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create(['plan' => 'free']);
        $item = $this->monster($gm);

        // Fill the account right up to its limit.
        Media::create([
            'user_id' => $gm->id, 'world_id' => $item->world_id, 'disk' => 'public',
            'path' => 'media/full.bin', 'filename' => 'full.bin', 'mime' => 'application/octet-stream',
            'size' => $gm->storageLimitBytes(),
        ]);

        $this->actingAs($gm)->post(route('compendium.image', $item), ['file' => UploadedFile::fake()->image('a.png')])
            ->assertStatus(422);

        $this->assertNull($item->fresh()->image_media_id);
    }

    public function test_an_imported_read_only_entry_rejects_image_uploads(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create(['plan' => 'free']);
        $imported = $this->monster($gm, provider: 'imported');

        $this->actingAs($gm)->post(route('compendium.image', $imported), ['file' => UploadedFile::fake()->image('a.png')])
            ->assertStatus(403);
    }

    public function test_a_user_cannot_upload_an_image_to_another_users_entry(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create(['plan' => 'free']);
        $item = $this->monster($gm);
        $intruder = User::factory()->create(['plan' => 'free']);

        $this->actingAs($intruder)->post(route('compendium.image', $item), ['file' => UploadedFile::fake()->image('a.png')])
            ->assertStatus(403);

        $this->assertNull($item->fresh()->image_media_id);
    }
}
