<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\CharacterTotals;
use App\Support\DemoRuleSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectTreeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A level-5 character on a world system whose one buyable node carries the given effect tree.
     *
     * @param  array<string, mixed>  $tree
     * @return array{0: User, 1: Character, 2: \App\Models\TalentNode}
     */
    private function treeBuild(array $tree): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create([
            'name' => 'Homebrew',
            'settings' => array_merge(DemoRuleSystem::defaultSettings(), ['starting_points' => 5]),
        ]);
        $system->stats()->create(['key' => 'str', 'label' => 'Strength', 'default_value' => 3]);
        $system->stats()->create(['key' => 'dex', 'label' => 'Dexterity', 'default_value' => 3]);
        $system->nodeKinds()->create(['key' => 'origin', 'label' => 'Origin', 'default_cost' => 0]);
        $system->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $system->webs()->create(['name' => 'Core']);
        $origin = $web->nodes()->create(['key' => 'origin', 'name' => 'Origin', 'kind' => 'origin', 'cost' => 0, 'config' => ['origin' => true]]);
        $target = $web->nodes()->create(['key' => 't', 'name' => 'Target', 'kind' => 'minor', 'cost' => 1, 'gate_level' => 1, 'config' => ['effect_tree' => $tree]]);
        $web->edges()->create(['from_node_id' => $origin->id, 'to_node_id' => $target->id]);
        $campaign = $world->campaigns()->create(['name' => 'Play', 'rule_system_id' => $system->id]);
        $character = $campaign->characters()->create(['name' => 'Aria', 'user_id' => $gm->id, 'level' => 5]);

        return [$gm, $character, $target];
    }

    private function sheet(Character $character): array
    {
        return app(CharacterTotals::class)->sheet($character->builds()->sole()->fresh());
    }

    public function test_the_chosen_path_decides_which_effects_apply(): void
    {
        [$gm, $character, $target] = $this->treeBuild([
            'id' => 'g0', 'op' => 'any', 'children' => [
                ['id' => 'a', 'op' => 'all', 'label' => 'Might', 'children' => [
                    ['id' => 'l1', 'effect' => ['type' => 'stat', 'key' => 'str', 'delta' => 1]],
                    ['id' => 'l2', 'effect' => ['type' => 'stat', 'key' => 'dex', 'delta' => 1]],
                ]],
                ['id' => 'b', 'label' => 'Focus', 'effect' => ['type' => 'stat', 'key' => 'str', 'delta' => 5]],
            ],
        ]);

        $this->actingAs($gm)->post(route('character-build.allocate', $character), ['node_id' => $target->id, 'choices' => ['g0' => 'a']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $sheet = $this->sheet($character);
        $this->assertSame(4, $sheet['stats']['str'], 'the Might branch adds +1 str');
        $this->assertSame(4, $sheet['stats']['dex'], 'the Might branch adds +1 dex');

        // Switching the choice re-resolves the applied effects.
        $this->actingAs($gm)->post(route('character-build.choices', $character), ['node_id' => $target->id, 'choices' => ['g0' => 'b']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $sheet = $this->sheet($character);
        $this->assertSame(8, $sheet['stats']['str'], 'the Focus branch adds +5 str');
        $this->assertSame(3, $sheet['stats']['dex'], 'and nothing to dex');
    }

    public function test_an_any_group_must_be_chosen_before_the_node_can_be_taken(): void
    {
        [$gm, $character, $target] = $this->treeBuild([
            'id' => 'g0', 'op' => 'any', 'children' => [
                ['id' => 'a', 'effect' => ['type' => 'stat', 'key' => 'str', 'delta' => 1]],
                ['id' => 'b', 'effect' => ['type' => 'stat', 'key' => 'dex', 'delta' => 1]],
            ],
        ]);

        $this->actingAs($gm)->post(route('character-build.allocate', $character), ['node_id' => $target->id, 'choices' => []])
            ->assertSessionHasErrors('talent');

        $this->assertDatabaseMissing('character_talents', ['talent_node_id' => $target->id]);
    }

    public function test_a_choice_gated_by_a_requirement_cannot_be_taken_until_it_is_met(): void
    {
        [$gm, $character, $target] = $this->treeBuild([
            'id' => 'g0', 'op' => 'any', 'children' => [
                ['id' => 'a', 'effect' => ['type' => 'stat', 'key' => 'str', 'delta' => 1]],
                ['id' => 'b', 'requires' => ['op' => 'all', 'children' => [['type' => 'stat', 'key' => 'str', 'min' => 99]]], 'effect' => ['type' => 'stat', 'key' => 'dex', 'delta' => 1]],
            ],
        ]);

        // The gated branch is refused…
        $this->actingAs($gm)->post(route('character-build.allocate', $character), ['node_id' => $target->id, 'choices' => ['g0' => 'b']])
            ->assertSessionHasErrors('talent');
        $this->assertDatabaseMissing('character_talents', ['talent_node_id' => $target->id]);

        // …but the open branch is fine.
        $this->actingAs($gm)->post(route('character-build.allocate', $character), ['node_id' => $target->id, 'choices' => ['g0' => 'a']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(4, $this->sheet($character)['stats']['str']);
    }
}
