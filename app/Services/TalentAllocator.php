<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TalentAllocationException;
use App\Models\CharacterBuild;
use App\Models\CharacterTalent;
use App\Models\TalentEdge;
use App\Models\TalentNode;
use App\Support\EffectTree;
use Illuminate\Support\Facades\DB;

/**
 * The rules engine for a character's build. Every mutation runs the same server-side checks the
 * player UI previews — budget, connectivity, level gate, kind caps, and node requirements — so a
 * character can never hold an illegal build. Read {@see CharacterTotals} for the point budget.
 */
class TalentAllocator
{
    public function __construct(private readonly CharacterTotals $totals) {}

    /**
     * Allocate a node, picking a choose-one option key (legacy nodes) or a set of choices for its nested
     * effect tree (keyed by ANY-group id).
     *
     * @param  array<string, string>  $choices
     */
    public function allocate(CharacterBuild $build, TalentNode $node, ?string $option = null, array $choices = []): CharacterTalent
    {
        $this->assertNodeInSystem($build, $node);
        $build->loadMissing(['talents.node', 'ruleSystem.nodeKinds']);

        if ($this->isAllocated($build, $node)) {
            throw new TalentAllocationException('That talent is already allocated.', 'duplicate');
        }

        $this->assertOptionValid($node, $option);
        $this->assertConnected($build, $node);
        $this->assertWithinLevel($build, $node);
        $this->assertKindCap($build, $node);
        $this->assertRequirements($build, $node);
        $this->assertTreeChoicesValid($build, $node, $choices);
        $this->assertAffordable($build, $node->cost);

        return DB::transaction(function () use ($build, $node, $option, $choices): CharacterTalent {
            $talent = $build->talents()->create([
                'talent_node_id' => $node->id,
                'chosen_option' => $option,
                'choices' => EffectTree::has($node) ? $choices : null,
                'rank' => 0,
            ]);

            $build->load('talents.node');

            return $talent;
        });
    }

    /**
     * Change the choices on an allocated nested-effect node.
     *
     * @param  array<string, string>  $choices
     */
    public function chooseChoices(CharacterBuild $build, TalentNode $node, array $choices): CharacterTalent
    {
        $build->loadMissing('talents.node');
        $talent = $build->talents->firstWhere('talent_node_id', $node->id);

        if ($talent === null) {
            throw new TalentAllocationException('Allocate the talent before choosing its options.', 'not_allocated');
        }
        if (! EffectTree::has($node)) {
            throw new TalentAllocationException('That talent has no choices to make.', 'no_choices');
        }

        $this->assertTreeChoicesValid($build, $node, $choices);
        $talent->update(['choices' => $choices]);

        return $talent;
    }

    /** Remove an allocated node, provided doing so leaves every other allocation still connected. */
    public function deallocate(CharacterBuild $build, TalentNode $node): void
    {
        $build->loadMissing('talents.node');
        $talent = $build->talents->firstWhere('talent_node_id', $node->id);

        if ($talent === null) {
            throw new TalentAllocationException('That talent is not allocated.', 'not_allocated');
        }

        if ($this->isRoot($node)) {
            throw new TalentAllocationException('The origin cannot be removed.', 'root');
        }

        if (! $this->remainsConnectedWithout($build, $node)) {
            throw new TalentAllocationException('Removing that would strand talents beyond it — remove those first.', 'orphan');
        }

        DB::transaction(function () use ($build, $talent): void {
            $talent->delete();
            $build->load('talents.node');
        });
    }

    /** Buy the next enhancement rank on an allocated node. */
    public function enhance(CharacterBuild $build, TalentNode $node): CharacterTalent
    {
        $build->loadMissing(['talents.node', 'ruleSystem.stats', 'ruleSystem.resources', 'ruleSystem.skills']);
        $talent = $build->talents->firstWhere('talent_node_id', $node->id);

        if ($talent === null) {
            throw new TalentAllocationException('Allocate the talent before enhancing it.', 'not_allocated');
        }

        $enhancements = $this->totals->enhancements($node);
        $next = $enhancements[$talent->rank] ?? null;

        if (! is_array($next)) {
            throw new TalentAllocationException('That talent has no further enhancements.', 'max_rank');
        }

        $this->assertRequirementsMet($build, $next['requires'] ?? null, 'enhance_requirement');
        $this->assertAffordable($build, (int) ($next['cost'] ?? 0));

        return DB::transaction(function () use ($talent, $build): CharacterTalent {
            $talent->increment('rank');
            $build->load('talents.node');

            return $talent->refresh();
        });
    }

    /** Change (or set) the chosen option on an allocated node. */
    public function chooseOption(CharacterBuild $build, TalentNode $node, string $option): CharacterTalent
    {
        $build->loadMissing('talents.node');
        $talent = $build->talents->firstWhere('talent_node_id', $node->id);

        if ($talent === null) {
            throw new TalentAllocationException('Allocate the talent before choosing an option.', 'not_allocated');
        }

        $this->assertOptionValid($node, $option);

        $talent->update(['chosen_option' => $option]);

        return $talent;
    }

    /** Clear the build back to its root nodes (a full respec). */
    public function respec(CharacterBuild $build): void
    {
        $build->loadMissing('talents.node');

        DB::transaction(function () use ($build): void {
            foreach ($build->talents as $talent) {
                if (! $this->isRoot($talent->node)) {
                    $talent->delete();
                }
            }

            $build->load('talents.node');
        });
    }

    /**
     * Ensure every root (origin) node in the system is allocated on this build. Called when a build is
     * first opened so the web has a starting point, matching the demo's always-taken origin.
     */
    public function ensureRootsAllocated(CharacterBuild $build): void
    {
        $build->loadMissing(['talents', 'ruleSystem.webs.nodes']);
        $allocated = $build->talents->pluck('talent_node_id')->all();

        foreach ($build->ruleSystem->webs as $web) {
            foreach ($web->nodes as $node) {
                if ($this->isRoot($node) && ! in_array($node->id, $allocated, true)) {
                    $build->talents()->create(['talent_node_id' => $node->id, 'rank' => 0]);
                }
            }
        }

        $build->load('talents.node');
    }

    private function assertNodeInSystem(CharacterBuild $build, TalentNode $node): void
    {
        $node->loadMissing('web');

        if ($node->web->rule_system_id !== $build->rule_system_id) {
            throw new TalentAllocationException('That talent belongs to a different rule system.', 'foreign');
        }
    }

    private function isAllocated(CharacterBuild $build, TalentNode $node): bool
    {
        return $build->talents->contains('talent_node_id', $node->id);
    }

    private function isRoot(TalentNode $node): bool
    {
        return (bool) ($node->config['origin'] ?? false);
    }

    private function assertOptionValid(TalentNode $node, ?string $option): void
    {
        $options = $node->options ?? [];

        if ($options === []) {
            return;
        }

        if ($option === null) {
            throw new TalentAllocationException('Choose an option for that talent first.', 'option_required');
        }

        $keys = array_map(static fn ($entry) => is_array($entry) ? ($entry['key'] ?? null) : null, $options);

        if (! in_array($option, $keys, true)) {
            throw new TalentAllocationException('That option is not available for the talent.', 'option_invalid');
        }
    }

    private function assertConnected(CharacterBuild $build, TalentNode $node): void
    {
        if ($this->isRoot($node)) {
            return;
        }

        $allocated = $build->talents->pluck('talent_node_id')->all();

        if ($allocated !== [] && array_intersect($this->neighbourIds($node), $allocated) !== []) {
            return;
        }

        throw new TalentAllocationException('That talent must connect to one you already own.', 'path');
    }

    private function assertWithinLevel(CharacterBuild $build, TalentNode $node): void
    {
        $level = $this->totals->effectiveLevel($build);

        if ($node->gate_level > $level) {
            throw new TalentAllocationException("That talent unlocks at level {$node->gate_level}; you are level {$level}.", 'level');
        }
    }

    private function assertKindCap(CharacterBuild $build, TalentNode $node): void
    {
        $kind = $build->ruleSystem->nodeKinds->firstWhere('key', $node->kind);
        $sameKind = $build->talents->filter(fn (CharacterTalent $talent) => $talent->node->kind === $node->kind);
        $count = $sameKind->count();

        $cap = $kind?->max_per_character;

        if ($cap !== null && $count >= $cap) {
            throw new TalentAllocationException("You have reached the limit of {$cap} for {$node->kind} talents.", 'kind_cap');
        }

        // A "solo" node must be the only one of its kind on the build.
        $existingSolo = $sameKind->first(fn (CharacterTalent $talent) => (bool) ($talent->node->config['solo'] ?? false));

        if ($existingSolo !== null) {
            throw new TalentAllocationException("A solo {$node->kind} must be your only one.", 'kind_solo');
        }

        if (($node->config['solo'] ?? false) && $count > 0) {
            throw new TalentAllocationException("A solo {$node->kind} must be your only one.", 'kind_solo');
        }
    }

    private function assertRequirements(CharacterBuild $build, TalentNode $node): void
    {
        $this->assertRequirementsMet($build, $node->config['requires'] ?? null, 'requirements');
    }

    /**
     * A nested-effect node's choices must pick a child for every reachable ANY group, and every node on
     * the chosen path must meet its own requirement. Legacy (non-tree) nodes have nothing to check.
     *
     * @param  array<string, string>  $choices
     */
    private function assertTreeChoicesValid(CharacterBuild $build, TalentNode $node, array $choices): void
    {
        if (! EffectTree::has($node)) {
            return;
        }

        $problems = EffectTree::problems(
            $node->config['effect_tree'],
            $choices,
            fn (array $requirement): bool => $this->requirementsMet($build, $requirement),
        );

        if (in_array('choose', $problems, true)) {
            throw new TalentAllocationException('Make a choice for each option on that talent.', 'choose_required');
        }
        if (in_array('requirement', $problems, true)) {
            throw new TalentAllocationException('You do not meet a requirement for that choice.', 'choice_requirement');
        }
    }

    /**
     * Every compendium entry this build has granted — the race's grants plus the grant effects on the
     * character's chosen paths — for the "have this spell/feat/ability" requirement.
     *
     * @return list<int>
     */
    private function grantedItemIds(CharacterBuild $build): array
    {
        $ids = [];

        foreach ((array) data_get($build->character?->raceItem?->fields, 'grants', []) as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        foreach ($build->talents as $talent) {
            $effects = EffectTree::has($talent->node)
                ? EffectTree::resolve($talent->node->config['effect_tree'], $talent->choices ?? [])
                : $this->legacyEffects($talent);

            foreach ($effects as $effect) {
                if (is_array($effect) && in_array($effect['type'] ?? null, ['spell', 'feat', 'ability'], true) && isset($effect['item_id'])) {
                    $ids[] = (int) $effect['item_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The flat effects of a legacy (non-tree) talent — its node's effects plus its chosen option's.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyEffects(CharacterTalent $talent): array
    {
        $node = $talent->node;
        $effects = is_array($node->effects) ? $node->effects : [];

        if (filled($talent->chosen_option)) {
            foreach ($node->options ?? [] as $option) {
                if (is_array($option) && ($option['key'] ?? null) === $talent->chosen_option) {
                    $effects = [...$effects, ...(is_array($option['effects'] ?? null) ? $option['effects'] : [])];
                }
            }
        }

        return array_values(array_filter($effects, 'is_array'));
    }

    /**
     * A node's requirements are a nested AND/OR tree, evaluated recursively:
     *  - a leaf: {type:'stat', key, min} or {type?:'nodes', count, kind?, min_ring?, web?}
     *  - a group: {op:'all'|'any', children:[...]}
     * A bare list is treated as an implicit ALL group (the old flat-list shape still works).
     *
     * @param  mixed  $requires
     */
    private function assertRequirementsMet(CharacterBuild $build, $requires, string $reason): void
    {
        if (! $this->requirementsMet($build, $requires)) {
            throw new TalentAllocationException('That talent needs prerequisites you have not met yet.', $reason);
        }
    }

    /**
     * @param  mixed  $requires
     */
    private function requirementsMet(CharacterBuild $build, $requires): bool
    {
        $group = $this->asGroup($requires);

        return $group === null || $this->groupMet($build, $group);
    }

    /**
     * Normalise any requirements value into a group, or null when there is nothing to check.
     *
     * @param  mixed  $requires
     * @return array{op: string, children: array<int, mixed>}|null
     */
    private function asGroup($requires): ?array
    {
        if (! is_array($requires) || $requires === []) {
            return null;
        }

        if (isset($requires['op'], $requires['children']) && is_array($requires['children'])) {
            return ['op' => $requires['op'] === 'any' ? 'any' : 'all', 'children' => array_values($requires['children'])];
        }

        // A bare list of leaves, or a single leaf object → an implicit ALL group.
        $children = array_is_list($requires) ? $requires : [$requires];

        return ['op' => 'all', 'children' => $children];
    }

    /**
     * @param  array{op: string, children: array<int, mixed>}  $group
     */
    private function groupMet(CharacterBuild $build, array $group): bool
    {
        $results = [];
        foreach ($group['children'] as $child) {
            if (! is_array($child) || $child === []) {
                continue;
            }
            $results[] = isset($child['op'], $child['children'])
                ? $this->groupMet($build, $this->asGroup($child))
                : $this->leafMet($build, $child);
        }

        if ($results === []) {
            return true;
        }

        return $group['op'] === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function leafMet(CharacterBuild $build, array $requirement): bool
    {
        if (($requirement['type'] ?? null) === 'stat') {
            $value = $this->totals->sheet($build)['stats'][(string) ($requirement['key'] ?? '')] ?? 0;

            return $value >= (int) ($requirement['min'] ?? 0);
        }

        if (($requirement['type'] ?? null) === 'grant') {
            return in_array((int) ($requirement['item_id'] ?? 0), $this->grantedItemIds($build), true);
        }

        $needed = max(1, (int) ($requirement['count'] ?? 1));
        $have = $build->talents->filter(fn (CharacterTalent $talent) => $this->matchesRequirement($talent, $requirement))->count();

        return $have >= $needed;
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function matchesRequirement(CharacterTalent $talent, array $requirement): bool
    {
        $node = $talent->node;

        if (isset($requirement['kind']) && $node->kind !== $requirement['kind']) {
            return false;
        }

        if (isset($requirement['min_ring']) && ($node->ring ?? 0) < (int) $requirement['min_ring']) {
            return false;
        }

        if (isset($requirement['web']) && $node->web->slug !== $requirement['web']) {
            return false;
        }

        return true;
    }

    private function assertAffordable(CharacterBuild $build, int $cost): void
    {
        $remaining = $this->totals->points($build)['remaining'];

        if ($cost > $remaining) {
            throw new TalentAllocationException("That costs {$cost} points; you have {$remaining} left.", 'points');
        }
    }

    /**
     * @return list<int>
     */
    private function neighbourIds(TalentNode $node): array
    {
        return TalentEdge::query()
            ->where('talent_web_id', $node->talent_web_id)
            ->where(fn ($query) => $query->where('from_node_id', $node->id)->orWhere('to_node_id', $node->id))
            ->get()
            ->map(fn (TalentEdge $edge) => $edge->from_node_id === $node->id ? $edge->to_node_id : $edge->from_node_id)
            ->all();
    }

    /**
     * Whether the allocations on the node's web stay connected to a root once $node is removed.
     */
    private function remainsConnectedWithout(CharacterBuild $build, TalentNode $node): bool
    {
        $webId = $node->talent_web_id;

        $remaining = $build->talents
            ->filter(fn (CharacterTalent $talent) => $talent->node->talent_web_id === $webId && $talent->talent_node_id !== $node->id)
            ->map(fn (CharacterTalent $talent) => $talent->node);

        $remainingIds = $remaining->pluck('id')->all();

        if ($remainingIds === []) {
            return true;
        }

        $edges = TalentEdge::query()->where('talent_web_id', $webId)->get();
        $adjacency = [];
        foreach ($edges as $edge) {
            if (in_array($edge->from_node_id, $remainingIds, true) && in_array($edge->to_node_id, $remainingIds, true)) {
                $adjacency[$edge->from_node_id][] = $edge->to_node_id;
                $adjacency[$edge->to_node_id][] = $edge->from_node_id;
            }
        }

        $roots = $remaining->filter(fn (TalentNode $candidate) => $this->isRoot($candidate))->pluck('id')->all();

        if ($roots === []) {
            // No root on this web among the remaining nodes: nothing anchors them, so it is stranded.
            return false;
        }

        $seen = [];
        $queue = $roots;
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($adjacency[$current] ?? [] as $neighbour) {
                if (! isset($seen[$neighbour])) {
                    $queue[] = $neighbour;
                }
            }
        }

        foreach ($remainingIds as $id) {
            if (! isset($seen[$id])) {
                return false;
            }
        }

        return true;
    }
}
