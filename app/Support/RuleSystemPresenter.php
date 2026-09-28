<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CampaignCompendiumItem;
use App\Models\RuleLevel;
use App\Models\RuleNodeKind;
use App\Models\RuleResource;
use App\Models\RuleSkill;
use App\Models\RuleStat;
use App\Models\RuleSystem;
use App\Models\TalentEdge;
use App\Models\TalentNode;
use App\Models\TalentWeb;

/**
 * Serialises a {@see RuleSystem} and its whole tree into explicit arrays for Inertia — never handing
 * Eloquent models to the frontend. Shared by the admin builder, the world builder and the player's
 * character builder so all three read the same shape.
 */
class RuleSystemPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function full(RuleSystem $system): array
    {
        $system->loadMissing([
            'stats', 'resources', 'skills', 'levels', 'nodeKinds',
            'webs.nodes.compendiumItem', 'webs.edges',
        ]);

        return [
            'id' => $system->id,
            'name' => $system->name,
            'slug' => $system->slug,
            'description' => $system->description,
            'is_template' => $system->is_template,
            'world_id' => $system->world_id,
            'settings' => [
                'progression_mode' => $system->progressionMode()->value,
                'points_cumulative' => $system->pointsCumulative(),
                'starting_points' => $system->startingPoints(),
                'level_cap' => $system->levelCap(),
                'role_kinds' => $system->roleKinds(),
                'compendium_sources' => collect($system->settings['compendium_sources'] ?? [])->map(fn ($id): int => (int) $id)->values()->all(),
            ],
            'stats' => $system->stats->sortBy('sort')->values()->map(self::stat(...))->all(),
            'resources' => $system->resources->sortBy('sort')->values()->map(self::resource(...))->all(),
            'skills' => $system->skills->sortBy('sort')->values()->map(self::skill(...))->all(),
            'levels' => $system->levels->sortBy('level')->values()->map(self::level(...))->all(),
            'nodeKinds' => $system->nodeKinds->sortBy('sort')->values()->map(self::nodeKind(...))->all(),
            'webs' => $system->webs->sortBy('sort')->values()->map(self::web(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stat(RuleStat $stat): array
    {
        return [
            'id' => $stat->id,
            'key' => $stat->key,
            'label' => $stat->label,
            'abbreviation' => $stat->abbreviation,
            'description' => $stat->description,
            'default_value' => $stat->default_value,
            'sort' => $stat->sort,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource(RuleResource $resource): array
    {
        return [
            'id' => $resource->id,
            'key' => $resource->key,
            'label' => $resource->label,
            'description' => $resource->description,
            'base_value' => $resource->base_value,
            'colour' => $resource->colour,
            'sort' => $resource->sort,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function skill(RuleSkill $skill): array
    {
        return [
            'id' => $skill->id,
            'key' => $skill->key,
            'label' => $skill->label,
            'governing_stat_key' => $skill->governing_stat_key,
            'description' => $skill->description,
            'sort' => $skill->sort,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function level(RuleLevel $level): array
    {
        return [
            'id' => $level->id,
            'level' => $level->level,
            'xp_required' => $level->xp_required,
            'talent_points' => $level->talent_points,
            'notes' => $level->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nodeKind(RuleNodeKind $kind): array
    {
        return [
            'id' => $kind->id,
            'key' => $kind->key,
            'label' => $kind->label,
            'default_cost' => $kind->default_cost,
            'shape' => $kind->shape->value,
            'glyph' => $kind->glyph,
            'size' => $kind->size,
            'colour' => $kind->colour,
            'max_per_character' => $kind->max_per_character,
            'sort' => $kind->sort,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function web(TalentWeb $web): array
    {
        return [
            'id' => $web->id,
            'name' => $web->name,
            'slug' => $web->slug,
            'description' => $web->description,
            'layout' => $web->layout,
            'sort' => $web->sort,
            'nodes' => $web->nodes->sortBy('sort')->values()->map(self::node(...))->all(),
            'edges' => $web->edges->values()->map(self::edge(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function node(TalentNode $node): array
    {
        return [
            'id' => $node->id,
            'key' => $node->key,
            'name' => $node->name,
            'kind' => $node->kind,
            'parent_node_id' => $node->parent_node_id,
            'layout_role' => $node->layout_role,
            'x' => $node->x,
            'y' => $node->y,
            'ring' => $node->ring,
            'gate_level' => $node->gate_level,
            'cost' => $node->cost,
            'description' => $node->description,
            'effects' => $node->effects ?? [],
            'options' => $node->options ?? [],
            'config' => $node->config ?? [],
            'sort' => $node->sort,
            'compendium_item_id' => $node->compendium_item_id,
            'compendium_item' => $node->compendiumItem !== null ? self::compendiumRef($node->compendiumItem) : null,
        ];
    }

    /**
     * The slice of a linked compendium spell/feat the builder and character sheet need to show it and
     * pop its effects — never the whole model, and never the raw import payload.
     *
     * @return array<string, mixed>
     */
    public static function compendiumRef(CampaignCompendiumItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'summary' => $item->summary,
            'fields' => $item->fields ?? [],
            'document' => $item->document,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function edge(TalentEdge $edge): array
    {
        return [
            'id' => $edge->id,
            'from_node_id' => $edge->from_node_id,
            'to_node_id' => $edge->to_node_id,
        ];
    }
}
