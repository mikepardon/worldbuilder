<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\NavMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_save_a_nav_menu_tree(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'nav_menu' => [
                ['id' => 'a', 'type' => 'page', 'label' => 'Home', 'target' => 'overview', 'children' => [
                    ['id' => 'b', 'type' => 'link', 'label' => 'Blog', 'target' => 'https://example.test', 'children' => []],
                ]],
            ],
        ])->assertSessionHasNoErrors();

        $menu = $world->fresh()->readerMenu();
        $this->assertCount(1, $menu);
        $this->assertSame('page', $menu[0]['type']);
        $this->assertSame('overview', $menu[0]['target']);
        $this->assertSame('Home', $menu[0]['label']);
        $this->assertCount(1, $menu[0]['children']);
        $this->assertSame('https://example.test', $menu[0]['children'][0]['target']);
    }

    public function test_saving_drops_nodes_of_an_unknown_type(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->put(route('worlds.update', $world), [
            'nav_menu' => [
                ['id' => 'a', 'type' => 'bogus', 'label' => 'Nope', 'target' => 'x', 'children' => []],
                ['id' => 'b', 'type' => 'section', 'label' => 'Locations', 'target' => 'locations', 'children' => []],
            ],
        ])->assertSessionHasNoErrors();

        $menu = $world->fresh()->readerMenu();
        $this->assertCount(1, $menu);
        $this->assertSame('section', $menu[0]['type']);
    }

    public function test_the_sanitiser_caps_nesting_depth(): void
    {
        // Five levels deep — only the first four survive.
        $deep = ['id' => 'l5', 'type' => 'link', 'label' => 'L5', 'target' => 'https://e.test/5', 'children' => []];
        for ($level = 4; $level >= 1; $level--) {
            $deep = ['id' => "l{$level}", 'type' => 'link', 'label' => "L{$level}", 'target' => "https://e.test/{$level}", 'children' => [$deep]];
        }

        $clean = NavMenu::sanitise([$deep]);

        $depth = 0;
        $node = $clean[0];
        while ($node !== null) {
            $depth++;
            $node = $node['children'][0] ?? null;
        }
        $this->assertSame(4, $depth);
    }

    public function test_an_empty_heading_group_survives_sanitising(): void
    {
        // A label-only dropdown heading has no target and may have no children yet — but must be kept.
        $clean = NavMenu::sanitise([
            ['id' => 'g', 'type' => 'group', 'label' => 'Rulebook', 'target' => '', 'children' => []],
            ['id' => 'x', 'type' => 'link', 'label' => 'Dead', 'target' => '', 'children' => []],
        ]);

        $this->assertCount(1, $clean);
        $this->assertSame('group', $clean[0]['type']);
        $this->assertSame('Rulebook', $clean[0]['label']);
    }

    public function test_a_world_without_a_saved_menu_falls_back_to_a_default(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'Town', 'slug' => 'town', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);

        $menu = $world->readerMenuOrDefault();
        $targets = collect($menu)->map(fn (array $node): string => "{$node['type']}:{$node['target']}");

        $this->assertTrue($targets->contains('page:overview'));
        $this->assertTrue($targets->contains('section:locations'));
        $this->assertTrue($targets->contains('page:campaigns'));
    }

    public function test_the_reader_resolves_linked_entry_titles_for_a_gm(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'Town', 'slug' => 'town', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);
        $world->documents()->create([
            'title' => 'Secret', 'slug' => 'secret', 'kind' => 'location', 'content' => 'x', 'is_private' => true,
        ]);
        $world->settings = ['nav_menu' => [
            ['id' => 'a', 'type' => 'entry', 'label' => '', 'target' => 'location:town', 'children' => []],
            ['id' => 'b', 'type' => 'entry', 'label' => '', 'target' => 'location:secret', 'children' => []],
        ]];
        $world->save();

        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.navEntries.location:town.title', 'Town')
                ->where('campaign.navEntries.location:secret.title', 'Secret'));
    }

    public function test_the_reader_hides_a_private_linked_entry_from_the_public(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'Town', 'slug' => 'town', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);
        $world->documents()->create([
            'title' => 'Secret', 'slug' => 'secret', 'kind' => 'location', 'content' => 'x', 'is_private' => true,
        ]);
        $world->settings = ['nav_menu' => [
            ['id' => 'a', 'type' => 'entry', 'label' => '', 'target' => 'location:town', 'children' => []],
            ['id' => 'b', 'type' => 'entry', 'label' => '', 'target' => 'location:secret', 'children' => []],
        ]];
        $world->save();

        $this->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('campaign.navEntries.location:town')
                ->missing('campaign.navEntries.location:secret'));
    }
}
