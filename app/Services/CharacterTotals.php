<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProgressionMode;
use App\Models\CharacterBuild;
use App\Models\CharacterTalent;
use App\Models\TalentNode;
use App\Support\EffectTree;

/**
 * The authoritative reader of a character's build: how many talent points it has and where they went,
 * and the stat/resource/skill/derived totals its allocated nodes produce. Sums structured node
 * effects — never parses prose — so results are reliable and match what the allocator enforces.
 */
class CharacterTotals
{
    /**
     * The build's point budget.
     *
     * @return array{total: int, spent: int, remaining: int}
     */
    public function points(CharacterBuild $build): array
    {
        $system = $build->ruleSystem;
        $total = $system->startingPoints() + $this->manualAndLevelPoints($build);

        $spent = 0;
        foreach ($build->talents as $talent) {
            $spent += $this->talentCost($talent);
        }

        return [
            'total' => $total,
            'spent' => $spent,
            'remaining' => $total - $spent,
        ];
    }

    /** The level this build effectively plays at: its override, else the character's own level (min 1). */
    public function effectiveLevel(CharacterBuild $build): int
    {
        if ($build->ruleSystem->progressionMode() === ProgressionMode::Xp) {
            return $this->levelForXp($build);
        }

        return max(1, $build->level_override ?? $build->character->level ?? 1);
    }

    /**
     * The full computed sheet for a build.
     *
     * @return array{
     *     level: int,
     *     points: array{total: int, spent: int, remaining: int},
     *     stats: array<string, int>,
     *     resources: array<string, int>,
     *     skills: array<string, array{value: int, proficient: bool}>,
     *     derived: array<string, int>,
     * }
     */
    public function sheet(CharacterBuild $build): array
    {
        $system = $build->ruleSystem;

        $stats = [];
        foreach ($system->stats as $stat) {
            $stats[$stat->key] = $stat->default_value;
        }

        $resources = [];
        $resourcePct = [];
        foreach ($system->resources as $resource) {
            $resources[$resource->key] = $resource->base_value;
            $resourcePct[$resource->key] = 0.0;
        }

        $skills = [];
        foreach ($system->skills as $skill) {
            $skills[$skill->key] = ['value' => 0, 'proficient' => false];
        }

        $derived = [];

        // A chosen race's base modifiers land first — the character's starting point before any points.
        foreach ($this->raceEffects($build) as $effect) {
            $this->applyEffect($effect, $stats, $resources, $resourcePct, $skills, $derived);
        }

        foreach ($build->talents as $talent) {
            foreach ($this->effectsFor($talent) as $effect) {
                $this->applyEffect($effect, $stats, $resources, $resourcePct, $skills, $derived);
            }
        }

        foreach ($resourcePct as $key => $pct) {
            if ($pct !== 0.0) {
                $resources[$key] = (int) round($resources[$key] * (1 + $pct));
            }
        }

        return [
            'level' => $this->effectiveLevel($build),
            'points' => $this->points($build),
            'stats' => $stats,
            'resources' => $resources,
            'skills' => $skills,
            'derived' => $derived,
        ];
    }

    /** The point cost of an allocated talent: its node's cost plus every enhancement rank bought. */
    public function talentCost(CharacterTalent $talent): int
    {
        $cost = $talent->node->cost;

        $enhancements = $this->enhancements($talent->node);
        for ($i = 0; $i < $talent->rank; $i++) {
            $cost += (int) ($enhancements[$i]['cost'] ?? 0);
        }

        return $cost;
    }

    /**
     * The enhancement definitions for a node (rank-up options), from its config.
     *
     * @return list<array<string, mixed>>
     */
    public function enhancements(TalentNode $node): array
    {
        $enhancements = $node->config['enhancements'] ?? [];

        return is_array($enhancements) ? array_values($enhancements) : [];
    }

    /**
     * Points from the level table plus any manual award. In manual mode only the manual award counts.
     */
    private function manualAndLevelPoints(CharacterBuild $build): int
    {
        $manual = $build->manual_points;

        if ($build->ruleSystem->progressionMode() === ProgressionMode::Manual) {
            return $manual;
        }

        $level = $this->effectiveLevel($build);
        $cumulative = $build->ruleSystem->pointsCumulative();

        $fromLevels = 0;
        foreach ($build->ruleSystem->levels as $row) {
            if ($cumulative ? $row->level <= $level : $row->level === $level) {
                $fromLevels += $row->talent_points;
            }
        }

        return $manual + $fromLevels;
    }

    /** The highest level whose XP threshold the build's XP total meets, for XP-based progression. */
    private function levelForXp(CharacterBuild $build): int
    {
        $xp = $build->xp ?? 0;
        $level = 1;

        foreach ($build->ruleSystem->levels as $row) {
            if ($row->xp_required !== null && $xp >= $row->xp_required && $row->level > $level) {
                $level = $row->level;
            }
        }

        return $level;
    }

    /**
     * The base modifiers a character's chosen race contributes, from its compendium entry's
     * stat_modifiers field. Same effect shape as a node; keys that don't match this system are ignored.
     *
     * @return list<array<string, mixed>>
     */
    private function raceEffects(CharacterBuild $build): array
    {
        $race = $build->character?->raceItem;

        return $race === null ? [] : $this->normaliseEffects(data_get($race->fields, 'stat_modifiers'));
    }

    /**
     * The effects a talent contributes: the node's own, its chosen option's, and each bought rank's.
     *
     * @return list<array<string, mixed>>
     */
    private function effectsFor(CharacterTalent $talent): array
    {
        $node = $talent->node;

        // A node authored as a nested tree resolves to the effects of the character's chosen path;
        // otherwise the older flat effects (plus the chosen choose-one option) apply.
        if (EffectTree::has($node)) {
            $effects = $this->normaliseEffects(EffectTree::resolve($node->config['effect_tree'], $talent->choices ?? []));
        } else {
            $effects = $this->normaliseEffects($node->effects);

            if (filled($talent->chosen_option)) {
                foreach ($node->options ?? [] as $option) {
                    if (is_array($option) && ($option['key'] ?? null) === $talent->chosen_option) {
                        $effects = [...$effects, ...$this->normaliseEffects($option['effects'] ?? null)];
                    }
                }
            }
        }

        $enhancements = $this->enhancements($node);
        for ($i = 0; $i < $talent->rank; $i++) {
            $effects = [...$effects, ...$this->normaliseEffects($enhancements[$i]['effects'] ?? null)];
        }

        return $effects;
    }

    /**
     * @param  mixed  $effects
     * @return list<array<string, mixed>>
     */
    private function normaliseEffects($effects): array
    {
        if (! is_array($effects)) {
            return [];
        }

        return array_values(array_filter($effects, static fn ($effect): bool => is_array($effect) && isset($effect['type'], $effect['key'])));
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $resources
     * @param  array<string, float>  $resourcePct
     * @param  array<string, array{value: int, proficient: bool}>  $skills
     * @param  array<string, int>  $derived
     */
    private function applyEffect(array $effect, array &$stats, array &$resources, array &$resourcePct, array &$skills, array &$derived): void
    {
        $type = (string) $effect['type'];
        $key = (string) $effect['key'];
        $delta = (int) ($effect['delta'] ?? 0);

        match ($type) {
            'stat' => $this->addTo($stats, $key, $delta),
            'resource' => $this->applyResource($resources, $resourcePct, $key, $delta, $effect['pct'] ?? null),
            'derived' => $this->addTo($derived, $key, $delta),
            'skill' => $this->applySkill($skills, $key, $delta, (bool) ($effect['proficiency'] ?? false)),
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $bag
     */
    private function addTo(array &$bag, string $key, int $delta): void
    {
        if ($key === '') {
            return;
        }

        $bag[$key] = ($bag[$key] ?? 0) + $delta;
    }

    /**
     * @param  array<string, int>  $resources
     * @param  array<string, float>  $resourcePct
     * @param  mixed  $pct
     */
    private function applyResource(array &$resources, array &$resourcePct, string $key, int $delta, $pct): void
    {
        if (! array_key_exists($key, $resources)) {
            return;
        }

        $resources[$key] += $delta;

        if (is_numeric($pct)) {
            $resourcePct[$key] += (float) $pct;
        }
    }

    /**
     * @param  array<string, array{value: int, proficient: bool}>  $skills
     */
    private function applySkill(array &$skills, string $key, int $delta, bool $proficiency): void
    {
        if (! array_key_exists($key, $skills)) {
            return;
        }

        $skills[$key]['value'] += $delta;

        if ($proficiency) {
            $skills[$key]['proficient'] = true;
        }
    }
}
