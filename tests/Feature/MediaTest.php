<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private function image(string $name = 'map.png'): UploadedFile
    {
        // A fake PNG payload — avoids requiring the GD extension in CI.
        return UploadedFile::fake()->create($name, 200, 'image/png');
    }

    public function test_a_gm_can_upload_into_a_world_they_own_and_set_it_as_cover(): void
    {
        Storage::fake('public');
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('media.store', $campaign), ['file' => $this->image()])
            ->assertCreated();

        $media = Media::sole();
        $this->assertSame($campaign->id, $media->world_id);

        $this->actingAs($gm)
            ->put(route('worlds.cover', $campaign), ['media_id' => $media->id])
            ->assertRedirect();

        $this->assertSame($media->id, $campaign->fresh()->cover_media_id);
    }

    public function test_a_user_cannot_upload_into_a_world_they_do_not_own(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $campaign = $owner->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('media.store', $campaign), ['file' => $this->image()])
            ->assertForbidden();

        $this->assertDatabaseCount('media', 0);
    }

    public function test_the_world_owner_can_delete_media_and_the_file_is_removed(): void
    {
        Storage::fake('public');
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $this->actingAs($gm)->post(route('media.store', $campaign), ['file' => $this->image()]);
        $media = Media::sole();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->delete(route('media.destroy', $media))->assertForbidden();
        Storage::disk('public')->assertExists($media->path);

        $this->actingAs($gm)->delete(route('media.destroy', $media))->assertRedirect();
        $this->assertDatabaseCount('media', 0);
        Storage::disk('public')->assertMissing($media->path);
    }
}
