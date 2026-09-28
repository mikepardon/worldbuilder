<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProgressionMode;
use App\Exceptions\TalentAllocationException;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\CharacterBuild;
use App\Models\TalentNode;
use App\Models\TalentWeb;
use App\Models\User;
use App\Services\CharacterTotals;
use App\Services\TalentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TalentAllocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A minimal system: STR (base 3), a Life pool (base 10), a keystone cap of 2, and a web with an
     * origin → n1 (minor, +1 STR) → n2 (minor, level 5) and origin → k1/k2/k3 (keystones).
     *
     * @return array{build: CharacterBuild, nodes: array<string, TalentNode>}
     */
    private function scaffold(int $manualPoints = 10, int $characterLevel = 1): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);

        $system = $world->ruleSystems()->create([
            'name' => 'Homebrew',
            'user_id' => $gm->id,
            'settings' => [
                'progression_mode' => ProgressionMode::Manual->value,
                'keystone_cap' => 2,
                'starting_points' => 0,
            ],
        ]);
        $system->stats()->create(['key' => 'str', 'label' => 'Strength', 'default_value' => 3]);
        $system->resources()->create(['key' => 'hp', 'label' => 'Life', 'base_value' => 10]);
        $system->nodeKinds()->create(['key' => 'origin', 'label' => 'Origin', 'default_cost' => 0]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $system->nodeKinds()->create(['key' => 'keystone', 'label' => 'Keystone', 'default_cost' => 3, 'max_per_character' => 2]);

        /** @var TalentWeb $web */
        $web = $system->webs()->create(['name' => 'Core']);

        $origin = $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'origin', 'cost' => 0, 'config' => ['origin' => true]]);
        $n1 = $web->nodes()->create(['key' => 'n1', 'name' => 'Might', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 1, 'effects' => [['type' => 'stat', 'key' => 'str', 'delta' => 1]]]);
        $n2 = $web->nodes()->create(['key' => 'n2', 'name' => 'Elite', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 5]);
        $k1 = $web->nodes()->create(['key' => 'k1', 'name' => 'Keystone One', 'kind' => 'keystone', 'cost' => 3, 'gate_level' => 1]);
        $k2 = $web->nodes()->create(['key' => 'k2', 'name' => 'Keystone Two', 'kind' => 'keystone', 'cost' => 3, 'gate_level' => 1]);
        $k3 = $web->nodes()->create(['key' => 'k3', 'name' => 'Keystone Three', 'kind' => 'keystone', 'cost' => 3, 'gate_level' => 1]);

        $web->edges()->create(['from_node_id' => $origin->id, 'to_node_id' => $n1->id]);
        $web->edges()->create(['from_node_id' => $n1->id, 'to_node_id' => $n2->id]);
        $web->edges()->create(['from_node_id' => $origin->id, 'to_node_id' => $k1->id]);
        $web->edges()->create(['from_node_id' => $origin->id, 'to_node_id' => $k2->id]);
        $web->edges()->create(['from_node_id' => $origin->id, 'to_node_id' => $k3->id]);

        /** @var Campaign $campaign */
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        /** @var Character $character */
        $character = $campaign->characters()->create(['name' => 'Bront', 'level' => $characterLevel]);
        /** @var CharacterBuild $build */
        $build = $character->builds()->create(['rule_system_id' => $system->id, 'manual_points' => $manualPoints]);

        $this->allocator()->ensureRootsAllocated($build);

        return ['build' => $build, 'nodes' => compact('origin', 'n1', 'n2', 'k1', 'k2', 'k3')];
    }

    private function allocator(): TalentAllocator
    {
        return app(TalentAllocator::class);
    }

    private function totals(): CharacterTotals
    {
        return app(CharacterTotals::class);
    }

    public function test_allocating_a_connected_node_spends_points_and_raises_the_stat(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold();

        $this->allocator()->allocate($build, $nodes['n1']);

        $sheet = $this->totals()->sheet($build->fresh(['talents.node', 'ruleSystem.stats', 'ruleSystem.resources', 'ruleSystem.skills']));
        $this->assertSame(4, $sheet['stats']['str']);
        $this->assertSame(9, $sheet['points']['remaining']);
        $this->assertSame(1, $sheet['points']['spent']);
    }

    public function test_a_node_that_touches_nothing_owned_cannot_be_allocated(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold();

        $this->expectException(TalentAllocationException::class);

        // n2 only connects to n1, which is not yet allocated.
        $this->allocator()->allocate($build, $nodes['n2']);
    }

    public function test_a_node_gated_above_the_characters_level_is_refused(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold(manualPoints: 10, characterLevel: 1);
        $this->allocator()->allocate($build, $nodes['n1']);

        try {
            $this->allocator()->allocate($build->fresh('talents.node'), $nodes['n2']);
            $this->fail('Expected a level gate violation.');
        } catch (TalentAllocationException $exception) {
            $this->assertSame('level', $exception->reason);
        }
    }

    public function test_a_node_cannot_be_allocated_without_enough_points(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold(manualPoints: 2);

        try {
            $this->allocator()->allocate($build, $nodes['k1']);
            $this->fail('Expected a points violation.');
        } catch (TalentAllocationException $exception) {
            $this->assertSame('points', $exception->reason);
        }
    }

    public function test_the_keystone_cap_stops_a_third_keystone(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold(manualPoints: 20);

        $this->allocator()->allocate($build, $nodes['k1']);
        $this->allocator()->allocate($build->fresh('talents.node'), $nodes['k2']);

        try {
            $this->allocator()->allocate($build->fresh('talents.node'), $nodes['k3']);
            $this->fail('Expected a kind cap violation.');
        } catch (TalentAllocationException $exception) {
            $this->assertSame('kind_cap', $exception->reason);
        }
    }

    public function test_removing_a_node_that_would_strand_another_is_refused(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold(manualPoints: 10, characterLevel: 5);
        $this->allocator()->allocate($build, $nodes['n1']);
        $this->allocator()->allocate($build->fresh('talents.node'), $nodes['n2']);

        try {
            // Removing n1 would strand n2 from the origin.
            $this->allocator()->deallocate($build->fresh('talents.node'), $nodes['n1']);
            $this->fail('Expected an orphan violation.');
        } catch (TalentAllocationException $exception) {
            $this->assertSame('orphan', $exception->reason);
        }
    }

    public function test_respec_clears_every_node_except_the_origin(): void
    {
        ['build' => $build, 'nodes' => $nodes] = $this->scaffold();
        $this->allocator()->allocate($build, $nodes['n1']);

        $this->allocator()->respec($build->fresh('talents.node'));

        $remaining = $build->fresh('talents.node')->talents;
        $this->assertCount(1, $remaining);
        $this->assertSame('origin', $remaining->first()->node->kind);
    }

    public function test_manual_points_are_the_only_source_in_manual_mode(): void
    {
        ['build' => $build] = $this->scaffold(manualPoints: 7);

        $this->assertSame(7, $this->totals()->points($build)['total']);
    }
}
