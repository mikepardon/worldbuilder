<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TalentNode;

/**
 * A node's benefits as a nested AND/OR tree. A group applies ALL of its children, or (ANY) exactly one
 * the player chooses; a leaf holds a single effect. Any node may carry a `requires` prerequisite. The
 * shape is:
 *   group = {id, op:'all'|'any', label?, requires?, children:[node,…]}
 *   leaf  = {id, label?, requires?, effect:{type,key?,delta?,pct?,proficiency?,item_id?}}
 *
 * {@see self::resolve()} turns a character's choices into the effects that apply (requirements are
 * validated when the node is allocated, not re-checked here); {@see self::problems()} reports whether a
 * given set of choices is complete and every node on the chosen path is qualified.
 */
class EffectTree
{
    /** Whether a node's benefits are authored as a tree (rather than the older effects/options). */
    public static function has(TalentNode $node): bool
    {
        return is_array($node->config['effect_tree'] ?? null);
    }

    /**
     * The effects that apply for a character's chosen path.
     *
     * @param  mixed  $tree
     * @param  array<string, string>  $choices  ANY-group id → chosen child id
     * @return list<array<string, mixed>>
     */
    public static function resolve($tree, array $choices): array
    {
        return self::resolveNode($tree, $choices);
    }

    /**
     * Problems with a set of choices: an ANY group with no valid choice ('choose'), or a node on the
     * chosen path whose requirement isn't met ('requirement'). Empty means the choices are allocatable.
     *
     * @param  mixed  $tree
     * @param  array<string, string>  $choices
     * @param  callable(array<string, mixed>): bool  $requirementMet
     * @return list<string>
     */
    public static function problems($tree, array $choices, callable $requirementMet): array
    {
        $problems = [];
        self::walk($tree, $choices, $requirementMet, $problems);

        return $problems;
    }

    /**
     * @param  mixed  $node
     * @param  array<string, string>  $choices
     * @return list<array<string, mixed>>
     */
    private static function resolveNode($node, array $choices): array
    {
        if (! is_array($node)) {
            return [];
        }

        if (self::isGroup($node)) {
            if (($node['op'] ?? 'all') === 'any') {
                $chosen = self::chosenChild($node, $choices);

                return $chosen === null ? [] : self::resolveNode($chosen, $choices);
            }

            $out = [];
            foreach ($node['children'] as $child) {
                $out = [...$out, ...self::resolveNode($child, $choices)];
            }

            return $out;
        }

        $effect = $node['effect'] ?? null;

        return is_array($effect) && isset($effect['type']) ? [$effect] : [];
    }

    /**
     * @param  mixed  $node
     * @param  array<string, string>  $choices
     * @param  list<string>  $problems
     */
    private static function walk($node, array $choices, callable $requirementMet, array &$problems): void
    {
        if (! is_array($node)) {
            return;
        }

        if (isset($node['requires']) && is_array($node['requires']) && ! $requirementMet($node['requires'])) {
            $problems[] = 'requirement';

            return;
        }

        if (! self::isGroup($node)) {
            return;
        }

        if (($node['op'] ?? 'all') === 'any') {
            $chosen = self::chosenChild($node, $choices);
            if ($chosen === null) {
                $problems[] = 'choose';

                return;
            }
            self::walk($chosen, $choices, $requirementMet, $problems);

            return;
        }

        foreach ($node['children'] as $child) {
            self::walk($child, $choices, $requirementMet, $problems);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function isGroup(array $node): bool
    {
        return isset($node['children']) && is_array($node['children']);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, string>  $choices
     * @return array<string, mixed>|null
     */
    private static function chosenChild(array $group, array $choices): ?array
    {
        $chosenId = $choices[(string) ($group['id'] ?? '')] ?? null;
        if ($chosenId === null) {
            return null;
        }

        foreach ($group['children'] as $child) {
            if (is_array($child) && ($child['id'] ?? null) === $chosenId) {
                return $child;
            }
        }

        return null;
    }
}
