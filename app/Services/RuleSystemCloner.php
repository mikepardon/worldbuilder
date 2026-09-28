<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RuleSystem;
use App\Models\TalentNode;
use App\Models\TalentWeb;
use App\Models\World;
use Illuminate\Support\Facades\DB;

/**
 * Deep-copies a rule system into a world — used when a GM clones a global template, or duplicates one
 * of their own. Copies stats, resources, skills, levels, node kinds, and every web with its nodes and
 * edges, remapping edge endpoints and parent links to the freshly-created node ids. When the source
 * declares prerequisite library "compendiums", those are imported into the world and the nodes' grant
 * references are remapped onto the imported copies.
 */
class RuleSystemCloner
{
    public function __construct(private readonly WorldCompendiumImporter $importer) {}

    public function cloneInto(RuleSystem $source, World $world, int $authorId, ?string $name = null): RuleSystem
    {
        $source->loadMissing([
            'stats', 'resources', 'skills', 'levels', 'nodeKinds', 'webs.nodes', 'webs.edges',
        ]);

        return DB::transaction(function () use ($source, $world, $authorId, $name): RuleSystem {
            $copy = $world->ruleSystems()->create([
                'user_id' => $authorId,
                'template_source_id' => $source->id,
                'is_template' => false,
                'name' => $name ?? $source->name,
                'description' => $source->description,
                'settings' => $source->settings,
            ]);

            // Bring in the system's prerequisite compendiums, and map the global entries the template's
            // nodes grant to their freshly-imported world copies so the grants keep working.
            $sourceIds = collect(data_get($source->settings, 'compendium_sources', []))
                ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn ($id): int => (int) $id)
                ->all();
            $grantMap = $sourceIds === [] ? [] : $this->importer->importSources($world, $sourceIds, $authorId)['map'];

            foreach ($source->stats as $stat) {
                $copy->stats()->create($stat->only(['key', 'label', 'abbreviation', 'description', 'default_value', 'sort']));
            }
            foreach ($source->resources as $resource) {
                $copy->resources()->create($resource->only(['key', 'label', 'description', 'base_value', 'colour', 'sort']));
            }
            foreach ($source->skills as $skill) {
                $copy->skills()->create($skill->only(['key', 'label', 'governing_stat_key', 'description', 'sort']));
            }
            foreach ($source->levels as $level) {
                $copy->levels()->create($level->only(['level', 'xp_required', 'talent_points', 'notes']));
            }
            foreach ($source->nodeKinds as $kind) {
                $copy->nodeKinds()->create($kind->only(['key', 'label', 'default_cost', 'shape', 'glyph', 'size', 'colour', 'max_per_character', 'sort']));
            }

            foreach ($source->webs as $web) {
                $this->cloneWeb($web, $copy, $grantMap);
            }

            return $copy;
        });
    }

    /**
     * @param  array<int, int>  $grantMap  global compendium item id → world entry id
     */
    private function cloneWeb(TalentWeb $web, RuleSystem $target, array $grantMap): void
    {
        /** @var TalentWeb $newWeb */
        $newWeb = $target->webs()->create($web->only(['name', 'slug', 'description', 'layout', 'sort']));

        $nodeMap = [];
        foreach ($web->nodes as $node) {
            $config = is_array($node->config) ? $node->config : [];
            if (isset($config['effect_tree'])) {
                $config['effect_tree'] = $this->remapTreeGrants($config['effect_tree'], $grantMap);
            }
            $newNode = $newWeb->nodes()->create([
                ...$node->only(['key', 'name', 'kind', 'layout_role', 'x', 'y', 'ring', 'gate_level', 'cost', 'description', 'sort']),
                'config' => $config,
                'effects' => $this->remapGrants($node->effects ?? [], $grantMap),
                'options' => array_map(
                    fn ($option) => is_array($option) ? [...$option, 'effects' => $this->remapGrants($option['effects'] ?? [], $grantMap)] : $option,
                    $node->options ?? [],
                ),
            ]);
            $nodeMap[$node->id] = $newNode->id;
        }

        // Second pass: point each cloned node's parent at the cloned parent, now that all exist.
        foreach ($web->nodes as $node) {
            if ($node->parent_node_id !== null && isset($nodeMap[$node->id], $nodeMap[$node->parent_node_id])) {
                TalentNode::where('id', $nodeMap[$node->id])->update(['parent_node_id' => $nodeMap[$node->parent_node_id]]);
            }
        }

        foreach ($web->edges as $edge) {
            if (isset($nodeMap[$edge->from_node_id], $nodeMap[$edge->to_node_id])) {
                $newWeb->edges()->create([
                    'from_node_id' => $nodeMap[$edge->from_node_id],
                    'to_node_id' => $nodeMap[$edge->to_node_id],
                ]);
            }
        }
    }

    /**
     * Remap a node's grant effects (spell/feat/ability) from global library ids onto the world copies,
     * dropping any whose entry wasn't imported. Sheet effects pass through unchanged.
     *
     * @param  mixed  $effects
     * @param  array<int, int>  $grantMap
     * @return list<array<string, mixed>>
     */
    private function remapGrants($effects, array $grantMap): array
    {
        $out = [];
        foreach ((array) $effects as $effect) {
            if (! is_array($effect)) {
                continue;
            }
            if (in_array($effect['type'] ?? null, ['spell', 'feat', 'ability'], true)) {
                $worldId = $grantMap[(int) ($effect['item_id'] ?? 0)] ?? null;
                if ($worldId !== null) {
                    $out[] = ['type' => $effect['type'], 'item_id' => $worldId];
                }

                continue;
            }
            $out[] = $effect;
        }

        return $out;
    }

    /**
     * Remap the grant leaves of an effect tree onto the world copies, clearing any that weren't imported.
     *
     * @param  mixed  $node
     * @param  array<int, int>  $grantMap
     * @return mixed
     */
    private function remapTreeGrants($node, array $grantMap)
    {
        if (! is_array($node)) {
            return $node;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_map(fn ($child) => $this->remapTreeGrants($child, $grantMap), $node['children']);

            return $node;
        }
        $effect = $node['effect'] ?? null;
        if (is_array($effect) && in_array($effect['type'] ?? null, ['spell', 'feat', 'ability'], true)) {
            $node['effect']['item_id'] = $grantMap[(int) ($effect['item_id'] ?? 0)] ?? null;
        }

        return $node;
    }
}
