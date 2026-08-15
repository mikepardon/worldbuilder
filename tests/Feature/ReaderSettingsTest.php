<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReaderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_players_cannot_open_the_compendium_when_it_is_disabled(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['reader_show_compendium' => false],
        ]);

        $this->get(route('public.compendium', $world))->assertNotFound();
    }

    public function test_the_reader_head_reflects_the_toggles(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['reader_indexable' => false],
        ]);

        $this->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaign.noindex', true));
    }

    public function test_an_indexable_public_world_is_not_noindexed(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['reader_indexable' => true],
        ]);

        $this->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaign.noindex', false));
    }

    public function test_the_reader_carries_the_chosen_accent_theme(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['reader_theme' => 'crimson'],
        ]);

        $this->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaign.theme', 'crimson'));
    }

    public function test_an_unknown_accent_theme_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), ['reader_theme' => 'neon'])
            ->assertSessionHasErrors('reader_theme');
    }

    public function test_a_gm_can_set_custom_reader_colours(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'reader_bg' => '#101820', 'reader_heading' => '#E8C07A', 'reader_text' => '#c8ccd3',
        ])->assertSessionHasNoErrors();

        // Stored on the settings bag and exposed to the reader (lower-cased hex).
        $this->assertSame(['background' => '#101820', 'heading' => '#e8c07a', 'text' => '#c8ccd3'], $world->fresh()->readerColours());

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.colours.background', '#101820')
                ->where('campaign.colours.heading', '#e8c07a')
                ->where('campaign.colours.text', '#c8ccd3'));
    }

    public function test_a_malformed_reader_colour_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), ['reader_bg' => 'red; body{}'])
            ->assertSessionHasErrors('reader_bg');
    }

    public function test_a_gm_can_set_custom_reader_css_and_it_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $css = '.wb-title { letter-spacing: 0.02em; }';
        $this->actingAs($gm)->put(route('worlds.update', $world), ['reader_css' => $css])
            ->assertSessionHasNoErrors();

        $this->assertSame($css, $world->fresh()->readerCss());

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.css', $css));
    }

    public function test_oversized_reader_css_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), ['reader_css' => str_repeat('a', 20001)])
            ->assertSessionHasErrors('reader_css');
    }

    public function test_a_blank_reader_colour_falls_back_to_the_default_palette(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['reader_bg' => '#101820'],
        ]);

        $this->actingAs($gm)->put(route('worlds.update', $world), ['reader_bg' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($world->fresh()->readerColours()['background']);
    }

    public function test_player_notes_are_rejected_when_disabled(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['reader_allow_notes' => false],
        ]);
        $document = $world->documents()->create([
            'title' => 'A', 'slug' => 'a', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('notes.store', $document), ['body' => 'hi'])
            ->assertForbidden();
    }
}
