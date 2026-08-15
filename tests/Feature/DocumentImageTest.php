<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\World;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_upload_a_card_and_banner_for_an_entry(): void
    {
        Storage::fake(config('media.disk'));
        [$gm, $document] = $this->entry();

        $this->actingAs($gm)->post(route('documents.image', $document), [
            'type' => 'card', 'file' => UploadedFile::fake()->image('card.png'),
        ])->assertRedirect();
        $this->actingAs($gm)->post(route('documents.image', $document), [
            'type' => 'banner', 'file' => UploadedFile::fake()->image('banner.png'),
        ])->assertRedirect();

        $document->refresh();
        $this->assertNotNull($document->card_media_id);
        $this->assertNotNull($document->banner_media_id);
    }

    public function test_the_banner_reaches_the_reader_article(): void
    {
        Storage::fake(config('media.disk'));
        [$gm, $document, $world] = $this->entry();
        $this->actingAs($gm)->post(route('documents.image', $document), [
            'type' => 'banner', 'file' => UploadedFile::fake()->image('banner.png'),
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, $document->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.banner_url', fn ($value) => is_string($value) && $value !== ''));
    }

    public function test_an_unknown_image_type_is_rejected(): void
    {
        Storage::fake(config('media.disk'));
        [$gm, $document] = $this->entry();

        $this->actingAs($gm)->post(route('documents.image', $document), [
            'type' => 'hero', 'file' => UploadedFile::fake()->image('x.png'),
        ])->assertSessionHasErrors('type');
    }

    public function test_removing_an_image_clears_it(): void
    {
        Storage::fake(config('media.disk'));
        [$gm, $document] = $this->entry();
        $this->actingAs($gm)->post(route('documents.image', $document), [
            'type' => 'card', 'file' => UploadedFile::fake()->image('card.png'),
        ]);

        $this->actingAs($gm)->delete(route('documents.image.clear', $document), ['type' => 'card'])
            ->assertRedirect();

        $this->assertNull($document->refresh()->card_media_id);
    }

    public function test_a_stranger_cannot_upload_an_entry_image(): void
    {
        Storage::fake(config('media.disk'));
        [, $document] = $this->entry();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('documents.image', $document), [
            'type' => 'card', 'file' => UploadedFile::fake()->image('card.png'),
        ])->assertForbidden();
    }

    /** @return array{0: User, 1: Document, 2: World} */
    private function entry(): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Keep', 'kind' => 'location',
            'slug' => 'the-keep', 'is_private' => false,
        ]);

        return [$gm, $document, $world];
    }
}
