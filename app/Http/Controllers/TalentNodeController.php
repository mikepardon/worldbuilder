<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CampaignCompendiumItem;
use App\Models\CompendiumItem;
use App\Models\RuleSystem;
use App\Models\TalentNode;
use App\Models\TalentWeb;
use App\Services\TalentLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Nodes on a {@see TalentWeb} — the building blocks the GM adds, moves and wires up. Positions are
 * saved in bulk as the GM drags, so the canvas stays snappy.
 */
class TalentNodeController extends Controller
{
    public function store(Request $request, TalentWeb $web, TalentLayout $layout)
    {
        $this->authorize('update', $web->ruleSystem);

        $data = $this->validateNode($request);

        // Optionally wire the new node straight to an existing one, so a branch can be grown in a click,
        // and record where it hangs (parent_node_id) and how it lays out (layout_role) so children can
        // fan off their parent and follow it when it moves.
        $links = $request->validate([
            'connect_to' => ['nullable', 'integer', Rule::exists('talent_nodes', 'id')->where('talent_web_id', $web->id)],
            'parent_node_id' => ['nullable', 'integer', Rule::exists('talent_nodes', 'id')->where('talent_web_id', $web->id)],
            'layout_role' => ['nullable', Rule::in(['master', 'child', 'adjacent', 'ring'])],
        ]);
        $connectTo = $links['connect_to'] ?? null;
        $role = $links['layout_role'] ?? 'master';
        $parentId = $links['parent_node_id'] ?? null;

        DB::transaction(function () use ($web, $data, $connectTo, $role, $parentId, $layout): void {
            $node = $web->nodes()->create([
                ...$data,
                'key' => $this->uniqueKey($web, $data['name']),
                'layout_role' => $role,
                'parent_node_id' => $parentId,
                'sort' => (int) $web->nodes()->max('sort') + 1,
            ]);

            if ($connectTo !== null) {
                $web->edges()->create(['from_node_id' => $connectTo, 'to_node_id' => $node->id]);
            }

            // Even the whole set of branch children out around the parent, so a new sibling nudges the
            // others into a comfortable fan instead of stacking on top of them.
            if ($role === 'child' && $parentId !== null && $node->parent !== null) {
                $layout->arrange($web, $node->parent);
            }
        });

        return back();
    }

    /** Re-fan a parent's branch children into an even outward spread (the "tidy" action in UI mode). */
    public function arrange(Request $request, TalentWeb $web, TalentLayout $layout)
    {
        $this->authorize('update', $web->ruleSystem);

        $parentId = $request->validate([
            'parent_id' => ['required', 'integer', Rule::exists('talent_nodes', 'id')->where('talent_web_id', $web->id)],
        ])['parent_id'];

        $layout->arrange($web, $web->nodes()->findOrFail($parentId));

        return back();
    }

    /**
     * Create a whole cluster in one go: a master (centre) node, a ring of nodes around it linked to it
     * and to each other, and a backing disc — so a master reads like the seeded clusters, not a lone node.
     */
    public function cluster(Request $request, TalentWeb $web)
    {
        $this->authorize('update', $web->ruleSystem);

        $data = $request->validate([
            'connect_to' => ['nullable', 'integer', Rule::exists('talent_nodes', 'id')->where('talent_web_id', $web->id)],
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'master_kind' => ['required', 'string', Rule::exists('rule_node_kinds', 'key')->where('rule_system_id', $web->rule_system_id)],
            'ring_kind' => ['required', 'string', Rule::exists('rule_node_kinds', 'key')->where('rule_system_id', $web->rule_system_id)],
            'ring_count' => ['sometimes', 'integer', 'min:3', 'max:12'],
            'gate_level' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'colour' => ['nullable', 'string', 'max:20'],
            'is_root' => ['sometimes', 'boolean'],
        ]);

        // A disconnected cluster is only allowed to seed an empty web (its master becomes the origin);
        // otherwise every cluster must attach to an existing node.
        if (blank($data['connect_to'] ?? null) && $web->nodes()->exists()) {
            abort(422, 'New clusters must connect to an existing node.');
        }

        $kinds = $web->ruleSystem->nodeKinds->keyBy('key');
        $count = (int) ($data['ring_count'] ?? 6);
        $cx = (int) $data['x'];
        $cy = (int) $data['y'];
        $gate = (int) ($data['gate_level'] ?? 1);
        $config = filled($data['colour'] ?? null) ? ['colour' => $data['colour']] : [];
        if (($data['is_root'] ?? false) && blank($data['connect_to'] ?? null)) {
            $config['origin'] = true;
        }

        DB::transaction(function () use ($web, $data, $kinds, $count, $cx, $cy, $gate, $config): void {
            $sort = (int) $web->nodes()->max('sort');

            $master = $web->nodes()->create([
                'key' => $this->uniqueKey($web, 'Master'),
                'name' => 'New node', 'kind' => $data['master_kind'], 'layout_role' => 'master',
                'x' => $cx, 'y' => $cy, 'gate_level' => $gate,
                'cost' => (int) ($kinds[$data['master_kind']]->default_cost ?? 3),
                'config' => $config, 'sort' => ++$sort,
            ]);
            if (filled($data['connect_to'] ?? null)) {
                $web->edges()->create(['from_node_id' => (int) $data['connect_to'], 'to_node_id' => $master->id]);
            }

            $ringIds = [];
            for ($i = 0; $i < $count; $i++) {
                $angle = deg2rad($i * (360 / $count) - 90);
                $ring = $web->nodes()->create([
                    'key' => $this->uniqueKey($web, 'Ring'),
                    'name' => 'New node', 'kind' => $data['ring_kind'],
                    'layout_role' => 'ring', 'parent_node_id' => $master->id,
                    'x' => (int) round($cx + cos($angle) * 86), 'y' => (int) round($cy + sin($angle) * 86),
                    'gate_level' => $gate,
                    'cost' => (int) ($kinds[$data['ring_kind']]->default_cost ?? 1),
                    'config' => $config, 'sort' => ++$sort,
                ]);
                $web->edges()->create(['from_node_id' => $master->id, 'to_node_id' => $ring->id]);
                $ringIds[] = $ring->id;
            }
            foreach ($ringIds as $i => $id) {
                $web->edges()->create(['from_node_id' => $id, 'to_node_id' => $ringIds[($i + 1) % $count]]);
            }

            $layout = (array) $web->layout;
            $discs = $layout['discs'] ?? [];
            $discs[] = ['x' => $cx, 'y' => $cy, 'size' => 232];
            $layout['discs'] = $discs;
            $web->update(['layout' => $layout]);
        });

        return back();
    }

    public function update(Request $request, TalentNode $node)
    {
        $this->authorize('update', $node->web->ruleSystem);

        $data = $this->withSanitisedEffects($this->validateNode($request), $node->web->ruleSystem);

        // Optionally link the node to a spell/feat from its world's compendium. Only world systems have a
        // compendium; templates have no world, so the link is simply ignored for them.
        $worldId = $node->web->ruleSystem->world_id;
        if ($request->has('compendium_item_id') && $worldId !== null) {
            $data['compendium_item_id'] = $request->validate([
                'compendium_item_id' => [
                    'nullable', 'integer',
                    Rule::exists('campaign_compendium_items', 'id')->where('world_id', $worldId)->whereIn('item_type', ['spell', 'feat']),
                ],
            ])['compendium_item_id'] ?? null;
        }

        $node->update($data);

        return back();
    }

    /** Persist dragged node positions in one write. */
    public function positions(Request $request, TalentWeb $web)
    {
        $this->authorize('update', $web->ruleSystem);

        $data = $request->validate([
            'positions' => ['present', 'array'],
            'positions.*.id' => ['required', 'integer'],
            'positions.*.x' => ['required', 'integer'],
            'positions.*.y' => ['required', 'integer'],
            // The canvas layout (cluster discs and labels) moves with a dragged master.
            'layout' => ['nullable', 'array'],
        ]);

        $ownIds = $web->nodes()->pluck('id')->all();

        DB::transaction(function () use ($web, $data, $ownIds): void {
            foreach ($data['positions'] as $position) {
                if (in_array((int) $position['id'], $ownIds, true)) {
                    TalentNode::where('id', $position['id'])->update([
                        'x' => (int) $position['x'],
                        'y' => (int) $position['y'],
                    ]);
                }
            }

            if (isset($data['layout'])) {
                $web->update(['layout' => $data['layout']]);
            }
        });

        return back();
    }

    public function destroy(TalentNode $node)
    {
        $this->authorize('update', $node->web->ruleSystem);

        $node->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateNode(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'max:40'],
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'ring' => ['nullable', 'integer', 'min:0', 'max:99'],
            'gate_level' => ['required', 'integer', 'min:1', 'max:100'],
            'cost' => ['required', 'integer', 'min:0', 'max:99'],
            'description' => ['nullable', 'string', 'max:2000'],
            'effects' => ['nullable', 'array'],
            // stat/resource/skill/derived modify the sheet; spell/feat/ability grant a compendium entry;
            // level_spell raises a learned spell to a higher rank (item_id = the spell, to_level = the rank).
            'effects.*.type' => ['required', 'string', 'in:stat,resource,skill,derived,spell,feat,ability,level_spell'],
            'effects.*.key' => ['nullable', 'string', 'max:60'],
            'effects.*.item_id' => ['nullable', 'integer'],
            'effects.*.to_level' => ['nullable', 'integer', 'min:1', 'max:99'],
            'effects.*.delta' => ['nullable', 'integer', 'min:-999', 'max:999'],
            'effects.*.pct' => ['nullable', 'numeric', 'min:-1', 'max:10'],
            'effects.*.proficiency' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.key' => ['required', 'string', 'max:60'],
            'options.*.name' => ['required', 'string', 'max:120'],
            'options.*.desc' => ['nullable', 'string', 'max:500'],
            'options.*.effects' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
        ]);
    }

    private const GRANT_EFFECT_TYPES = ['spell', 'feat', 'ability'];

    /** Raises a learned spell to a higher rank; references a spell by item_id and carries a to_level. */
    private const SPELL_LEVEL_EFFECT_TYPE = 'level_spell';

    /** Effect types whose item_id points at a compendium entry (for loading valid references in one query). */
    private const REFERENCE_EFFECT_TYPES = ['spell', 'feat', 'ability', 'level_spell'];

    /**
     * Clean a node's effects and its choose-one variants' effects: drop sheet effects with no key, and
     * keep a grant effect (spell/feat/ability) only when its item_id points at a matching entry in this
     * world's compendium — so a node can never grant an entry from another world or of the wrong type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withSanitisedEffects(array $data, RuleSystem $system): array
    {
        $hasEffects = array_key_exists('effects', $data);
        $hasOptions = array_key_exists('options', $data);
        if (! $hasEffects && ! $hasOptions) {
            return $data;
        }

        // Load every referenced grant across the effects, variant effects and effect tree in one query.
        $tree = $data['config']['effect_tree'] ?? null;
        $grantIds = $this->grantIdsIn($data['effects'] ?? []);
        foreach ((array) ($data['options'] ?? []) as $option) {
            if (is_array($option)) {
                $grantIds = [...$grantIds, ...$this->grantIdsIn($option['effects'] ?? [])];
            }
        }
        $grantIds = [...$grantIds, ...$this->treeGrantIds($tree)];
        $validGrants = $this->validGrants($system, $grantIds);

        if ($hasEffects) {
            $data['effects'] = $this->cleanEffectList($data['effects'] ?? [], $validGrants);
        }
        if ($hasOptions) {
            $options = [];
            foreach ((array) ($data['options'] ?? []) as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $option['effects'] = $this->cleanEffectList($option['effects'] ?? [], $validGrants);
                $options[] = $option;
            }
            $data['options'] = $options;
        }
        if ($tree !== null) {
            $data['config']['effect_tree'] = $this->cleanTree($tree, $validGrants);
        }

        return $data;
    }

    /**
     * Collect the grant item ids referenced anywhere in an effect tree.
     *
     * @param  mixed  $node
     * @return list<int>
     */
    private function treeGrantIds($node): array
    {
        if (! is_array($node)) {
            return [];
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $ids = [];
            foreach ($node['children'] as $child) {
                $ids = [...$ids, ...$this->treeGrantIds($child)];
            }

            return $ids;
        }
        $effect = $node['effect'] ?? null;

        return is_array($effect) && in_array($effect['type'] ?? null, self::REFERENCE_EFFECT_TYPES, true) && isset($effect['item_id']) ? [(int) $effect['item_id']] : [];
    }

    /**
     * Clear a grant leaf whose entry isn't valid for this system (foreign world / wrong type) so the tree
     * can never grant something it shouldn't; other nodes pass through unchanged.
     *
     * @param  mixed  $node
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $validGrants
     * @return mixed
     */
    private function cleanTree($node, $validGrants)
    {
        if (! is_array($node)) {
            return $node;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_values(array_map(fn ($child) => $this->cleanTree($child, $validGrants), $node['children']));

            return $node;
        }
        $effect = $node['effect'] ?? null;
        if (is_array($effect) && in_array($effect['type'] ?? null, self::GRANT_EFFECT_TYPES, true)) {
            $item = $validGrants->get((int) ($effect['item_id'] ?? 0));
            $node['effect']['item_id'] = ($item !== null && $item->item_type === $effect['type']) ? (int) $item->id : null;
        }
        if (is_array($effect) && ($effect['type'] ?? null) === self::SPELL_LEVEL_EFFECT_TYPE) {
            $item = $validGrants->get((int) ($effect['item_id'] ?? 0));
            $node['effect']['item_id'] = ($item !== null && $item->item_type === 'spell') ? (int) $item->id : null;
            $node['effect']['to_level'] = max(1, (int) ($effect['to_level'] ?? 1));
        }

        return $node;
    }

    /**
     * @param  mixed  $effects
     * @return list<int>
     */
    private function grantIdsIn($effects): array
    {
        $ids = [];
        foreach ((array) $effects as $effect) {
            if (is_array($effect) && in_array($effect['type'] ?? null, self::REFERENCE_EFFECT_TYPES, true) && isset($effect['item_id'])) {
                $ids[] = (int) $effect['item_id'];
            }
        }

        return $ids;
    }

    /**
     * The grant entries a node in this system may reference, keyed by id. A world system grants from its
     * world compendium; a template grants from the entries of its prerequisite library sources (which are
     * imported into the world when the template is adopted).
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function validGrants(RuleSystem $system, array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        if ($system->world_id !== null) {
            return CampaignCompendiumItem::whereIn('id', array_unique($ids))->where('world_id', $system->world_id)->get()->keyBy('id');
        }

        $sourceIds = collect(data_get($system->settings, 'compendium_sources', []))
            ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $sourceIds === []
            ? collect()
            : CompendiumItem::whereIn('id', array_unique($ids))->whereIn('source_id', $sourceIds)->get()->keyBy('id');
    }

    /**
     * @param  mixed  $effects
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $validGrants
     * @return list<array<string, mixed>>
     */
    private function cleanEffectList($effects, $validGrants): array
    {
        $clean = [];
        foreach ((array) $effects as $effect) {
            if (! is_array($effect)) {
                continue;
            }
            $type = (string) ($effect['type'] ?? '');

            if (in_array($type, self::GRANT_EFFECT_TYPES, true)) {
                $item = $validGrants->get((int) ($effect['item_id'] ?? 0));
                if ($item !== null && $item->item_type === $type) {
                    $clean[] = ['type' => $type, 'item_id' => (int) $item->id];
                }

                continue;
            }

            if ($type === self::SPELL_LEVEL_EFFECT_TYPE) {
                $item = $validGrants->get((int) ($effect['item_id'] ?? 0));
                if ($item !== null && $item->item_type === 'spell') {
                    $clean[] = ['type' => $type, 'item_id' => (int) $item->id, 'to_level' => max(1, (int) ($effect['to_level'] ?? 1))];
                }

                continue;
            }

            $key = trim((string) ($effect['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $entry = ['type' => $type, 'key' => $key, 'delta' => (int) ($effect['delta'] ?? 0)];
            if (isset($effect['pct']) && is_numeric($effect['pct'])) {
                $entry['pct'] = (float) $effect['pct'];
            }
            if (($effect['proficiency'] ?? false)) {
                $entry['proficiency'] = true;
            }
            $clean[] = $entry;
        }

        return $clean;
    }

    private function uniqueKey(TalentWeb $web, string $name): string
    {
        $base = Str::slug($name) ?: 'node';
        $key = $base;
        $n = 2;
        while ($web->nodes()->where('key', $key)->exists()) {
            $key = $base.'-'.$n++;
        }

        return $key;
    }
}
