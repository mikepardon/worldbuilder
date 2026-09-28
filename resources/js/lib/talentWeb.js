// Shared talent-web logic for the builder and the player's character builder. This mirrors the
// server's App\Services\CharacterTotals + TalentAllocator so the UI can preview budgets, totals and
// legality instantly — the server stays authoritative and re-checks every mutation.

/** Map a system's node kinds by key. */
export const kindMap = (system) => {
    const map = {};
    for (const kind of system?.nodeKinds ?? []) {
        map[kind.key] = kind;
    }
    return map;
};

/** Undirected adjacency list for a web, keyed by node id. */
export const adjacency = (web) => {
    const adj = {};
    for (const node of web?.nodes ?? []) {
        adj[node.id] = [];
    }
    for (const edge of web?.edges ?? []) {
        if (adj[edge.from_node_id] && adj[edge.to_node_id]) {
            adj[edge.from_node_id].push(edge.to_node_id);
            adj[edge.to_node_id].push(edge.from_node_id);
        }
    }
    return adj;
};

const isRoot = (node) => Boolean(node?.config?.origin);

/** Whether a node can be dragged directly in layout mode — hubs and standalone siblings, not followers. */
export const isDraggableRole = (node) => node?.layout_role === 'master' || node?.layout_role === 'adjacent';

/**
 * Every node that follows a given node when it is dragged: its children, their children, and so on
 * (via parent_node_id). The node itself is not included. Used so moving a hub carries its ring/branch.
 */
export const descendantIds = (web, nodeId) => {
    const childrenOf = {};
    for (const node of web?.nodes ?? []) {
        if (node.parent_node_id != undefined) {
            (childrenOf[node.parent_node_id] ??= []).push(node.id);
        }
    }
    const result = new Set();
    const stack = [...(childrenOf[nodeId] ?? [])];
    while (stack.length) {
        const id = stack.pop();
        if (result.has(id)) {
            continue;
        }
        result.add(id);
        stack.push(...(childrenOf[id] ?? []));
    }
    return result;
};

/** The effects a nested effect tree contributes for a given set of ANY-group choices. */
export const resolveTree = (node, choices) => {
    if (!node || typeof node !== 'object') {
        return [];
    }
    if (Array.isArray(node.children)) {
        if (node.op === 'any') {
            const chosen = node.children.find((child) => child && child.id === choices?.[node.id]);
            return chosen ? resolveTree(chosen, choices) : [];
        }
        return node.children.flatMap((child) => resolveTree(child, choices));
    }
    return node.effect && node.effect.type ? [node.effect] : [];
};

/** Every effect a talent contributes: its tree path (or flat effects + chosen option), and bought ranks. */
const effectsFor = (node, allocation) => {
    const tree = node.config?.effect_tree;
    let effects;
    if (tree && typeof tree === 'object') {
        effects = resolveTree(tree, allocation?.choices ?? {});
    } else {
        effects = Array.isArray(node.effects) ? [...node.effects] : [];
        if (allocation?.chosen_option && Array.isArray(node.options)) {
            const option = node.options.find((entry) => entry.key === allocation.chosen_option);
            if (option && Array.isArray(option.effects)) {
                effects = [...effects, ...option.effects];
            }
        }
    }

    const enhancements = node.config?.enhancements ?? [];
    for (let i = 0; i < (allocation?.rank ?? 0); i++) {
        if (Array.isArray(enhancements[i]?.effects)) {
            effects = [...effects, ...enhancements[i].effects];
        }
    }

    return effects;
};

/** The point cost of an allocation: the node cost plus every enhancement rank bought. */
export const talentCost = (node, allocation) => {
    let cost = node.cost;
    const enhancements = node.config?.enhancements ?? [];
    for (let i = 0; i < (allocation?.rank ?? 0); i++) {
        cost += Number(enhancements[i]?.cost ?? 0);
    }
    return cost;
};

/**
 * The compendium spells/feats/abilities a node grants: its grant effects (resolved through the
 * id→entry lookup) plus any single linked entry. When an allocation is given, the chosen variant's and
 * bought ranks' grant effects are included too. Deduped by id.
 */
export const grantItemsFor = (node, compendiumById = {}, allocation = undefined) => {
    const items = [];
    const seen = new Set();
    const push = (item) => {
        if (item && !seen.has(item.id)) {
            seen.add(item.id);
            items.push(item);
        }
    };
    const effects = allocation ? effectsFor(node, allocation) : node?.effects ?? [];
    for (const effect of effects) {
        if (effect && (effect.type === 'spell' || effect.type === 'feat' || effect.type === 'ability') && effect.item_id != undefined) {
            push(compendiumById[effect.item_id]);
        }
    }
    push(node?.compendium_item);
    return items;
};

/** A spell's mana-costed ranks, coerced to numbers and sorted low→high. Empty for a flat (non-leveled) spell. */
const spellRanks = (item) => {
    const raw = item?.fields?.levels;
    if (!Array.isArray(raw) || !raw.length) {
        return [];
    }
    return raw
        .map((rank) => ({ ...rank, level: Number(rank.level ?? 0), mana: Number(rank.mana ?? 0), min_level: Number(rank.min_level ?? 0) }))
        .filter((rank) => rank.level > 0)
        .sort((a, b) => a.level - b.level);
};

const rankMinLevel = (ranks, level) => ranks.find((rank) => rank.level === level)?.min_level ?? 0;

/**
 * For each learned leveled spell, the ranks the character can cast. A spell is learned by a grant (its
 * lowest rank); a `level_spell` effect raises its cap toward a target rank, but only as far as the
 * character's level clears each rank's `min_level`. Every rank is returned with an `available` flag
 * (learned, i.e. level ≤ cap and character level ≥ its min_level) so the sheet can show locked ranks too.
 * Flat spells (no ranks) are skipped — they render as ordinary cards.
 *
 * @returns {Array<{item: object, cap: number, levels: Array<object>}>}
 */
export const learnedSpellsFor = (grantedItems, allocations, characterLevel = 1) => {
    const learned = new Map();
    for (const item of grantedItems ?? []) {
        if (item?.item_type !== 'spell' || learned.has(item.id)) {
            continue;
        }
        const ranks = spellRanks(item);
        if (ranks.length) {
            learned.set(item.id, { item, ranks, cap: ranks[0].level });
        }
    }

    for (const allocation of allocations ?? []) {
        if (!allocation?.node) {
            continue;
        }
        for (const effect of effectsFor(allocation.node, allocation)) {
            if (effect?.type !== 'level_spell' || effect.item_id == undefined) {
                continue;
            }
            const entry = learned.get(effect.item_id);
            if (!entry) {
                continue; // you can only raise a spell you've learned
            }
            const target = Number(effect.to_level ?? 0) || entry.ranks[entry.ranks.length - 1].level;
            let cap = target;
            while (cap > entry.cap && characterLevel < rankMinLevel(entry.ranks, cap)) {
                cap -= 1;
            }
            if (cap > entry.cap) {
                entry.cap = cap;
            }
        }
    }

    return [...learned.values()].map(({ item, ranks, cap }) => ({
        item,
        cap,
        levels: ranks.map((rank) => ({ ...rank, available: rank.level <= cap && characterLevel >= (rank.min_level ?? 0) })),
    }));
};

/** The level a build effectively plays at, mirroring the server. */
export const effectiveLevel = (system, character, build) => {
    if (system.settings?.progression_mode === 'xp') {
        const xp = build?.xp ?? 0;
        let level = 1;
        for (const row of system.levels ?? []) {
            if (row.xp_required != undefined && xp >= row.xp_required && row.level > level) {
                level = row.level;
            }
        }
        return level;
    }
    return Math.max(1, build?.level_override ?? character?.level ?? 1);
};

/** The build's point budget: { total, spent, remaining }. */
export const computePoints = (system, character, build, allocations) => {
    const manual = build?.manual_points ?? 0;
    const starting = system.settings?.starting_points ?? 0;
    let total = starting + manual;

    if (system.settings?.progression_mode !== 'manual') {
        const level = effectiveLevel(system, character, build);
        const cumulative = system.settings?.points_cumulative ?? true;
        for (const row of system.levels ?? []) {
            if (cumulative ? row.level <= level : row.level === level) {
                total += row.talent_points;
            }
        }
    }

    let spent = 0;
    for (const allocation of allocations) {
        const node = allocation.node;
        if (node) {
            spent += talentCost(node, allocation);
        }
    }

    return { total, spent, remaining: total - spent };
};

/**
 * Compute the full sheet (stats/resources/skills/derived) from the allocations. `extraEffects` are
 * applied first — a chosen race's base modifiers, which mirror the server's CharacterTotals.
 */
export const computeSheet = (system, allocations, extraEffects = []) => {
    const stats = {};
    for (const stat of system.stats ?? []) {
        stats[stat.key] = stat.default_value;
    }
    const resources = {};
    const pct = {};
    for (const resource of system.resources ?? []) {
        resources[resource.key] = resource.base_value;
        pct[resource.key] = 0;
    }
    const skills = {};
    for (const skill of system.skills ?? []) {
        skills[skill.key] = { value: 0, proficient: false };
    }
    const derived = {};

    const apply = (effect) => {
        if (!effect || !effect.type || !effect.key) {
            return;
        }
        const delta = Number(effect.delta ?? 0);
        if (effect.type === 'stat' && effect.key in stats) {
            stats[effect.key] += delta;
        } else if (effect.type === 'resource' && effect.key in resources) {
            resources[effect.key] += delta;
            if (effect.pct != undefined) {
                pct[effect.key] += Number(effect.pct);
            }
        } else if (effect.type === 'derived') {
            derived[effect.key] = (derived[effect.key] ?? 0) + delta;
        } else if (effect.type === 'skill' && effect.key in skills) {
            skills[effect.key].value += delta;
            if (effect.proficiency) {
                skills[effect.key].proficient = true;
            }
        }
    };

    for (const effect of extraEffects ?? []) {
        apply(effect);
    }
    for (const allocation of allocations) {
        if (!allocation.node) {
            continue;
        }
        for (const effect of effectsFor(allocation.node, allocation)) {
            apply(effect);
        }
    }

    for (const key of Object.keys(pct)) {
        if (pct[key] !== 0) {
            resources[key] = Math.round(resources[key] * (1 + pct[key]));
        }
    }

    return { stats, resources, skills, derived };
};

// --- node requirements: a nested AND/OR tree, mirroring TalentAllocator's recursive evaluation ---

// Normalise any requirements value into a group, or undefined when there is nothing to check.
const asRequirementGroup = (requires) => {
    if (!requires || typeof requires !== 'object') {
        return undefined;
    }
    if (Array.isArray(requires)) {
        return requires.length ? { op: 'all', children: requires } : undefined;
    }
    if (requires.op && Array.isArray(requires.children)) {
        return { op: requires.op === 'any' ? 'any' : 'all', children: requires.children };
    }
    return { op: 'all', children: [requires] };
};

const requirementNodeMatches = (node, requirement) => {
    if (requirement.kind && node.kind !== requirement.kind) {
        return false;
    }
    if (requirement.min_ring != undefined && (node.ring ?? 0) < Number(requirement.min_ring)) {
        return false;
    }
    // The optional `web` condition can't be evaluated client-side (merged nodes drop their web); the
    // server still enforces it.
    return true;
};

const requirementLeafMet = (requirement, ctx) => {
    if (requirement?.type === 'stat') {
        return (ctx.stats?.[requirement.key] ?? 0) >= Number(requirement.min ?? 0);
    }
    if (requirement?.type === 'grant') {
        return ctx.grantedIds?.has(requirement.item_id) ?? false;
    }
    const needed = Math.max(1, Number(requirement?.count ?? 1));
    const have = (ctx.allocatedNodes ?? []).filter((node) => requirementNodeMatches(node, requirement)).length;
    return have >= needed;
};

const requirementGroupMet = (group, ctx) => {
    const results = [];
    for (const child of group.children) {
        if (!child || typeof child !== 'object') {
            continue;
        }
        results.push(
            child.op && Array.isArray(child.children) ? requirementGroupMet(asRequirementGroup(child), ctx) : requirementLeafMet(child, ctx),
        );
    }
    if (!results.length) {
        return true;
    }
    return group.op === 'any' ? results.includes(true) : !results.includes(false);
};

/** Whether a node's requirements (config.requires) are satisfied by the current build. */
export const requirementsMet = (requires, ctx) => {
    const group = asRequirementGroup(requires);
    return !group || requirementGroupMet(group, ctx);
};

/**
 * Problems with a set of effect-tree choices, mirroring the server: 'choose' when an ANY group on the
 * chosen path has no pick, 'requirement' when a chosen node's prerequisite isn't met. Empty = ready.
 */
export const treeProblems = (tree, choices, ctx) => {
    const problems = [];
    const walk = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        if (node.requires && !requirementsMet(node.requires, ctx)) {
            problems.push('requirement');
            return;
        }
        if (Array.isArray(node.children)) {
            if (node.op === 'any') {
                const chosen = node.children.find((child) => child && child.id === choices?.[node.id]);
                if (!chosen) {
                    problems.push('choose');
                    return;
                }
                walk(chosen);
            } else {
                node.children.forEach(walk);
            }
        }
    };
    walk(tree);
    return problems;
};

const requirementLeafLabel = (requirement, system, ctx = {}) => {
    if (requirement?.type === 'stat') {
        const label = (system.stats ?? []).find((stat) => stat.key === requirement.key)?.label ?? requirement.key;
        return `${label} ≥ ${requirement.min ?? 0}`;
    }
    if (requirement?.type === 'grant') {
        return `have ${ctx.compendiumById?.[requirement.item_id]?.name ?? 'a specific entry'}`;
    }
    const kindLabel = requirement.kind
        ? (system.nodeKinds ?? []).find((kind) => kind.key === requirement.kind)?.label ?? requirement.kind
        : 'node';
    const count = Math.max(1, Number(requirement?.count ?? 1));
    const ring = requirement.min_ring != undefined ? ` (ring ${requirement.min_ring}+)` : '';
    return `${count}× ${kindLabel}${count === 1 ? '' : 's'}${ring}`;
};

/**
 * A renderable tree of a node's requirements with each leaf's met/unmet state, for the allocation
 * modal. Returns undefined when the node has no requirements.
 */
export const requirementTree = (requires, system, ctx) => {
    const build = (group) => ({
        op: group.op,
        children: group.children
            .filter((child) => child && typeof child === 'object')
            .map((child) =>
                child.op && Array.isArray(child.children)
                    ? build(asRequirementGroup(child))
                    : { leaf: true, text: requirementLeafLabel(child, system, ctx), met: requirementLeafMet(child, ctx) },
            ),
    });
    const group = asRequirementGroup(requires);
    return group ? build(group) : undefined;
};

/**
 * A flat list of rows for rendering a node's requirements: group headers ("All of:" / "Any of:") and
 * leaf rules with their met/unmet state, each with a depth for indentation. Empty when none.
 */
export const requirementRows = (requires, system, ctx) => {
    const tree = requirementTree(requires, system, ctx);
    if (!tree) {
        return [];
    }
    const rows = [];
    const walk = (group, depth) => {
        rows.push({ group: true, text: group.op === 'any' ? 'Any of:' : 'All of:', depth });
        for (const child of group.children) {
            if (child.leaf) {
                rows.push({ group: false, text: child.text, met: child.met, depth: depth + 1 });
            } else {
                walk(child, depth + 1);
            }
        }
    };
    walk(tree, 0);
    return rows;
};

/**
 * Why a node cannot be allocated right now, or undefined if it can. Mirrors TalentAllocator's checks.
 */
export const blockReason = (node, ctx) => {
    const { allocatedSet, adj, level, kindCounts, kinds, remaining, hasSoloByKind } = ctx;

    if (allocatedSet.has(node.id)) {
        return undefined;
    }
    if (!isRoot(node)) {
        const neighbours = adj[node.id] ?? [];
        if (!neighbours.some((id) => allocatedSet.has(id))) {
            return 'path';
        }
    }
    if (node.gate_level > level) {
        return 'level';
    }

    const cap = kinds[node.kind]?.max_per_character;
    const count = kindCounts[node.kind] ?? 0;
    if (cap != undefined && count >= cap) {
        return 'cap';
    }
    if (hasSoloByKind[node.kind]) {
        return 'solo';
    }
    if (node.config?.solo && count > 0) {
        return 'solo';
    }
    if (!requirementsMet(node.config?.requires, ctx)) {
        return 'requirements';
    }
    if (node.cost > remaining) {
        return 'poor';
    }
    return undefined;
};

/** The visual state of a node for the canvas. */
export const nodeState = (node, ctx) => {
    if (ctx.allocatedSet.has(node.id)) {
        return 'taken';
    }
    const reason = blockReason(node, ctx);
    if (!reason) {
        return 'open';
    }
    if (reason === 'level') {
        return 'gated';
    }
    if (reason === 'poor') {
        return 'poor';
    }
    return 'locked';
};

/**
 * Build the shared context nodeState/blockReason need, from the current allocations. `extraEffects` are
 * a chosen race's base modifiers; `options` carries the race's granted item ids and a compendium lookup
 * so "have this spell/feat/ability" requirements can be evaluated and labelled.
 */
export const buildContext = (system, character, build, allocations, web, extraEffects = [], options = {}) => {
    const allocatedSet = new Set(allocations.map((a) => a.node_id));
    const kindCounts = {};
    const hasSoloByKind = {};
    const allocatedNodes = [];
    const grantedIds = new Set(options.raceGrantIds ?? []);
    for (const allocation of allocations) {
        const node = allocation.node;
        if (!node) {
            continue;
        }
        allocatedNodes.push(node);
        kindCounts[node.kind] = (kindCounts[node.kind] ?? 0) + 1;
        if (node.config?.solo) {
            hasSoloByKind[node.kind] = true;
        }
        for (const effect of effectsFor(node, allocation)) {
            if (effect && (effect.type === 'spell' || effect.type === 'feat' || effect.type === 'ability') && effect.item_id != undefined) {
                grantedIds.add(effect.item_id);
            }
        }
    }

    return {
        allocatedSet,
        adj: adjacency(web),
        level: effectiveLevel(system, character, build),
        kindCounts,
        kinds: kindMap(system),
        remaining: computePoints(system, character, build, allocations).remaining,
        hasSoloByKind,
        allocatedNodes,
        stats: computeSheet(system, allocations, extraEffects).stats,
        grantedIds,
        compendiumById: options.compendiumById ?? {},
    };
};

/** Shortest chain of unallocated nodes needed to reach a node from what is already owned. */
export const pathTo = (node, adj, allocatedSet) => {
    if (allocatedSet.has(node.id)) {
        return [];
    }
    const seen = new Set([node.id]);
    const queue = [[node.id]];
    while (queue.length) {
        const path = queue.shift();
        const last = path[path.length - 1];
        if (allocatedSet.has(last)) {
            return path.slice(1, -1).reverse();
        }
        for (const neighbour of adj[last] ?? []) {
            if (!seen.has(neighbour)) {
                seen.add(neighbour);
                queue.push([...path, neighbour]);
            }
        }
    }
    return [];
};

/** CSS border-radius for a node shape. */
export const shapeRadius = (shape) => (shape === 'square' || shape === 'diamond' || shape === 'hexagon' ? '4px' : '50%');

/** Degrees a shape is rotated (diamonds); glyphs are counter-rotated by the negative of this. */
export const shapeSpin = (shape) => (shape === 'diamond' ? 45 : 0);

/** The accent colour for a node: its own override, else its tree/config colour, else the system gold. */
export const nodeAccent = (node) => node.config?.colour ?? '#e0a33e';

/** A hex colour at the given alpha, e.g. hexA('#d1483f', 0.5) → 'rgba(209,72,63,0.5)'. */
export const hexA = (hex, alpha) => {
    const value = (hex ?? '#e0a33e').replace('#', '');
    const full = value.length === 3 ? value.replace(/(.)/g, '$1$1') : value;
    const r = parseInt(full.slice(0, 2), 16);
    const g = parseInt(full.slice(2, 4), 16);
    const b = parseInt(full.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

/**
 * Visual style for a node given its state — background, border, glow — ported from the reference
 * design's palette (warm gradient fills, coloured borders, soft glows) rather than flat swatches.
 */
export const nodeVisual = (state, accent, isKeystone = false) => {
    switch (state) {
        case 'taken':
            return {
                bg: `radial-gradient(120% 120% at 50% 15%, ${hexA(accent, 0.9)}, #1b140d 84%)`,
                border: accent,
                glow: isKeystone
                    ? `0 0 30px ${hexA(accent, 0.65)}, 0 0 0 4px rgba(224,163,62,0.4)`
                    : `0 0 14px ${hexA(accent, 0.55)}, inset 0 0 10px ${hexA(accent, 0.4)}`,
                glyphColor: '#fff6e4',
                opacity: 1,
                borderWidth: 3,
            };
        case 'poor':
            return { bg: 'linear-gradient(180deg, #3a2611, #221709)', border: '#e0954f', glow: '0 0 10px rgba(224,149,79,0.35)', glyphColor: '#f0a55e', opacity: 1, borderWidth: 3 };
        case 'gated':
            return { bg: 'linear-gradient(180deg, #2c2a34, #1f1d26)', border: '#6f6a86', glow: 'inset 0 1px 0 rgba(220,215,255,0.07)', glyphColor: '#a09cba', opacity: 0.95, borderWidth: 2 };
        case 'locked':
            return { bg: 'linear-gradient(180deg, #332a1f, #241c14)', border: '#6b5d48', glow: 'inset 0 1px 0 rgba(255,235,195,0.07)', glyphColor: '#9a8b74', opacity: 0.9, borderWidth: 2 };
        default: // open / builder
            return {
                bg: 'linear-gradient(180deg, #2a2117, #191309)',
                border: hexA(accent, 0.85),
                glow: isKeystone ? `0 0 20px ${hexA(accent, 0.4)}` : `0 0 12px ${hexA(accent, 0.3)}`,
                glyphColor: accent,
                opacity: 1,
                borderWidth: 3,
            };
    }
};
