<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TalentWeb;
use App\Models\User;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Support\DemoRuleSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TalentAiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(string $reply): void
    {
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->andReturn($reply);
        $this->app->instance(AnthropicClient::class, $ai);
    }

    /**
     * @return array{0: User, 1: TalentWeb, 2: int}
     */
    private function scaffold(): array
    {
        $gm = User::factory()->create();
        /** @var World $world */
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create(['name' => 'Homebrew', 'settings' => DemoRuleSystem::defaultSettings()]);
        $system->stats()->create(['key' => 'str', 'label' => 'Strength', 'default_value' => 3]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        /** @var TalentWeb $web */
        $web = $system->webs()->create(['name' => 'Core']);
        $origin = $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'minor', 'cost' => 0, 'config' => ['origin' => true]]);

        return [$gm, $web, $origin->id];
    }

    public function test_muse_proposes_nodes_and_drops_invalid_effects_and_kinds(): void
    {
        [$gm, $web] = $this->scaffold();

        $this->fakeAi(json_encode([
            'reply' => 'Here is a branch.',
            'nodes' => [
                ['id' => 'n1', 'name' => 'Ember', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 1, 'effects' => [
                    ['type' => 'stat', 'key' => 'str', 'delta' => 1],
                    ['type' => 'stat', 'key' => 'ghost', 'delta' => 9], // unknown stat → dropped
                ]],
                ['id' => 'n2', 'name' => 'Blaze', 'kind' => 'nonsense', 'cost' => 2, 'connect' => ['n1']], // bad kind → coerced
                ['id' => 'n3', 'name' => '', 'kind' => 'minor'], // no name → dropped
            ],
        ]));

        $this->actingAs($gm)->postJson(route('talent-ai.chat', $web->id), ['prompt' => 'a fire branch'])
            ->assertOk()
            ->assertJsonPath('reply', 'Here is a branch.')
            ->assertJsonCount(2, 'nodes')
            ->assertJsonPath('nodes.0.name', 'Ember')
            ->assertJsonCount(1, 'nodes.0.effects')
            ->assertJsonPath('nodes.1.kind', 'minor');
    }

    public function test_applying_proposals_persists_nodes_and_wires_connections(): void
    {
        [$gm, $web, $originId] = $this->scaffold();

        $this->actingAs($gm)->post(route('talent-ai.apply', $web->id), [
            'nodes' => [
                ['temp_id' => 'n1', 'name' => 'Ember', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 1, 'effects' => [], 'connect' => [(string) $originId]],
                ['temp_id' => 'n2', 'name' => 'Blaze', 'kind' => 'minor', 'cost' => 2, 'gate_level' => 1, 'effects' => [], 'connect' => ['n1']],
            ],
        ])->assertRedirect();

        $ember = $web->nodes()->where('name', 'Ember')->sole();
        $blaze = $web->nodes()->where('name', 'Blaze')->sole();

        // Ember links to the existing origin; Blaze chains onto Ember.
        $this->assertSame(1, $web->edges()->where('from_node_id', $ember->id)->where('to_node_id', $originId)->count());
        $this->assertSame(1, $web->edges()->where('from_node_id', $blaze->id)->where('to_node_id', $ember->id)->count());
    }

    public function test_applying_proposals_parents_each_node_to_its_first_connection(): void
    {
        [$gm, $web, $originId] = $this->scaffold();
        // Give the origin a position off the web centre so a fanned child lands a known distance from it.
        $web->nodes()->whereKey($originId)->update(['x' => 1500, 'y' => 1300]);

        $this->actingAs($gm)->post(route('talent-ai.apply', $web->id), [
            'nodes' => [
                ['temp_id' => 'n1', 'name' => 'Ember', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 1, 'effects' => [], 'connect' => [(string) $originId]],
                ['temp_id' => 'n2', 'name' => 'Blaze', 'kind' => 'minor', 'cost' => 2, 'gate_level' => 1, 'effects' => [], 'connect' => ['n1']],
            ],
        ])->assertRedirect();

        $ember = $web->nodes()->where('name', 'Ember')->sole();
        $blaze = $web->nodes()->where('name', 'Blaze')->sole();

        // Each node hangs off the node it connects to, laid out as a branch child…
        $this->assertSame($originId, $ember->parent_node_id);
        $this->assertSame('child', $ember->layout_role);
        $this->assertSame($ember->id, $blaze->parent_node_id);

        // …placed next to its parent (a ring node on the origin master), not scattered in the old spiral.
        $this->assertEqualsWithDelta(86.0, sqrt(($ember->x - 1500) ** 2 + ($ember->y - 1300) ** 2), 2.0);
    }

    public function test_a_stranger_cannot_use_muse_on_a_web(): void
    {
        [, $web] = $this->scaffold();
        $this->fakeAi('{"reply":"","nodes":[]}');

        $this->actingAs(User::factory()->create())
            ->postJson(route('talent-ai.chat', $web->id), ['prompt' => 'x'])
            ->assertForbidden();
    }
}
