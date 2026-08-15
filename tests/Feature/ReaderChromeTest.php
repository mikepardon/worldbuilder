<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReaderChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_custom_footer_line_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['reader_footer' => '© The Cartographer'],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.footer', '© The Cartographer'));
    }

    public function test_the_owner_gets_a_manage_link_on_their_reader_but_guests_do_not(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // A guest never sees the manage link.
        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.manageUrl', null));

        // The owner does.
        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.manageUrl', route('worlds.settings', $world->id)));
    }

    public function test_a_support_link_reaches_the_reader_with_a_default_label(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['support_url' => 'https://patreon.com/me'],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.support.url', 'https://patreon.com/me')
                ->where('campaign.support.label', 'Support this world'));
    }

    public function test_no_support_link_is_exposed_when_none_is_set(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.support', null));
    }

    public function test_nav_customisation_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world->id), [
            'nav_hidden' => ['timelines'],
            'nav_order' => ['people', 'locations'],
            'nav_links' => [['label' => 'Discord', 'url' => 'https://discord.gg/abc']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.nav.hidden', ['timelines'])
                ->where('campaign.nav.order', ['people', 'locations'])
                ->where('campaign.nav.links.0.label', 'Discord')
                ->where('campaign.nav.links.0.url', 'https://discord.gg/abc'));
    }

    public function test_the_nav_editor_lists_only_populated_sections(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Keep', 'kind' => 'location', 'slug' => 'the-keep',
        ]);

        // Only 'locations' has an entry, so the nav editor should list just that — not the empty section types.
        $this->actingAs($gm)->get(route('worlds.settings', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sectionCatalogue', [['slug' => 'locations', 'label' => 'Locations']]));
    }

    public function test_invalid_nav_links_are_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world->id), [
            'nav_links' => [['label' => 'Bad', 'url' => 'not-a-url']],
        ])->assertSessionHasErrors('nav_links.0.url');
    }

    public function test_llms_txt_is_served_as_markdown(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        $response->assertSee('Worldbuilder', false);
        $response->assertSee('/w/{world-slug}', false);
    }

    public function test_a_worlds_llms_txt_lists_public_entries_but_not_private_ones(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public', 'description' => 'A drowned coast.']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Amber Temple', 'kind' => 'location',
            'slug' => 'the-amber-temple', 'summary' => 'A frozen vault of dark secrets.', 'is_private' => false,
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The GM Only Vault', 'kind' => 'location',
            'slug' => 'gm-vault', 'is_private' => true,
        ]);

        $response = $this->get(route('public.llms', $world));

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        $response->assertSee('# Saltmere', false);
        $response->assertSee('A drowned coast.', false);
        $response->assertSee('The Amber Temple', false);
        $response->assertSee("/w/{$world->slug}/location/the-amber-temple", false);
        $response->assertDontSee('The GM Only Vault', false);
    }

    public function test_a_private_worlds_llms_txt_is_not_found(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Secret', 'visibility' => 'private']);

        $this->get(route('public.llms', $world))->assertNotFound();
    }
}
