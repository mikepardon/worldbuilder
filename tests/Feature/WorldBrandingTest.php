<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_upload_a_reader_logo(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.branding', $world->id), [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertRedirect();

        $world->refresh();
        $this->assertNotNull($world->logo_media_id);
        $this->assertDatabaseHas('media', ['id' => $world->logo_media_id, 'world_id' => $world->id]);
    }

    public function test_uploading_a_banner_sets_only_the_banner(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.branding', $world->id), [
            'type' => 'banner',
            'file' => UploadedFile::fake()->image('banner.jpg', 800, 250),
        ])->assertRedirect();

        $world->refresh();
        $this->assertNotNull($world->banner_media_id);
        $this->assertNull($world->logo_media_id);
    }

    public function test_a_gm_can_upload_a_favicon(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.branding', $world->id), [
            'type' => 'favicon',
            'file' => UploadedFile::fake()->image('icon.png', 32, 32),
        ])->assertRedirect();

        $this->assertNotNull($world->refresh()->favicon_media_id);
    }

    public function test_the_social_image_uses_the_og_upload_and_falls_back_to_the_banner(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // With only a banner, the social image falls back to it.
        $this->actingAs($gm)->post(route('worlds.branding', $world->id), ['type' => 'banner', 'file' => UploadedFile::fake()->image('banner.png')]);
        $bannerUrl = $world->refresh()->banner?->url;
        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.ogImage', $bannerUrl));

        // A dedicated OG image overrides the banner.
        $this->actingAs($gm)->post(route('worlds.branding', $world->id), ['type' => 'og', 'file' => UploadedFile::fake()->image('og.png')]);
        $ogUrl = $world->refresh()->og?->url;
        $this->assertNotSame($bannerUrl, $ogUrl);
        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.ogImage', $ogUrl));
    }

    public function test_the_reader_head_exposes_the_uploaded_branding(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.branding', $world->id), ['type' => 'logo', 'file' => UploadedFile::fake()->image('logo.png')]);
        $this->actingAs($gm)->post(route('worlds.branding', $world->id), ['type' => 'banner', 'file' => UploadedFile::fake()->image('banner.png')]);
        $this->actingAs($gm)->post(route('worlds.branding', $world->id), ['type' => 'favicon', 'file' => UploadedFile::fake()->image('icon.png')]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.logo', fn ($value) => is_string($value) && $value !== '')
                ->where('campaign.banner', fn ($value) => is_string($value) && $value !== '')
                ->where('campaign.favicon', fn ($value) => is_string($value) && $value !== ''));
    }

    public function test_removing_the_logo_clears_it(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $this->actingAs($gm)->post(route('worlds.branding', $world->id), ['type' => 'logo', 'file' => UploadedFile::fake()->image('logo.png')]);

        $this->actingAs($gm)->delete(route('worlds.branding.clear', $world->id), ['type' => 'logo'])
            ->assertRedirect();

        $this->assertNull($world->refresh()->logo_media_id);
    }

    public function test_an_unknown_branding_type_is_rejected(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.branding', $world->id), [
            'type' => 'hero',
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertSessionHasErrors('type');
    }

    public function test_a_stranger_cannot_upload_branding(): void
    {
        Storage::fake(config('media.disk'));
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('worlds.branding', $world->id), [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertForbidden();

        $this->assertNull($world->refresh()->logo_media_id);
    }
}
