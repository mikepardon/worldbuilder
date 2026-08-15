<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_upload_a_profile_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('me.png', 100, 'image/png')])
            ->assertOk();

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertNotNull($user->avatar_url);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_uploading_a_new_image_replaces_the_old_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('a.png', 100, 'image/png')]);
        $first = $user->refresh()->avatar_path;

        $this->actingAs($user)->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('b.png', 100, 'image/png')]);
        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_user_can_remove_their_profile_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('me.png', 100, 'image/png')]);
        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect();

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar'), ['file' => UploadedFile::fake()->create('virus.exe', 100, 'application/octet-stream')])
            ->assertStatus(422);

        $this->assertNull($user->refresh()->avatar_path);
    }
}
