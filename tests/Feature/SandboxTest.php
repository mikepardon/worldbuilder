<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\World;
use App\Models\WorldBlock;
use Database\Seeders\SandboxWorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The seeder writes region/battle map SVGs to the public disk; keep it out of real storage.
        Storage::fake('public');
    }

    private function sandbox(): World
    {
        $this->seed(SandboxWorldSeeder::class);

        return World::where('is_sandbox', true)->firstOrFail();
    }

    public function test_the_seeder_builds_the_full_demo_world(): void
    {
        $sandbox = $this->sandbox();
        $mainCampaign = $sandbox->campaigns()->firstOrFail();

        $this->assertTrue($sandbox->is_sandbox);
        $this->assertSame('public', $sandbox->visibility);
        $this->assertSame(70, $sandbox->compendiumItems()->count(), '10 of each of the seven compendium types');
        $this->assertSame(10, $sandbox->compendiumItems()->where('item_type', 'monster')->count());
        $this->assertSame(10, $sandbox->compendiumItems()->where('item_type', 'spell')->count());
        $this->assertSame(24, $sandbox->documents()->where('kind', 'location')->count());
        $this->assertSame(41, $sandbox->documents()->whereIn('kind', ['npc', 'faction'])->count());
        // 5 authored lore entries plus the scheduled "The Warden's Gambit".
        $this->assertSame(6, $sandbox->documents()->where('kind', 'lore')->count());
        $this->assertSame(2, $sandbox->documents()->where('kind', 'timeline')->count());
        $this->assertSame(1, $sandbox->documents()->where('kind', 'bloodline')->count());
        $this->assertSame(5, $mainCampaign->sessions()->count());
        $this->assertSame(1, $sandbox->maps()->count());
        $this->assertSame(9, $sandbox->maps()->firstOrFail()->pins()->count());
        $this->assertSame(1, $mainCampaign->rooms()->count());
        $this->assertSame(5, $mainCampaign->rooms()->firstOrFail()->tokens()->count());
        $this->assertGreaterThanOrEqual(10, $sandbox->documentLinks()->count());
    }

    public function test_monster_stat_block_embeds_are_resolved_to_real_ids(): void
    {
        $sandbox = $this->sandbox();

        // No document still carries the raw placeholder, and at least one carries a resolved embed.
        $this->assertSame(
            0,
            $sandbox->documents()->where('content', 'like', '%{{MONSTER:%')->count(),
            'every {{MONSTER:slug}} placeholder is resolved',
        );
        $this->assertGreaterThanOrEqual(
            1,
            $sandbox->documents()->where('content', 'like', '%{{monster=%')->count(),
            'the bestiary entry embeds a real stat block',
        );
    }

    public function test_the_battle_scene_places_monster_tokens_with_stats(): void
    {
        $sandbox = $this->sandbox();
        $mainCampaign = $sandbox->campaigns()->firstOrFail();
        $room = $mainCampaign->rooms()->firstOrFail();
        $token = $room->tokens()->where('label', 'Bog Ghast')->first();

        $this->assertNotNull($token, 'the encounter includes a Bog Ghast');
        $this->assertNotNull($token->compendium_item_id, 'the token is linked to its compendium entry');
        $this->assertGreaterThan(0, $token->max_hp, 'the token carries its stat-block HP');
        $this->assertGreaterThan(0, $token->ac);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(SandboxWorldSeeder::class);
        $this->seed(SandboxWorldSeeder::class);

        $this->assertSame(1, World::where('is_sandbox', true)->count());
    }

    public function test_view_redirects_a_gm_to_the_player_perspective_reader(): void
    {
        $sandbox = $this->sandbox();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sandbox.view'))
            ->assertRedirect(route('public.world', $sandbox->slug));
    }

    public function test_view_is_not_found_when_the_sandbox_has_not_been_seeded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sandbox.view'))->assertNotFound();
    }

    public function test_an_admin_may_edit_the_seed_world_and_a_stranger_may_not(): void
    {
        $sandbox = $this->sandbox();
        $admin = User::factory()->create(['is_admin' => true]);
        $stranger = User::factory()->create();

        // The admin dashboard surfaces the seed world for editing.
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('seedWorld.id', $sandbox->id));

        // An admin may open its GM settings — the strictest, normally owner-only ability — via the admin
        // Gate::before, so they edit the default seed in the same workspace a GM uses.
        $this->actingAs($admin)->get(route('worlds.settings', $sandbox->id))->assertOk();

        // A signed-in user who neither owns nor plays in it may not.
        $this->actingAs($stranger)->get(route('worlds.settings', $sandbox->id))->assertForbidden();
    }

    public function test_cloning_gives_the_user_a_private_editable_copy(): void
    {
        $sandbox = $this->sandbox();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('sandbox.clone'));

        $copyWorld = World::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('worlds.show', $copyWorld));

        $copyMainCampaign = $copyWorld->campaigns()->firstOrFail();

        $this->assertFalse($copyWorld->is_sandbox, 'the copy is a normal world, not another sandbox');
        $this->assertSame('private', $copyWorld->visibility);
        $this->assertSame(70, $copyWorld->compendiumItems()->count());
        $this->assertSame(74, $copyWorld->documents()->count());
        $this->assertSame(9, $copyWorld->maps()->firstOrFail()->pins()->count());
        $this->assertSame(5, $copyMainCampaign->rooms()->firstOrFail()->tokens()->count());

        // The full GM toolset comes across: custom fields, roll tables, calendar, templates, reusable
        // blocks, and the world's own reader chrome.
        $this->assertSame(4, $copyWorld->customFields()->count());
        $this->assertSame(2, $copyWorld->rollTables()->count());
        $this->assertSame(1, $copyWorld->calendars()->count());
        $this->assertSame(2, $copyWorld->templates()->count());
        $this->assertSame(1, WorldBlock::where('world_id', $copyWorld->id)->count());
        $this->assertSame(['locations', 'people', 'lore', 'timelines'], array_keys($copyWorld->sectionImages()));

        // The Ankmier → Lalder reference field was remapped to the clone's own copies.
        $copyAnkmier = $copyWorld->documents()->where('slug', 'ankmier')->firstOrFail();
        $copyLalder = $copyWorld->documents()->where('slug', 'lalder')->firstOrFail();
        $this->assertSame($copyLalder->id, $copyAnkmier->data['patron']);

        // The original is untouched.
        $this->assertSame(70, $sandbox->compendiumItems()->count());
        $this->assertTrue(World::where('is_sandbox', true)->exists());
    }

    public function test_the_seeder_seeds_the_current_feature_set(): void
    {
        $sandbox = $this->sandbox();

        // Per-world custom fields, including a multi-value field and a reference relation.
        $this->assertSame(4, $sandbox->customFields()->count());
        $patron = $sandbox->customFields()->where('key', 'patron')->firstOrFail();
        $this->assertSame('reference', $patron->type);
        $this->assertSame(['npc'], $patron->ref_kinds);
        $this->assertTrue($sandbox->customFields()->where('key', 'exports')->firstOrFail()->multiple);

        // Roll tables, a calendar with events, a bloodline, a reusable block, and templates.
        $this->assertSame(2, $sandbox->rollTables()->count());
        $this->assertSame(3, $sandbox->calendars()->firstOrFail()->events()->count());
        $this->assertSame(1, WorldBlock::where('world_id', $sandbox->id)->count());
        $this->assertSame(2, $sandbox->templates()->count());
        $this->assertSame(['locations', 'people', 'lore', 'timelines'], array_keys($sandbox->sectionImages()));
        $this->assertNotNull($sandbox->banner_media_id);

        // The showcase entry carries the richer metadata and its custom-field values.
        $ankmier = $sandbox->documents()->where('slug', 'ankmier')->firstOrFail();
        $this->assertSame('#c98a3a', $ankmier->accent);
        $this->assertTrue($ankmier->show_toc);
        $this->assertTrue($ankmier->comments_enabled);
        $this->assertSame('Contested', $ankmier->data['threat']);
        $this->assertSame(['Art', 'Trade', 'Cuisine'], $ankmier->data['exports']);

        $lalder = $sandbox->documents()->where('slug', 'lalder')->firstOrFail();
        $this->assertSame($lalder->id, $ankmier->data['patron'], 'the patron reference points at the councillor NPC');

        // A scheduled entry that is GM-only until it publishes.
        $this->assertTrue(
            $sandbox->documents()->where('title', "The Warden's Gambit")->firstOrFail()->isScheduled(),
        );
    }

    public function test_cloned_embeds_point_at_the_cloned_compendium_not_the_sandbox(): void
    {
        $sandbox = $this->sandbox();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('sandbox.clone'));
        $copyWorld = World::where('user_id', $user->id)->firstOrFail();

        // The cloned Bog Ghast bestiary embed must reference the clone's own item id.
        $clonedSeaGhast = $copyWorld->compendiumItems()->where('slug', 'bog-ghast')->firstOrFail();
        $embed = Document::where('world_id', $copyWorld->id)
            ->where('content', 'like', '%{{monster=%')->firstOrFail();

        $this->assertStringContainsString("{{monster={$clonedSeaGhast->id}}}", $embed->content);
    }

    public function test_the_compendium_defaults_to_the_first_type_tab(): void
    {
        $sandbox = $this->sandbox();

        $props = $this->get(route('public.compendium', $sandbox->slug))
            ->assertOk()->viewData('page')['props'];

        // No "All" tab: it opens on the first type (spells) and shows only those.
        $this->assertSame('spell', $props['filters']['type']);
        $this->assertSame(10, $props['items']['total']);
        $this->assertTrue(collect($props['items']['data'])->every(fn ($i) => $i['item_type'] === 'spell'));
        $this->assertSame(['Level', 'School'], collect($props['columns'])->pluck('key')->all());
        $this->assertSame(7, count($props['types']), 'a tab per seeded type');
    }

    public function test_each_type_tab_shows_its_core_detail_columns(): void
    {
        $sandbox = $this->sandbox();
        $slug = $sandbox->slug;

        $monsters = $this->get(route('public.compendium', ['world' => $slug, 'type' => 'monster']))
            ->viewData('page')['props'];
        $this->assertSame(10, $monsters['items']['total']);
        $this->assertSame(['Type', 'CR'], collect($monsters['columns'])->pluck('key')->all());

        // A monster row carries its creature type and CR at a glance.
        $ghast = collect($monsters['items']['data'])->firstWhere('slug', 'bog-ghast');
        $this->assertArrayHasKey('CR', $ghast['facts']);
        $this->assertArrayHasKey('Type', $ghast['facts']);

        // A spell row carries its level + school.
        $spell = collect(
            $this->get(route('public.compendium', ['world' => $slug, 'type' => 'spell']))
                ->viewData('page')['props']['items']['data']
        )->firstWhere('slug', 'ember-bolt');
        $this->assertSame('Cantrip', $spell['facts']['Level']);
        $this->assertSame('Evocation', $spell['facts']['School']);
    }

    public function test_search_narrows_within_the_active_type(): void
    {
        $sandbox = $this->sandbox();

        $result = $this->get(route('public.compendium', ['world' => $sandbox->slug, 'type' => 'monster', 'q' => 'goblin']))
            ->viewData('page')['props']['items'];

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertTrue(
            collect($result['data'])->every(fn ($i) => str_contains(strtolower($i['name'].' '.$i['summary']), 'goblin')),
        );
    }

    public function test_a_hidden_or_inactive_compendium_item_is_not_shown_to_players(): void
    {
        $sandbox = $this->sandbox();
        $sandbox->compendiumItems()->where('slug', 'bog-ghast')->update(['is_private' => true]);
        $sandbox->compendiumItems()->where('slug', 'grove-goblin')->update(['is_active' => false]);

        $monsters = $this->get(route('public.compendium', ['world' => $sandbox->slug, 'type' => 'monster']))
            ->viewData('page')['props']['items'];
        $this->assertSame(8, $monsters['total'], 'the private and inactive monsters drop out');

        // Searching for them returns nothing, and the entry pages 404 for an anonymous reader.
        $search = $this->get(route('public.compendium', ['world' => $sandbox->slug, 'type' => 'monster', 'q' => 'Bog Ghast']))
            ->viewData('page')['props']['items'];
        $this->assertSame(0, $search['total'], 'a private item is not searchable by players');
        $this->get(route('public.compendium.item', [$sandbox->slug, 'bog-ghast']))->assertNotFound();
        $this->get(route('public.compendium.item', [$sandbox->slug, 'grove-goblin']))->assertNotFound();
    }

    public function test_a_compendium_entry_renders_its_stat_block_or_document(): void
    {
        $sandbox = $this->sandbox();

        $monster = $this->get(route('public.compendium.item', [$sandbox->slug, 'bog-ghast']))
            ->assertOk()->viewData('page')['props']['item'];
        $this->assertNotNull($monster['block'], 'a monster exposes its parsed stat block');

        $spell = $this->get(route('public.compendium.item', [$sandbox->slug, 'ember-bolt']))
            ->assertOk()->viewData('page')['props']['item'];
        $this->assertNull($spell['block']);
        $this->assertNotEmpty($spell['document'], 'a spell exposes its rendered document');
    }

    public function test_the_compendium_nav_flag_reflects_player_visibility(): void
    {
        $sandbox = $this->sandbox();

        $this->assertTrue(
            $this->get(route('public.world', $sandbox->slug))
                ->viewData('page')['props']['campaign']['hasCompendium'],
        );
    }

    public function test_cloning_duplicates_media_so_it_is_not_shared_with_the_sandbox(): void
    {
        $sandbox = $this->sandbox();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('sandbox.clone'));
        $copyWorld = World::where('user_id', $user->id)->firstOrFail();

        $sandboxMap = $sandbox->maps()->firstOrFail();
        $copyMap = $copyWorld->maps()->firstOrFail();

        $this->assertNotNull($copyMap->image_media_id);
        $this->assertNotSame(
            $sandboxMap->image_media_id,
            $copyMap->image_media_id,
            'the cloned map has its own media row',
        );
    }
}
