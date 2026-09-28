<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignCompendiumItem;
use App\Models\Character;
use App\Models\CompendiumItem;
use App\Models\RuleSystem;
use App\Models\User;
use App\Support\DemoRuleSystem;
use Database\Seeders\CompendiumSourceSeeder;
use Database\Seeders\TtrpgSystemSeeder;
use Database\Seeders\WorldbuilderContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RuleSystemFlowTest extends TestCase
{
    use RefreshDatabase;

    private function template(): RuleSystem
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $system = RuleSystem::create([
            'user_id' => $admin->id,
            'is_template' => true,
            'name' => 'Ascendancy',
            'settings' => DemoRuleSystem::defaultSettings(),
        ]);
        (new DemoRuleSystem)->populate($system);

        return $system;
    }

    public function test_the_starter_blueprint_builds_a_complete_playable_system(): void
    {
        $system = $this->template();

        $this->assertSame(5, $system->stats()->count());
        $this->assertSame(15, $system->skills()->count());
        $this->assertSame(2, $system->resources()->count());
        $this->assertSame(20, $system->levels()->count());
        $this->assertSame(1, $system->webs()->count());

        $web = $system->webs()->first();
        $this->assertGreaterThan(20, $web->nodes()->count());
        $this->assertSame(1, $web->nodes()->whereJsonContains('config->origin', true)->count());
        // Every gateway wires back to the origin, so a fresh build can reach them.
        $this->assertGreaterThanOrEqual(5, $web->edges()->count());
    }

    public function test_an_admin_creates_a_starter_template_and_opens_the_builder(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.rule-systems.store'), [
            'name' => 'My System',
            'starter' => true,
        ]);

        $system = RuleSystem::where('name', 'My System')->sole();
        $this->assertTrue($system->is_template);
        $this->assertSame(5, $system->stats()->count());
        $response->assertRedirect(route('rule-systems.build', $system));
    }

    public function test_a_non_admin_cannot_reach_the_template_library(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.rule-systems.index'))->assertForbidden();
    }

    public function test_a_gm_clones_a_template_into_their_world_with_all_nodes(): void
    {
        $template = $this->template();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);

        $this->actingAs($gm)->post(route('rule-systems.clone', [$world, $template]))->assertRedirect();

        $copy = $world->ruleSystems()->sole();
        $this->assertSame($template->id, $copy->template_source_id);
        $this->assertFalse($copy->is_template);
        $this->assertSame(
            $template->webs()->first()->nodes()->count(),
            $copy->webs()->first()->nodes()->count(),
        );
    }

    public function test_a_gm_enables_a_system_on_a_campaign(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $campaign = $world->campaigns()->create(['name' => 'Play']);

        $this->actingAs($gm)->put(route('campaigns.rule-system', $campaign), [
            'rule_system_id' => $system->id,
        ])->assertRedirect();

        $this->assertSame($system->id, $campaign->refresh()->rule_system_id);
    }

    public function test_a_campaign_cannot_be_pointed_at_another_worlds_system(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $campaign = $world->campaigns()->create(['name' => 'Play']);
        $otherWorld = $gm->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'private']);
        $foreign = $otherWorld->ruleSystems()->create(['name' => 'Foreign', 'settings' => DemoRuleSystem::defaultSettings()]);

        $this->actingAs($gm)->put(route('campaigns.rule-system', $campaign), [
            'rule_system_id' => $foreign->id,
        ])->assertSessionHasErrors('rule_system_id');
    }

    public function test_a_player_allocates_a_gateway_through_the_http_endpoint(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        (new DemoRuleSystem)->populate($system);

        /** @var Campaign $campaign */
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        /** @var Character $character */
        $character = $campaign->characters()->create(['name' => 'Bront', 'user_id' => $player->id, 'level' => 5]);

        $gateway = $system->webs()->first()->nodes()->where('key', 'phy-gateway')->sole();

        $this->actingAs($player)->post(route('character-build.allocate', $character), [
            'node_id' => $gateway->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $build = $character->builds()->where('rule_system_id', $system->id)->sole();
        $this->assertDatabaseHas('character_talents', [
            'character_build_id' => $build->id,
            'talent_node_id' => $gateway->id,
        ]);
    }

    public function test_a_stranger_cannot_build_someone_elses_character(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        (new DemoRuleSystem)->populate($system);
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        $character = $campaign->characters()->create(['name' => 'Bront', 'user_id' => $gm->id, 'level' => 5]);
        $gateway = $system->webs()->first()->nodes()->where('key', 'phy-gateway')->sole();

        $this->actingAs($stranger)->post(route('character-build.allocate', $character), [
            'node_id' => $gateway->id,
        ])->assertForbidden();
    }

    public function test_adding_a_connected_node_creates_the_node_and_its_edge(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $origin = $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'minor', 'cost' => 0]);

        $this->actingAs($gm)->post(route('talent-nodes.store', $web->id), [
            'name' => 'Branch',
            'kind' => 'minor',
            'x' => 100,
            'y' => 100,
            'gate_level' => 1,
            'cost' => 1,
            'connect_to' => $origin->id,
        ])->assertRedirect();

        $branch = $web->nodes()->where('name', 'Branch')->sole();
        $this->assertSame(1, $web->edges()->where('from_node_id', $origin->id)->where('to_node_id', $branch->id)->count());
    }

    public function test_adding_a_master_creates_a_ringed_cluster_with_a_disc(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $system->nodeKinds()->create(['key' => 'keystone', 'label' => 'Keystone', 'default_cost' => 3]);
        $web = $system->webs()->create(['name' => 'Core']);
        $origin = $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'minor', 'cost' => 0]);

        $this->actingAs($gm)->post(route('talent-nodes.cluster', $web->id), [
            'connect_to' => $origin->id,
            'x' => 1600, 'y' => 1300,
            'master_kind' => 'keystone', 'ring_kind' => 'minor', 'ring_count' => 6,
        ])->assertRedirect();

        // origin + master + 6 ring nodes.
        $this->assertSame(8, $web->nodes()->count());
        $this->assertSame(1, $web->nodes()->where('kind', 'keystone')->count());
        // origin→master (1) + master→ring (6) + ring hexagon (6) = 13 edges.
        $this->assertSame(13, $web->edges()->count());
        // A backing disc was added at the cluster centre.
        $this->assertSame(1, collect($web->fresh()->layout['discs'])->where('x', 1600)->count());
    }

    public function test_a_disconnected_cluster_is_refused_on_a_non_empty_web(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $system->nodeKinds()->create(['key' => 'keystone', 'label' => 'Keystone', 'default_cost' => 3]);
        $web = $system->webs()->create(['name' => 'Core']);
        $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'minor', 'cost' => 0]);

        $this->actingAs($gm)->post(route('talent-nodes.cluster', $web->id), [
            'x' => 1600, 'y' => 1300, 'master_kind' => 'keystone', 'ring_kind' => 'minor',
        ])->assertStatus(422);
    }

    public function test_the_first_cluster_on_an_empty_web_becomes_the_root(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $system->nodeKinds()->create(['key' => 'keystone', 'label' => 'Keystone', 'default_cost' => 3]);
        $web = $system->webs()->create(['name' => 'Core']);

        $this->actingAs($gm)->post(route('talent-nodes.cluster', $web->id), [
            'x' => 1300, 'y' => 1300, 'master_kind' => 'keystone', 'ring_kind' => 'minor', 'is_root' => true,
        ])->assertRedirect();

        $master = $web->nodes()->where('kind', 'keystone')->sole();
        $this->assertTrue((bool) ($master->config['origin'] ?? false));
    }

    public function test_updating_a_node_changes_its_kind_and_glyph(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $system->nodeKinds()->create(['key' => 'keystone', 'label' => 'Keystone', 'default_cost' => 3, 'glyph' => '★']);
        $web = $system->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'n1', 'name' => 'Node', 'kind' => 'minor', 'cost' => 1]);

        $this->actingAs($gm)->put(route('talent-nodes.update', $node->id), [
            'name' => 'Node', 'kind' => 'keystone', 'x' => 10, 'y' => 10,
            'gate_level' => 1, 'cost' => 3, 'config' => ['glyph' => '✦'],
        ])->assertRedirect();

        $node->refresh();
        $this->assertSame('keystone', $node->kind);
        $this->assertSame('✦', $node->config['glyph']);
    }

    public function test_adding_a_child_to_a_master_spaces_it_in_the_ring(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        // A cluster master (default layout_role 'master') at the web centre.
        $master = $web->nodes()->create(['key' => 'src', 'name' => 'Src', 'kind' => 'minor', 'cost' => 1, 'x' => 1300, 'y' => 1300]);

        foreach (['A', 'B'] as $ignored) {
            $this->actingAs($gm)->post(route('talent-nodes.store', $web->id), [
                'name' => 'New node', 'kind' => 'minor', 'x' => 1600, 'y' => 1300,
                'gate_level' => 1, 'cost' => 1,
                'layout_role' => 'child', 'parent_node_id' => $master->id, 'connect_to' => $master->id,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $children = $web->nodes()->where('parent_node_id', $master->id)->where('layout_role', 'child')->get();
        $this->assertCount(2, $children);

        // A child of a master joins the ring inside the disc — one ring-radius (86) from the master…
        foreach ($children as $child) {
            $distance = sqrt(($child->x - 1300) ** 2 + ($child->y - 1300) ** 2);
            $this->assertEqualsWithDelta(86.0, $distance, 2.0);
        }

        // …spaced to distinct spots rather than stacking.
        $this->assertNotEquals([$children[0]->x, $children[0]->y], [$children[1]->x, $children[1]->y]);
    }

    public function test_adding_a_child_to_a_branch_node_fans_it_outward(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        // A branch node (not a master) due-right of centre — its children fan outward, not into a ring.
        $branch = $web->nodes()->create(['key' => 'br', 'name' => 'Branch', 'kind' => 'minor', 'cost' => 1, 'x' => 1500, 'y' => 1300, 'layout_role' => 'child']);

        $this->actingAs($gm)->post(route('talent-nodes.store', $web->id), [
            'name' => 'New node', 'kind' => 'minor', 'x' => 1600, 'y' => 1300,
            'gate_level' => 1, 'cost' => 1,
            'layout_role' => 'child', 'parent_node_id' => $branch->id, 'connect_to' => $branch->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $child = $web->nodes()->where('parent_node_id', $branch->id)->where('layout_role', 'child')->sole();
        $this->assertEqualsWithDelta(120.0, sqrt(($child->x - 1500) ** 2 + ($child->y - 1300) ** 2), 2.0);
    }

    public function test_dragging_a_master_persists_its_moved_cluster_disc(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core', 'layout' => ['discs' => [['x' => 1300, 'y' => 1300, 'size' => 232]]]]);
        $master = $web->nodes()->create(['key' => 'm', 'name' => 'M', 'kind' => 'minor', 'cost' => 1, 'x' => 1300, 'y' => 1300, 'layout_role' => 'master']);

        $this->actingAs($gm)->put(route('talent-nodes.positions', $web->id), [
            'positions' => [['id' => $master->id, 'x' => 1500, 'y' => 1500]],
            'layout' => ['discs' => [['x' => 1500, 'y' => 1500, 'size' => 232]]],
        ])->assertRedirect();

        $this->assertSame(1500, $master->refresh()->x);
        $this->assertSame(1500, $web->refresh()->layout['discs'][0]['x'], 'the cluster disc moved with the master');
    }

    public function test_a_cluster_marks_its_master_free_and_its_ring_as_followers(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $system->nodeKinds()->create(['key' => 'keystone', 'label' => 'Keystone', 'default_cost' => 3]);
        $web = $system->webs()->create(['name' => 'Core']);

        $this->actingAs($gm)->post(route('talent-nodes.cluster', $web->id), [
            'x' => 1300, 'y' => 1300, 'master_kind' => 'keystone', 'ring_kind' => 'minor', 'ring_count' => 6, 'is_root' => true,
        ])->assertRedirect();

        $master = $web->nodes()->where('kind', 'keystone')->sole();
        $this->assertSame('master', $master->layout_role);
        $this->assertNull($master->parent_node_id);

        $ring = $web->nodes()->where('layout_role', 'ring')->get();
        $this->assertCount(6, $ring);
        $this->assertTrue($ring->every(fn ($node): bool => $node->parent_node_id === $master->id));
    }

    public function test_the_arrange_endpoint_spreads_a_hubs_children_evenly(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $source = $web->nodes()->create(['key' => 'src', 'name' => 'Src', 'kind' => 'minor', 'cost' => 1, 'x' => 1500, 'y' => 1300]);
        // Three children all dumped on the same spot — the arrange call should fan them apart.
        foreach (range(1, 3) as $index) {
            $web->nodes()->create([
                'key' => "c{$index}", 'name' => "C{$index}", 'kind' => 'minor', 'cost' => 1,
                'x' => 1500, 'y' => 1400, 'layout_role' => 'child', 'parent_node_id' => $source->id,
            ]);
        }

        $this->actingAs($gm)->post(route('talent-nodes.arrange', $web->id), ['parent_id' => $source->id])
            ->assertRedirect();

        $children = $web->nodes()->where('parent_node_id', $source->id)->get();
        $spots = $children->map(fn ($node): string => "{$node->x},{$node->y}")->unique();
        $this->assertCount(3, $spots, 'each child should land on its own spot');
        // The source is a master, so its children space evenly around it at the ring radius.
        foreach ($children as $child) {
            $this->assertEqualsWithDelta(86.0, sqrt(($child->x - 1500) ** 2 + ($child->y - 1300) ** 2), 2.0);
        }
    }

    public function test_a_node_can_be_linked_to_a_spell_from_its_worlds_compendium(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'vm', 'name' => 'Vicious Mockery', 'kind' => 'minor', 'cost' => 1]);
        $spell = CampaignCompendiumItem::create([
            'world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'vicious-mockery',
            'name' => 'Vicious Mockery', 'fields' => ['level' => 'Cantrip', 'casting_time' => '1 action'],
            'is_private' => false, 'is_active' => true,
        ]);

        $this->actingAs($gm)->put(route('talent-nodes.update', $node->id), [
            'name' => 'Vicious Mockery', 'kind' => 'minor', 'x' => 0, 'y' => 0, 'gate_level' => 1, 'cost' => 1,
            'compendium_item_id' => $spell->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($spell->id, $node->refresh()->compendium_item_id);
    }

    public function test_a_node_cannot_link_to_a_compendium_item_from_another_world(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'n', 'name' => 'Node', 'kind' => 'minor', 'cost' => 1]);
        $elsewhere = $gm->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'private']);
        $foreign = CampaignCompendiumItem::create([
            'world_id' => $elsewhere->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'foreign',
            'name' => 'Foreign Spell', 'is_private' => false, 'is_active' => true,
        ]);

        $this->actingAs($gm)->put(route('talent-nodes.update', $node->id), [
            'name' => 'Node', 'kind' => 'minor', 'x' => 0, 'y' => 0, 'gate_level' => 1, 'cost' => 1,
            'compendium_item_id' => $foreign->id,
        ])->assertSessionHasErrors('compendium_item_id');

        $this->assertNull($node->refresh()->compendium_item_id);
    }

    public function test_the_builder_exposes_world_spells_and_a_linked_node_carries_the_entry(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $spell = CampaignCompendiumItem::create([
            'world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'firebolt',
            'name' => 'Firebolt', 'fields' => ['level' => 'Cantrip'], 'is_private' => false, 'is_active' => true,
        ]);
        $web->nodes()->create(['key' => 'fb', 'name' => 'Firebolt', 'kind' => 'minor', 'cost' => 1, 'compendium_item_id' => $spell->id]);

        $this->actingAs($gm)->get(route('rule-systems.build', $system))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RuleSystems/Builder')
                ->where('compendium.0.name', 'Firebolt')
                ->where('system.webs.0.nodes.0.compendium_item.name', 'Firebolt'));
    }

    public function test_a_grant_effect_keeps_a_valid_world_entry_and_drops_foreign_or_mistyped_ones(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'n', 'name' => 'N', 'kind' => 'minor', 'cost' => 1]);

        $spell = CampaignCompendiumItem::create(['world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Firebolt', 'is_private' => false, 'is_active' => true]);
        $elsewhere = $gm->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'private']);
        $foreign = CampaignCompendiumItem::create(['world_id' => $elsewhere->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'fx', 'name' => 'Foreign', 'is_private' => false, 'is_active' => true]);

        $this->actingAs($gm)->put(route('talent-nodes.update', $node->id), [
            'name' => 'N', 'kind' => 'minor', 'x' => 0, 'y' => 0, 'gate_level' => 1, 'cost' => 1,
            'effects' => [
                ['type' => 'spell', 'item_id' => $spell->id],       // valid → kept
                ['type' => 'spell', 'item_id' => $foreign->id],     // another world → dropped
                ['type' => 'feat', 'item_id' => $spell->id],        // wrong type (a spell under feat) → dropped
                ['type' => 'ability', 'item_id' => 999999],         // does not exist → dropped
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([['type' => 'spell', 'item_id' => $spell->id]], $node->refresh()->effects);
    }

    public function test_a_level_spell_effect_is_kept_for_a_valid_spell_and_dropped_for_other_references(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'n', 'name' => 'N', 'kind' => 'minor', 'cost' => 1]);

        $spell = CampaignCompendiumItem::create(['world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Firebolt', 'is_private' => false, 'is_active' => true]);
        $feat = CampaignCompendiumItem::create(['world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'feat', 'slug' => 'al', 'name' => 'Alert', 'is_private' => false, 'is_active' => true]);

        $this->actingAs($gm)->put(route('talent-nodes.update', $node->id), [
            'name' => 'N', 'kind' => 'minor', 'x' => 0, 'y' => 0, 'gate_level' => 1, 'cost' => 1,
            'effects' => [
                ['type' => 'level_spell', 'item_id' => $spell->id, 'to_level' => 4],  // a real spell → kept with its rank
                ['type' => 'level_spell', 'item_id' => $feat->id, 'to_level' => 2],    // a feat, not a spell → dropped
                ['type' => 'level_spell', 'item_id' => 999999, 'to_level' => 3],       // does not exist → dropped
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([['type' => 'level_spell', 'item_id' => $spell->id, 'to_level' => 4]], $node->refresh()->effects);
    }

    public function test_the_seeded_template_has_a_node_that_advances_a_spell_to_a_higher_rank(): void
    {
        $this->seed(CompendiumSourceSeeder::class);
        $this->seed(WorldbuilderContentSeeder::class);
        $this->seed(TtrpgSystemSeeder::class);

        $system = RuleSystem::where('slug', 'ascendancy')->where('is_template', true)->sole();
        $web = $system->webs()->first();

        // The Firebolt node grants the Fire spell; the Fireball node advances that same spell to rank 4.
        $firebolt = $web->nodes()->where('name', 'Firebolt')->first();
        $grant = collect($firebolt->effects)->firstWhere(fn ($effect) => ($effect['type'] ?? '') === 'spell');

        $fireball = $web->nodes()->where('name', 'Fireball')->first();
        $advance = collect($fireball->effects)->firstWhere(fn ($effect) => ($effect['type'] ?? '') === 'level_spell');

        $this->assertNotNull($advance, 'the Fireball node carries a spell-level advance');
        $this->assertSame($grant['item_id'], $advance['item_id'], 'it advances the very spell the Firebolt node teaches');
        $this->assertSame(4, $advance['to_level']);
    }

    public function test_choose_one_variant_effects_are_sanitised_like_node_effects(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'ea', 'name' => 'Elemental Attunement', 'kind' => 'minor', 'cost' => 2]);
        $spell = CampaignCompendiumItem::create(['world_id' => $world->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'fb', 'name' => 'Firebolt', 'is_private' => false, 'is_active' => true]);
        $elsewhere = $gm->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'private']);
        $foreign = CampaignCompendiumItem::create(['world_id' => $elsewhere->id, 'user_id' => $gm->id, 'item_type' => 'spell', 'slug' => 'fx', 'name' => 'Foreign', 'is_private' => false, 'is_active' => true]);

        $this->actingAs($gm)->put(route('talent-nodes.update', $node->id), [
            'name' => 'Elemental Attunement', 'kind' => 'minor', 'x' => 0, 'y' => 0, 'gate_level' => 1, 'cost' => 2,
            'options' => [
                ['key' => 'fire', 'name' => 'Fire', 'desc' => 'Targets burn.', 'effects' => [
                    ['type' => 'resource', 'key' => 'hp', 'delta' => 1],
                    ['type' => 'spell', 'item_id' => $spell->id],      // valid → kept
                    ['type' => 'spell', 'item_id' => $foreign->id],    // another world → dropped
                ]],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $node->refresh();
        $this->assertSame('fire', $node->options[0]['key']);
        $this->assertSame([
            ['type' => 'resource', 'key' => 'hp', 'delta' => 1],
            ['type' => 'spell', 'item_id' => $spell->id],
        ], $node->options[0]['effects']);
    }

    public function test_an_any_requirement_group_lets_a_node_be_taken_when_one_branch_is_met(): void
    {
        [$gm, $character, $target] = $this->buildWithRequirement([
            'op' => 'any',
            'children' => [
                ['type' => 'stat', 'key' => 'str', 'min' => 99],   // unmet
                ['count' => 1, 'kind' => 'origin'],                // met — the origin is auto-allocated
            ],
        ]);

        $this->actingAs($gm)->post(route('character-build.allocate', $character), ['node_id' => $target->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('character_talents', ['talent_node_id' => $target->id]);
    }

    public function test_an_all_requirement_group_blocks_a_node_when_a_branch_is_unmet(): void
    {
        [$gm, $character, $target] = $this->buildWithRequirement([
            'op' => 'all',
            'children' => [
                ['type' => 'stat', 'key' => 'str', 'min' => 99],   // unmet → the whole ALL group fails
                ['count' => 1, 'kind' => 'origin'],
            ],
        ]);

        $this->actingAs($gm)->post(route('character-build.allocate', $character), ['node_id' => $target->id])
            ->assertSessionHasErrors('talent');

        $this->assertDatabaseMissing('character_talents', ['talent_node_id' => $target->id]);
    }

    /**
     * A world system with an origin and a target node (connected, gated by the given requirements),
     * plus a level-5 character on a campaign that uses it.
     *
     * @param  array<string, mixed>  $requires
     * @return array{0: User, 1: Character, 2: \App\Models\TalentNode}
     */
    private function buildWithRequirement(array $requires): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create([
            'name' => 'Homebrew',
            'settings' => array_merge(DemoRuleSystem::defaultSettings(), ['starting_points' => 5]),
        ]);
        $system->stats()->create(['key' => 'str', 'label' => 'Strength', 'default_value' => 3]);
        $system->nodeKinds()->create(['key' => 'origin', 'label' => 'Origin', 'default_cost' => 0]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $origin = $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'origin', 'cost' => 0, 'config' => ['origin' => true]]);
        $target = $web->nodes()->create(['key' => 't', 'name' => 'Target', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 1, 'config' => ['requires' => $requires]]);
        $web->edges()->create(['from_node_id' => $origin->id, 'to_node_id' => $target->id]);
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        $character = $campaign->characters()->create(['name' => 'Aria', 'user_id' => $gm->id, 'level' => 5]);

        return [$gm, $character, $target];
    }

    public function test_the_seeded_talent_web_template_grants_library_spells_and_abilities(): void
    {
        $this->seed(CompendiumSourceSeeder::class);
        $this->seed(WorldbuilderContentSeeder::class);
        $this->seed(TtrpgSystemSeeder::class);

        $system = RuleSystem::where('slug', 'ascendancy')->where('is_template', true)->sole();
        $this->assertNotEmpty($system->settings['compendium_sources'] ?? [], 'the template declares its prerequisite compendiums');

        $web = $system->webs()->first();
        $granting = $web->nodes()->get()->filter(
            fn ($node) => collect($node->effects ?? [])->contains(fn ($effect) => in_array($effect['type'] ?? '', ['spell', 'ability', 'feat'], true)),
        );
        $this->assertGreaterThanOrEqual(5, $granting->count(), 'several nodes grant library entries');

        // The Firebolt node grants the Firebolt spell entry.
        $firebolt = $web->nodes()->where('name', 'Firebolt')->first();
        $grant = collect($firebolt->effects)->firstWhere(fn ($effect) => ($effect['type'] ?? '') === 'spell');
        $this->assertNotNull($grant);
        $this->assertSame('Firebolt', CompendiumItem::find($grant['item_id'])->name);
    }

    public function test_the_build_page_shows_an_empty_state_when_no_system_is_enabled(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $campaign = $world->campaigns()->create(['name' => 'Play']);
        $character = $campaign->characters()->create(['name' => 'Bront', 'user_id' => $gm->id, 'level' => 1]);

        $this->actingAs($gm)->get(route('character-build.show', [$world, $campaign, $character]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Characters/Build')
                ->where('system', null));
    }
}
