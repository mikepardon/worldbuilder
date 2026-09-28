<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NodeShape;
use App\Enums\ProgressionMode;
use App\Models\CampaignCompendiumItem;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\RuleSystem;
use App\Models\TalentWeb;
use App\Services\WorldCompendiumImporter;

/**
 * Builds a rich, ready-to-play starter system onto a {@see RuleSystem} — "Ascendancy", the web from the
 * design, rendered as real data. Five stats, two resources, the fifteen skills, a level table, the
 * eight node kinds, and a radial web of gateways → avenues → rings, laid out with the same maths the
 * design used (centre 1300,1300; ring radii per tier). Used by the seeder and the starter button.
 */
class DemoRuleSystem
{
    private const CENTRE = 1300;

    /** Radius from the hub of the gateway, the inner avenue cluster, and the outer avenue cluster. */
    private const CLR = ['gate' => 300, 'inner' => 660, 'outer' => 1030];

    /** Degrees between an avenue and its tree's axis (the two avenues sit at ∓ half this). */
    private const AV_SPREAD = 44.0;

    /**
     * The five stats keyed as the design had them, each with the skills it governs.
     *
     * @var array<string, array{label: string, abbr: string, desc: string}>
     */
    private const STATS = [
        'str' => ['label' => 'Strength', 'abbr' => 'STR', 'desc' => 'Melee attack and damage, Athletics, and what you can carry or break.'],
        'dex' => ['label' => 'Dexterity', 'abbr' => 'DEX', 'desc' => 'Initiative, light-armour defence, finesse and ranged attacks, Stealth.'],
        'int' => ['label' => 'Intelligence', 'abbr' => 'INT', 'desc' => 'Arcane spell power, Arcana and Investigation.'],
        'wis' => ['label' => 'Wisdom', 'abbr' => 'WIS', 'desc' => 'Spirit magic, Insight, Medicine, Perception and saves against fear.'],
        'cha' => ['label' => 'Charisma', 'abbr' => 'CHA', 'desc' => 'Command, Persuasion and Performance, and social talents.'],
    ];

    /** @var array<string, string> skill key => governing stat key */
    private const SKILLS = [
        'acrobatics' => 'dex', 'arcana' => 'int', 'athletics' => 'str', 'history' => 'int',
        'insight' => 'wis', 'investigation' => 'int', 'medicine' => 'wis', 'nature' => 'wis',
        'perception' => 'wis', 'performance' => 'cha', 'persuasion' => 'cha', 'religion' => 'wis',
        'sleight-of-hand' => 'dex', 'stealth' => 'dex', 'survival' => 'wis',
    ];

    /** @var array<string, array{label: string, cost: int, shape: NodeShape, glyph: string, size: int, cap: int|null}> */
    private const NODE_KINDS = [
        'origin' => ['label' => 'Origin', 'cost' => 0, 'shape' => NodeShape::Circle, 'glyph' => '✵', 'size' => 78, 'cap' => null],
        'gateway' => ['label' => 'Gateway', 'cost' => 1, 'shape' => NodeShape::Circle, 'glyph' => '◆', 'size' => 40, 'cap' => null],
        'minor' => ['label' => 'Minor', 'cost' => 1, 'shape' => NodeShape::Circle, 'glyph' => '', 'size' => 24, 'cap' => null],
        'attribute' => ['label' => 'Attribute', 'cost' => 2, 'shape' => NodeShape::Circle, 'glyph' => '+1', 'size' => 34, 'cap' => null],
        'notable' => ['label' => 'Notable', 'cost' => 2, 'shape' => NodeShape::Square, 'glyph' => '✦', 'size' => 34, 'cap' => null],
        'upgrade' => ['label' => 'Upgrade', 'cost' => 2, 'shape' => NodeShape::Square, 'glyph' => '▲', 'size' => 34, 'cap' => null],
        'keystone' => ['label' => 'Keystone', 'cost' => 3, 'shape' => NodeShape::Star, 'glyph' => '★', 'size' => 60, 'cap' => 2],
        'bridge' => ['label' => 'Bridge', 'cost' => 3, 'shape' => NodeShape::Diamond, 'glyph' => '⬢', 'size' => 46, 'cap' => null],
    ];

    /**
     * The five trees: a stat, an accent colour, an angle, a gateway, and two avenues each with a chain
     * of ring nodes.
     *
     * @var list<array{key: string, name: string, stat: string, colour: string, angle: float, avenues: list<array{key: string, name: string, rings: array<int, array{0: string, 1: string, 2: string, 3?: array<string, mixed>}>}>}>
     */
    private const TREES = [
        ['key' => 'phy', 'name' => 'Physical', 'stat' => 'str', 'colour' => '#d1483f', 'angle' => -90.0, 'avenues' => [
            ['key' => 'ber', 'name' => 'Berserker', 'rings' => [
                1 => ['notable', 'Two-handed training', 'Proficiency with greataxes, greatswords, mauls and polearms.'],
                2 => ['attribute', 'Might', '+1 Strength.', ['effects' => [['type' => 'stat', 'key' => 'str', 'delta' => 1]]]],
                3 => ['upgrade', 'Reckless attack', 'Gain advantage on melee attacks; enemies gain advantage against you until your next turn.'],
                4 => ['keystone', 'Blood frenzy', 'While raging, each hit heals you 1d4.', ['config' => ['drawback' => 'You cannot end rage early and must attack the nearest enemy.']]],
            ]],
            ['key' => 'gua', 'name' => 'Guardian', 'rings' => [
                1 => ['notable', 'Shield training', 'Proficiency with shields (+2 AC).'],
                2 => ['notable', 'Medium armour training', 'Wear medium armour without penalty; your frame hardens to carry it.', ['effects' => [['type' => 'resource', 'key' => 'hp', 'delta' => 3]]]],
                3 => ['upgrade', 'Heavy armour training', 'Wear heavy armour without penalty; your frame hardens further.', ['effects' => [['type' => 'resource', 'key' => 'hp', 'delta' => 5]]]],
                4 => ['keystone', 'Bulwark', 'The first hit each round on an adjacent ally is redirected to you.', ['config' => ['drawback' => 'Your movement is halved.']]],
            ]],
        ]],
        ['key' => 'agi', 'name' => 'Agility', 'stat' => 'dex', 'colour' => '#5aa05f', 'angle' => -18.0, 'avenues' => [
            ['key' => 'due', 'name' => 'Duelist', 'rings' => [
                1 => ['notable', 'Finesse training', 'Use DEX for attack and damage with light and finesse weapons.'],
                2 => ['attribute', 'Grace', '+1 Dexterity.', ['effects' => [['type' => 'stat', 'key' => 'dex', 'delta' => 1]]]],
                3 => ['upgrade', 'Riposte', 'Reaction: when a melee attack misses you, make one attack against the attacker.'],
                4 => ['keystone', 'Blade dancer', '+2 AC while dual wielding.', ['config' => ['drawback' => 'You cannot use shields, or medium or heavy armour.']]],
            ]],
            ['key' => 'sha', 'name' => 'Shadow', 'rings' => [
                1 => ['notable', 'Skulker', "Proficiency in Stealth and thieves' tools.", ['effects' => [['type' => 'skill', 'key' => 'stealth', 'proficiency' => true]]]],
                2 => ['notable', 'Sneak attack', 'Once per turn, +1d6 damage when you have advantage or an ally flanks.'],
                3 => ['upgrade', 'Evasion', 'On a successful DEX save for half damage, take none instead.', ['effects' => [['type' => 'resource', 'key' => 'hp', 'delta' => 3]]]],
                4 => ['keystone', "Assassin's mark", "Hits against creatures that haven't acted are automatic crits.", ['config' => ['drawback' => 'Your maximum Life is reduced by a quarter.', 'solo' => false]]],
            ]],
        ]],
        ['key' => 'min', 'name' => 'Mind', 'stat' => 'int', 'colour' => '#4d7fd1', 'angle' => 54.0, 'avenues' => [
            ['key' => 'evo', 'name' => 'Evoker', 'rings' => [
                1 => ['notable', 'Firebolt', 'A ranged spell attack dealing 1d10 fire.', ['config' => ['mana' => 0], 'effects' => [['type' => 'resource', 'key' => 'mana', 'delta' => 1]]]],
                2 => ['attribute', 'Acuity', '+1 Intelligence.', ['effects' => [['type' => 'stat', 'key' => 'int', 'delta' => 1]]]],
                3 => ['upgrade', 'Fireball', 'Advance your Fire spell to its 4th rank — a roaring blast in a radius.', ['config' => ['mana' => 8, 'level_spell' => ['grant' => 'Firebolt', 'to_level' => 4]]]],
                4 => ['keystone', 'Overchannel', 'Once per rest, cast a spell as if you spent double the Mana.', ['config' => ['drawback' => 'Take 2d6 force damage each time you overchannel.']]],
            ]],
            ['key' => 'war', 'name' => 'Abjurer', 'rings' => [
                1 => ['notable', 'Arcane ward', 'A ward that absorbs damage equal to your INT each rest.'],
                2 => ['notable', 'Shield', 'Reaction: +5 AC until your next turn.', ['config' => ['mana' => 1]]],
                3 => ['upgrade', 'Counterspell', 'Reaction: attempt to interrupt a spell as it is cast.', ['config' => ['mana' => 2]]],
                4 => ['keystone', 'Spellbreaker', 'You have advantage on saves against spells.', ['config' => ['drawback' => 'You can no longer be the target of ally buffs.']]],
            ]],
        ]],
        ['key' => 'spi', 'name' => 'Spirit', 'stat' => 'wis', 'colour' => '#8a5ad1', 'angle' => 126.0, 'avenues' => [
            ['key' => 'hea', 'name' => 'Cleric', 'rings' => [
                1 => ['notable', 'Mend', 'Touch a creature to heal 1d8.', ['config' => ['mana' => 1]]],
                2 => ['attribute', 'Devotion', '+1 Wisdom.', ['effects' => [['type' => 'stat', 'key' => 'wis', 'delta' => 1]]]],
                3 => ['upgrade', 'Mass healing', 'Heal every ally in a radius for 3d8.', ['config' => ['mana' => 4]]],
                4 => ['keystone', 'Lifebloom', 'Healing you cast also grants that much temporary Life.', ['config' => ['drawback' => 'Your own healing is halved.']]],
            ]],
            ['key' => 'wil', 'name' => 'Druid', 'rings' => [
                1 => ['notable', 'Entangle', 'Restrain creatures in an area with grasping growth.', ['config' => ['mana' => 1]]],
                2 => ['notable', 'Wild shape', 'Assume a beast form once per rest.'],
                3 => ['upgrade', 'Second skin', 'Learn a second beast form and swap freely while shifted.'],
                4 => ['keystone', 'Heart of the wild', 'Your beast forms gain your talents.', ['config' => ['drawback' => 'You cannot wear worked-metal armour.']]],
            ]],
        ]],
        ['key' => 'pre', 'name' => 'Presence', 'stat' => 'cha', 'colour' => '#d1a23f', 'angle' => 198.0, 'avenues' => [
            ['key' => 'tac', 'name' => 'Commander', 'rings' => [
                1 => ['notable', 'Command', 'Bonus action: direct an ally to attack or move.'],
                2 => ['attribute', 'Presence', '+1 Charisma.', ['effects' => [['type' => 'stat', 'key' => 'cha', 'delta' => 1]]]],
                3 => ['upgrade', 'Inspiration', 'Grant an ally a bonus die on their next roll.'],
                4 => ['keystone', 'Warlord', 'Allies within 30 ft add your CHA to their damage once per turn.', ['config' => ['drawback' => 'You cannot benefit from your own inspiration.']]],
            ]],
            ['key' => 'ins', 'name' => 'Bard', 'rings' => [
                1 => ['notable', 'Vicious mockery', 'A jeer that deals 1d4 psychic and imposes disadvantage.', ['config' => ['mana' => 0]]],
                2 => ['notable', 'Charm person', 'Bend a humanoid to friendliness for a time.', ['config' => ['mana' => 1]]],
                3 => ['upgrade', 'Cutting words', 'Reaction: subtract a die from an enemy roll.'],
                4 => ['keystone', 'Maestro', 'Your performances affect twice as many creatures.', ['config' => ['drawback' => 'Concentration breaks if you take any damage.']]],
            ]],
        ]],
    ];

    /** @var array<string, array{type: string, id: int}> slug → the library entry a matching node grants */
    private array $grantMap = [];

    public function populate(RuleSystem $system): void
    {
        $this->seedStats($system);
        $this->seedSkills($system);
        $this->seedResources($system);
        $this->seedNodeKinds($system);
        $this->seedLevels($system);
        $this->prepareGrants($system);
        $this->seedWeb($system);
    }

    /**
     * Wire the system to the Worldbuilder spell/feat/ability compendiums and build a slug→entry map, so a
     * node named after a library entry grants it. For a world system the entries are imported into the
     * world and mapped to the world copies; a template maps straight to the global library.
     */
    private function prepareGrants(RuleSystem $system): void
    {
        $sources = CompendiumSource::whereIn('key', ['worldbuilder-spell', 'worldbuilder-ability', 'worldbuilder-feat', 'worldbuilder-race'])->get();
        if ($sources->isEmpty()) {
            return;
        }

        $sourceIds = $sources->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $system->update(['settings' => array_merge((array) $system->settings, ['compendium_sources' => $sourceIds])]);

        if ($system->world_id !== null && $system->world !== null) {
            app(WorldCompendiumImporter::class)->importSources($system->world, $sourceIds, (int) ($system->user_id ?? $system->world->user_id ?? 0));
            $items = CampaignCompendiumItem::where('world_id', $system->world_id)->whereIn('item_type', ['spell', 'ability', 'feat'])->get(['id', 'name', 'item_type']);
        } else {
            $items = CompendiumItem::whereIn('source_id', $sourceIds)->get(['id', 'name', 'item_type']);
        }

        // Keyed by name (lowercased) so a node grants the library entry it's named after.
        foreach ($items as $item) {
            $this->grantMap[mb_strtolower((string) $item->name)] = ['type' => $item->item_type, 'id' => (int) $item->id];
        }
    }

    /** The default settings block a fresh copy of this system should carry. */
    public static function defaultSettings(): array
    {
        return [
            'progression_mode' => ProgressionMode::Milestone->value,
            'points_cumulative' => true,
            'starting_points' => 0,
            'level_cap' => 20,
            'role_kinds' => ['child' => 'minor', 'adjacent' => 'minor', 'master' => 'keystone'],
        ];
    }

    private function seedStats(RuleSystem $system): void
    {
        $sort = 0;
        foreach (self::STATS as $key => $stat) {
            $system->stats()->create([
                'key' => $key,
                'label' => $stat['label'],
                'abbreviation' => $stat['abbr'],
                'description' => $stat['desc'],
                'default_value' => 3,
                'sort' => $sort++,
            ]);
        }
    }

    private function seedSkills(RuleSystem $system): void
    {
        $sort = 0;
        foreach (self::SKILLS as $key => $stat) {
            $system->skills()->create([
                'key' => $key,
                'label' => ucwords(str_replace('-', ' ', $key)),
                'governing_stat_key' => $stat,
                'sort' => $sort++,
            ]);
        }
    }

    private function seedResources(RuleSystem $system): void
    {
        $system->resources()->create(['key' => 'hp', 'label' => 'Life', 'base_value' => 10, 'colour' => '#e8a494', 'sort' => 0]);
        $system->resources()->create(['key' => 'mana', 'label' => 'Mana', 'base_value' => 4, 'colour' => '#bcb0e8', 'sort' => 1]);
    }

    private function seedNodeKinds(RuleSystem $system): void
    {
        $sort = 0;
        foreach (self::NODE_KINDS as $key => $kind) {
            $system->nodeKinds()->create([
                'key' => $key,
                'label' => $kind['label'],
                'default_cost' => $kind['cost'],
                'shape' => $kind['shape'],
                'glyph' => $kind['glyph'],
                'size' => $kind['size'],
                'max_per_character' => $kind['cap'],
                'sort' => $sort++,
            ]);
        }
    }

    private function seedLevels(RuleSystem $system): void
    {
        // A gently scaling table: more points at the milestones players care about.
        $awards = [1 => 0, 2 => 5, 3 => 3, 4 => 3, 5 => 5, 6 => 3, 7 => 3, 8 => 5, 9 => 3, 10 => 5,
            11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 5, 16 => 3, 17 => 3, 18 => 3, 19 => 3, 20 => 6];
        foreach ($awards as $level => $points) {
            $system->levels()->create(['level' => $level, 'talent_points' => $points]);
        }
    }

    /**
     * Small "connective tissue" passives threaded between the notable nodes, cycled for variety so the
     * web reads dense like a passive tree rather than a sparse chain.
     *
     * @var list<array{name: string, description: string, effects: list<array<string, mixed>>}>
     */
    private const MINORS = [
        ['name' => 'Vitality', 'description' => '+2 maximum Life.', 'effects' => [['type' => 'resource', 'key' => 'hp', 'delta' => 2]]],
        ['name' => 'Focus', 'description' => '+1 maximum Mana.', 'effects' => [['type' => 'resource', 'key' => 'mana', 'delta' => 1]]],
        ['name' => 'Alertness', 'description' => '+1 initiative.', 'effects' => [['type' => 'derived', 'key' => 'initiative', 'delta' => 1]]],
        ['name' => 'Fleet foot', 'description' => '+5 ft movement speed.', 'effects' => [['type' => 'derived', 'key' => 'speed', 'delta' => 5]]],
        ['name' => 'Hardy', 'description' => '+3 maximum Life.', 'effects' => [['type' => 'resource', 'key' => 'hp', 'delta' => 3]]],
    ];

    private function seedWeb(RuleSystem $system): void
    {
        $blueprint = $this->buildBlueprint();

        /** @var TalentWeb $web */
        $web = $system->webs()->create([
            'name' => 'Ascendancy',
            'description' => 'The core talent web — five trees of stats, skills, spells and abilities.',
            'layout' => [
                'centre' => ['x' => self::CENTRE, 'y' => self::CENTRE],
                'discs' => $blueprint['discs'],
                'titles' => $blueprint['titles'],
            ],
        ]);

        $ids = [];
        foreach ($blueprint['nodes'] as $spec) {
            $ids[$spec['key']] = $web->nodes()->create($spec)->id;
        }

        $made = [];
        foreach ($blueprint['edges'] as [$a, $b]) {
            $pair = $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";
            if ($a !== $b && isset($ids[$a], $ids[$b]) && ! isset($made[$pair])) {
                $made[$pair] = true;
                $web->edges()->create(['from_node_id' => $ids[$a], 'to_node_id' => $ids[$b]]);
            }
        }
    }

    /**
     * Compute the orbital-cluster geometry of the demo web, ported from the reference "Talent Web v4":
     * a hub, gateway nodes each ringed by six minors, and every avenue rendered as an inner and outer
     * cluster of nodes circling a centre — the keystone sits at the outer centre and others branch off
     * it — joined by minor corridors.
     *
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array{0: string, 1: string}>,
     *     discs: list<array<string, int>>,
     *     titles: list<array<string, mixed>>,
     * }
     */
    private function buildBlueprint(): array
    {
        $nodes = [];
        $edges = [];
        $discs = [];
        $titles = [];
        $pos = [];
        $minorCounter = 0;

        $push = function (array $spec) use (&$nodes, &$pos): void {
            $nodes[] = $spec;
            $pos[$spec['key']] = [$spec['x'], $spec['y']];
        };
        $link = function (string $a, string $b) use (&$edges): void {
            $edges[] = [$a, $b];
        };
        $minor = function (string $key, int $ring, float $x, float $y, string $tree, string $avenue, string $colour) use ($push, &$minorCounter): void {
            $definition = self::MINORS[$minorCounter % count(self::MINORS)];
            $minorCounter++;
            $push([
                'key' => $key, 'name' => $definition['name'], 'kind' => 'minor',
                'x' => (int) round($x), 'y' => (int) round($y), 'ring' => $ring,
                'gate_level' => $this->gateForRing($ring), 'cost' => self::NODE_KINDS['minor']['cost'],
                'description' => $definition['description'], 'effects' => $definition['effects'],
                'config' => ['tree' => $tree, 'avenue' => $avenue, 'colour' => $colour],
            ]);
        };
        $talent = function (string $key, array $definition, int $ring, array $tree, string $avenue, float $x, float $y) use ($push): void {
            $kind = $definition[0];
            $extra = $definition[3] ?? [];
            $push([
                'key' => $key, 'name' => $definition[1], 'kind' => $kind,
                'x' => (int) round($x), 'y' => (int) round($y), 'ring' => $ring,
                'gate_level' => $this->gateForRing($ring), 'cost' => self::NODE_KINDS[$kind]['cost'],
                'description' => $definition[2], 'effects' => $extra['effects'] ?? null,
                'config' => array_merge(['tree' => $tree['key'], 'avenue' => $avenue, 'colour' => $tree['colour']], $extra['config'] ?? []),
            ]);
        };
        $nearest = function (array $keys, float $x, float $y) use (&$pos): string {
            $best = $keys[0];
            $bestDistance = INF;
            foreach ($keys as $key) {
                [$px, $py] = $pos[$key];
                $distance = ($px - $x) ** 2 + ($py - $y) ** 2;
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $key;
                }
            }

            return $best;
        };
        $corridor = function (string $prefix, string $fromKey, string $toKey, int $count, int $ring, string $tree, string $avenue, string $colour) use (&$pos, &$edges, $minor): void {
            [$fx, $fy] = $pos[$fromKey];
            [$tx, $ty] = $pos[$toKey];
            $previous = $fromKey;
            for ($i = 0; $i < $count; $i++) {
                $t = ($i + 1) / ($count + 1);
                $key = $prefix.'_c'.$i;
                $minor($key, $ring, $fx + ($tx - $fx) * $t, $fy + ($ty - $fy) * $t, $tree, $avenue, $colour);
                $edges[] = [$previous, $key];
                $previous = $key;
            }
            $edges[] = [$previous, $toKey];
        };

        $push([
            'key' => 'origin', 'name' => 'Origin', 'kind' => 'origin',
            'x' => self::CENTRE, 'y' => self::CENTRE, 'ring' => 0, 'gate_level' => 1, 'cost' => 0,
            'description' => 'Every character begins here. Spend your first point on a gateway to open the tree beyond it.',
            'config' => ['origin' => true],
        ]);
        $discs[] = ['x' => self::CENTRE, 'y' => self::CENTRE, 'size' => 200];

        foreach (self::TREES as $tree) {
            [$gx, $gy] = $this->position($tree['angle'], self::CLR['gate']);
            $gatewayKey = $tree['key'].'-gateway';
            $push([
                'key' => $gatewayKey, 'name' => $tree['name'], 'kind' => 'gateway',
                'x' => $gx, 'y' => $gy, 'ring' => 0, 'gate_level' => 1, 'cost' => self::NODE_KINDS['gateway']['cost'],
                'description' => "The gateway into the {$tree['name']} tree. +1 {$tree['stat']}.",
                'effects' => [['type' => 'stat', 'key' => $tree['stat'], 'delta' => 1]],
                'config' => ['colour' => $tree['colour'], 'tree' => $tree['key']],
            ]);
            $link('origin', $gatewayKey);
            $discs[] = ['x' => $gx, 'y' => $gy, 'size' => 232];

            $gateRing = [];
            for ($i = 0; $i < 6; $i++) {
                $angle = deg2rad($tree['angle'] + 180 + $i * 60);
                $key = $tree['key'].'-g'.$i;
                $minor($key, 1, $gx + cos($angle) * 86, $gy + sin($angle) * 86, $tree['key'], $tree['name'], $tree['colour']);
                $gateRing[] = $key;
            }
            foreach ($gateRing as $i => $key) {
                $link($key, $gateRing[($i + 1) % 6]);
                $link($gatewayKey, $key);
            }

            $avenueCount = count($tree['avenues']);
            foreach ($tree['avenues'] as $ai => $avenue) {
                $avenueAngle = $tree['angle'] + ($ai - ($avenueCount - 1) / 2) * self::AV_SPREAD;
                $rings = $avenue['rings'];

                // Inner cluster: a hub node at the centre (so every cluster has a master in the middle,
                // never just floating text), ringed by the avenue's early talents and minors.
                [$ix, $iy] = $this->position($avenueAngle, self::CLR['inner']);
                $discs[] = ['x' => $ix, 'y' => $iy, 'size' => 248];

                $hubKey = $avenue['key'].'-hub';
                $push([
                    'key' => $hubKey, 'name' => $avenue['name'], 'kind' => 'bridge',
                    'x' => $ix, 'y' => $iy, 'ring' => 1, 'gate_level' => $this->gateForRing(1),
                    'cost' => self::NODE_KINDS['bridge']['cost'],
                    'description' => "The heart of the {$avenue['name']} avenue — the {$tree['name']} tree.",
                    'config' => ['tree' => $tree['key'], 'avenue' => $avenue['name'], 'colour' => $tree['colour']],
                ]);

                $innerItems = [['minor' => true]];
                if (isset($rings[1])) {
                    $innerItems[] = ['t' => $rings[1], 'ring' => 1];
                }
                $innerItems[] = ['minor' => true];
                if (isset($rings[2])) {
                    $innerItems[] = ['t' => $rings[2], 'ring' => 2];
                }
                $innerItems[] = ['minor' => true];
                while (count($innerItems) < 7) {
                    $innerItems[] = ['minor' => true];
                }

                $innerRing = $this->placeRing($innerItems, $ix, $iy, 104, 1, $avenue, $tree, $minor, $talent, 'i');
                foreach ($innerRing as $i => $key) {
                    $link($key, $innerRing[($i + 1) % count($innerRing)]);
                }
                // Wire the hub to a couple of opposite ring nodes so it's reachable and reads as the centre.
                $link($hubKey, $innerRing[0]);
                $link($hubKey, $innerRing[intdiv(count($innerRing), 2)]);
                $corridor($avenue['key'].'-gi', $nearest($gateRing, $pos[$innerRing[0]][0], $pos[$innerRing[0]][1]), $innerRing[0], 3, 1, $tree['key'], $avenue['name'], $tree['colour']);

                // Outer cluster: minors and the ring 3 talent, with the keystone at the centre.
                [$ox, $oy] = $this->position($avenueAngle, self::CLR['outer']);
                $discs[] = ['x' => $ox, 'y' => $oy, 'size' => 252];
                $outerItems = [['minor' => true]];
                if (isset($rings[3])) {
                    $outerItems[] = ['t' => $rings[3], 'ring' => 3];
                }
                $outerItems[] = ['minor' => true];
                while (count($outerItems) < 6) {
                    $outerItems[] = ['minor' => true];
                }

                $outerRing = $this->placeRing($outerItems, $ox, $oy, 106, 3, $avenue, $tree, $minor, $talent, 'o');
                foreach ($outerRing as $i => $key) {
                    $link($key, $outerRing[($i + 1) % count($outerRing)]);
                }
                $corridor($avenue['key'].'-io', $innerRing[intdiv(count($innerRing), 2)], $outerRing[0], 3, 3, $tree['key'], $avenue['name'], $tree['colour']);

                if (isset($rings[4])) {
                    $keystoneKey = $avenue['key'].'-key';
                    $talent($keystoneKey, $rings[4], 4, $tree, $avenue['name'], $ox, $oy);
                    $mid = intdiv(count($outerRing), 2);
                    foreach ([$mid - 1, $mid, $mid + 1] as $j) {
                        $link($keystoneKey, $outerRing[($j + count($outerRing)) % count($outerRing)]);
                    }
                }
            }
        }

        // A node named after a Worldbuilder library entry grants it — so the starter's spells and
        // abilities are real, clickable compendium entries rather than just prose on the node.
        foreach ($nodes as $index => $spec) {
            $grant = $this->grantMap[mb_strtolower((string) $spec['name'])] ?? null;
            if ($grant !== null) {
                $effects = is_array($spec['effects'] ?? null) ? $spec['effects'] : [];
                $effects[] = ['type' => $grant['type'], 'item_id' => $grant['id']];
                $nodes[$index]['effects'] = $effects;
            }
        }

        // A node marked with a spell-level advance raises a learned spell to a higher rank when allocated
        // (a no-op unless the character has already learned that spell), gated per-rank by its own min_level.
        foreach ($nodes as $index => $spec) {
            $advance = $spec['config']['level_spell'] ?? null;
            if (! is_array($advance)) {
                continue;
            }
            $target = $this->grantMap[mb_strtolower((string) ($advance['grant'] ?? ''))] ?? null;
            if ($target !== null && $target['type'] === 'spell') {
                $effects = is_array($nodes[$index]['effects'] ?? null) ? $nodes[$index]['effects'] : [];
                $effects[] = ['type' => 'level_spell', 'item_id' => $target['id'], 'to_level' => (int) ($advance['to_level'] ?? 1)];
                $nodes[$index]['effects'] = $effects;
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'discs' => $discs, 'titles' => $titles];
    }

    /**
     * Place a cluster's items evenly around a circle centred on the hub-facing entry point, creating
     * each as a minor or a talent, and return their keys in ring order.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $avenue
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    private function placeRing(array $items, int $cx, int $cy, int $radius, int $minorRing, array $avenue, array $tree, callable $minor, callable $talent, string $suffix): array
    {
        $count = count($items);
        $entry = rad2deg(atan2($cy - self::CENTRE, $cx - self::CENTRE)) + 180;
        $keys = [];
        foreach ($items as $i => $item) {
            $angle = deg2rad($entry + $i * (360 / $count));
            $x = $cx + cos($angle) * $radius;
            $y = $cy + sin($angle) * $radius;
            $key = $avenue['key'].'-'.$suffix.$i;
            if (isset($item['minor'])) {
                $minor($key, $minorRing, $x, $y, $tree['key'], $avenue['name'], $tree['colour']);
            } else {
                $talent($key, $item['t'], $item['ring'], $tree, $avenue['name'], $x, $y);
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** The level a ring's nodes gate behind, mirroring the design's tiered unlocks. */
    private function gateForRing(int $ring): int
    {
        return match ($ring) {
            1 => 1,
            2 => 3,
            3 => 6,
            4 => 10,
            default => 1,
        };
    }

    /**
     * Cartesian coordinates for a polar position around the web centre.
     *
     * @return array{0: int, 1: int}
     */
    private function position(float $angleDegrees, int $radius): array
    {
        $radians = deg2rad($angleDegrees);

        return [
            (int) round(self::CENTRE + $radius * cos($radians)),
            (int) round(self::CENTRE + $radius * sin($radians)),
        ];
    }
}
