<script setup>
import EffectChoices from '@/Components/TalentWeb/EffectChoices.vue';
import TalentSheetTabs from '@/Components/TalentWeb/TalentSheetTabs.vue';
import WebCanvas from '@/Components/TalentWeb/WebCanvas.vue';
import { adjacency, buildContext, computePoints, computeSheet, grantItemsFor, learnedSpellsFor, nodeState, pathTo, requirementRows, treeProblems } from '@/lib/talentWeb';
import { computed, onMounted, ref } from 'vue';

// A throwaway sandbox: allocate this system's talents on a test character entirely in the browser,
// so a GM can feel out point costs, gating and stat totals without touching a real character.
const props = defineProps({
    system: { type: Object, required: true },
    // Spells/feats/abilities the system's nodes can grant, so the sheet tabs can resolve them.
    compendium: { type: Array, default: () => [] },
    // Races the test character can pick ({ id, name, effects, grants }).
    races: { type: Array, default: () => [] },
});

// Building a test character starts by choosing a race — its base modifiers land before any points.
const raceId = ref(null);
const race = computed(() => props.races.find((entry) => entry.id === raceId.value) ?? null);
const raceEffects = computed(() => race.value?.effects ?? []);

const compendiumById = computed(() => {
    const map = {};
    for (const item of props.compendium) {
        map[item.id] = item;
    }
    return map;
});
defineEmits(['close']);

const level = ref(props.system.settings?.level_cap ?? 20);
const manualPoints = ref(0);
const allocated = ref([]); // [{ node_id, chosen_option, rank }]

const nodeIndex = computed(() => {
    const map = {};
    for (const web of props.system.webs ?? []) {
        for (const node of web.nodes) {
            map[node.id] = node;
        }
    }
    return map;
});

const character = computed(() => ({ level: level.value }));
const build = computed(() => ({ manual_points: manualPoints.value, level_override: level.value, xp: null, talents: allocated.value }));
const allocations = computed(() => allocated.value.map((entry) => ({ ...entry, node: nodeIndex.value[entry.node_id] })).filter((entry) => entry.node));
const mergedWeb = computed(() => ({ nodes: Object.values(nodeIndex.value), edges: (props.system.webs ?? []).flatMap((web) => web.edges) }));
const context = computed(() =>
    buildContext(props.system, character.value, build.value, allocations.value, mergedWeb.value, raceEffects.value, {
        raceGrantIds: (race.value?.grants ?? []).map((item) => item.id),
        compendiumById: compendiumById.value,
    }),
);

const states = computed(() => {
    const result = {};
    for (const node of mergedWeb.value.nodes) {
        result[node.id] = nodeState(node, context.value);
    }
    return result;
});
const points = computed(() => computePoints(props.system, character.value, build.value, allocations.value));
const sheet = computed(() => computeSheet(props.system, allocations.value, raceEffects.value));

// Spells/feats/abilities the character has: the race's grants plus those the allocated talents grant.
const grants = computed(() => {
    const map = {};
    for (const item of race.value?.grants ?? []) {
        map[item.id] = item;
    }
    for (const allocation of allocations.value) {
        for (const item of grantItemsFor(allocation.node, compendiumById.value, allocation)) {
            map[item.id] = item;
        }
    }
    return Object.values(map);
});

// The character level the preview plays at (from the shared context), and the leveled spells they've learned.
const characterLevel = computed(() => context.value.level);
const spellLevels = computed(() => learnedSpellsFor(grants.value, allocations.value, characterLevel.value));

// The selected node's requirements as rows for the detail panel, evaluated against the current build.
const selectedRequirementRows = computed(() =>
    selected.value ? requirementRows(selected.value.config?.requires, props.system, context.value) : [],
);

const activeWebId = ref(props.system.webs?.[0]?.id ?? null);
const activeWeb = computed(() => (props.system.webs ?? []).find((web) => web.id === activeWebId.value));

const isRoot = (node) => Boolean(node.config?.origin);

const seedRoots = () => {
    for (const web of props.system.webs ?? []) {
        for (const node of web.nodes) {
            if (isRoot(node) && !allocated.value.some((entry) => entry.node_id === node.id)) {
                allocated.value.push({ node_id: node.id, chosen_option: undefined, rank: 0 });
            }
        }
    }
};
onMounted(seedRoots);

// Clicking a node opens a details panel so you can see what it does (and the stat change) before
// committing, matching the player's allocation flow.
const selected = ref(null);
const treeChoices = ref({});
const nodeTree = computed(() => selected.value?.config?.effect_tree ?? null);
const treeReady = computed(() => !nodeTree.value || treeProblems(nodeTree.value, treeChoices.value, context.value).length === 0);
const openNode = (node) => {
    selected.value = node;
    treeChoices.value = { ...(allocated.value.find((entry) => entry.node_id === node.id)?.choices ?? {}) };
};
const closeModal = () => {
    selected.value = null;
    treeChoices.value = {};
};

const isTaken = (id) => allocated.value.some((entry) => entry.node_id === id);
const selectedState = computed(() => (selected.value ? states.value[selected.value.id] : undefined));
const kindLabel = (kind) => props.system.nodeKinds.find((entry) => entry.key === kind)?.label ?? kind;

const statLabel = (key) => props.system.stats.find((stat) => stat.key === key)?.label ?? key;
const resourceLabel = (key) => props.system.resources.find((resource) => resource.key === key)?.label ?? key;
const skillLabel = (key) => props.system.skills.find((skill) => skill.key === key)?.label ?? key;
const describeEffect = (effect) => {
    const sign = (effect.delta ?? 0) >= 0 ? '+' : '';
    if (effect.type === 'stat') {
        return `${sign}${effect.delta} ${statLabel(effect.key)}`;
    }
    if (effect.type === 'resource') {
        return `${effect.delta ? `${sign}${effect.delta} ` : ''}${resourceLabel(effect.key)}`;
    }
    if (effect.type === 'skill') {
        return effect.proficiency ? `${skillLabel(effect.key)} proficiency` : `${sign}${effect.delta} ${skillLabel(effect.key)}`;
    }
    return `${sign}${effect.delta} ${effect.key}`;
};

// The before → after change to stats and resources if the selected node is (de)allocated.
const diff = computed(() => {
    if (!selected.value) {
        return [];
    }
    const taken = isTaken(selected.value.id);
    const after = taken
        ? allocations.value.filter((entry) => entry.node_id !== selected.value.id)
        : [...allocations.value, { node_id: selected.value.id, chosen_option: selected.value.options?.[0]?.key, choices: treeChoices.value, rank: 0, node: selected.value }];
    const now = sheet.value;
    const next = computeSheet(props.system, after, raceEffects.value);
    const rows = [];
    for (const stat of props.system.stats) {
        if (next.stats[stat.key] !== now.stats[stat.key]) {
            rows.push({ label: stat.label, from: now.stats[stat.key], to: next.stats[stat.key] });
        }
    }
    for (const resource of props.system.resources) {
        if (next.resources[resource.key] !== now.resources[resource.key]) {
            rows.push({ label: resource.label, from: now.resources[resource.key], to: next.resources[resource.key] });
        }
    }
    return rows;
});

const adjacencyMap = computed(() => adjacency(mergedWeb.value));
const pathToSelected = computed(() => {
    if (!selected.value) {
        return [];
    }
    return pathTo(selected.value, adjacencyMap.value, new Set(allocated.value.map((entry) => entry.node_id)));
});
const pathCost = computed(() =>
    [...pathToSelected.value, selected.value.id].reduce((sum, id) => sum + (nodeIndex.value[id]?.cost ?? 0), 0),
);
const stateLabel = computed(
    () =>
        ({ taken: 'Allocated', open: 'Available', poor: 'Not enough points', gated: 'Locked by level', locked: 'No connected path' })[
            selectedState.value
        ] ?? 'Unavailable',
);

const allocate = () => {
    allocated.value.push({ node_id: selected.value.id, chosen_option: selected.value.options?.[0]?.key, choices: { ...treeChoices.value }, rank: 0 });
    closeModal();
};
// Live-update a chosen path on an already-allocated node in the sandbox.
const onTreeChange = () => {
    const entry = allocated.value.find((item) => item.node_id === selected.value.id);
    if (entry) {
        entry.choices = { ...treeChoices.value };
    }
};
const refund = () => {
    if (selected.value && !isRoot(selected.value)) {
        allocated.value = allocated.value.filter((entry) => entry.node_id !== selected.value.id);
    }
    closeModal();
};
const takePath = () => {
    for (const id of pathToSelected.value) {
        if (!isTaken(id)) {
            allocated.value.push({ node_id: id, rank: 0 });
        }
    }
    allocate();
};

const respec = () => {
    allocated.value = [];
    seedRoots();
};
</script>

<template>
    <div class="fixed inset-0 z-[60] flex flex-col bg-night text-ink">
        <header class="flex items-center gap-3 border-b border-edge bg-surface px-4 py-2">
            <div>
                <p class="eyebrow-muted">Preview · test character</p>
                <h2 class="font-display text-lg text-bright">{{ system.name }}</h2>
            </div>
            <label v-if="races.length" class="ml-4 flex items-center gap-2 text-xs text-muted">
                Race
                <select v-model="raceId" class="field !w-40 !py-1">
                    <option :value="null">— none —</option>
                    <option v-for="entry in races" :key="entry.id" :value="entry.id">{{ entry.name }}</option>
                </select>
            </label>
            <label class="ml-4 flex items-center gap-2 text-xs text-muted">
                Level
                <input v-model.number="level" type="number" min="1" class="field !w-20 !py-1" />
            </label>
            <label class="flex items-center gap-2 text-xs text-muted">
                Bonus points
                <input v-model.number="manualPoints" type="number" min="0" class="field !w-20 !py-1" />
            </label>
            <div class="ml-auto flex items-center gap-3">
                <div class="rounded-md border border-amber/40 bg-amber/10 px-3 py-1 text-center">
                    <div class="font-mono text-[9px] uppercase tracking-widest text-muted">Points</div>
                    <div class="font-display text-xl leading-none text-amber">{{ points.remaining }}</div>
                </div>
                <button class="btn-ghost !px-3 !py-1.5 text-xs" @click="respec">Respec</button>
                <button class="btn-primary !px-3 !py-1.5 text-xs" @click="$emit('close')">Close preview</button>
            </div>
        </header>

        <div class="relative flex-1 overflow-hidden">
            <WebCanvas
                v-if="activeWeb"
                :key="activeWeb.id"
                :web="activeWeb"
                :system="system"
                :states="states"
                :selected-id="selected?.id ?? null"
                @node-click="openNode"
            />

            <aside class="panel absolute left-3 top-3 flex max-h-[calc(100%-1.5rem)] w-72 flex-col gap-3 overflow-auto p-4">
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="stat in system.stats" :key="stat.key" class="rounded border border-edge2 bg-raised px-2 py-1">
                        <span class="font-mono text-[9px] uppercase tracking-wide text-faint">{{ stat.abbreviation || stat.label }}</span>
                        <span class="ml-1 font-display text-sm text-bright">{{ sheet.stats[stat.key] }}</span>
                    </span>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="resource in system.resources" :key="resource.key" class="rounded border border-edge2 bg-raised px-2 py-1">
                        <span class="font-mono text-[9px] uppercase tracking-wide text-faint">{{ resource.label }}</span>
                        <span class="ml-1 font-display text-sm" :style="{ color: resource.colour || '#e8e3d9' }">{{ sheet.resources[resource.key] }}</span>
                    </span>
                </div>
                <div class="border-t border-edge pt-2">
                    <TalentSheetTabs :system="system" :skills="sheet.skills" :grants="grants" :spell-levels="spellLevels" :character-level="characterLevel" />
                </div>
                <p class="text-[11px] text-faint">{{ points.spent }} of {{ points.total }} points spent. Click a node to inspect it.</p>
            </aside>

            <!-- Node detail: see what it does (and the change) before committing -->
            <div v-if="selected" class="absolute inset-0 flex items-center justify-center bg-night/70 p-4" @click.self="closeModal">
                <div class="panel w-full max-w-md overflow-auto p-5" style="max-height: 90%">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-display text-xl text-bright">{{ selected.name }}</h3>
                            <p class="mt-0.5 font-mono text-[10px] uppercase tracking-widest text-faint">
                                {{ kindLabel(selected.kind) }} · {{ selected.cost }} pt<span v-if="selected.gate_level > 1"> · level {{ selected.gate_level }}</span>
                            </p>
                        </div>
                        <button class="text-faint hover:text-ink" @click="closeModal">✕</button>
                    </div>

                    <p v-if="selected.description" class="mt-3 text-sm leading-relaxed text-muted">{{ selected.description }}</p>

                    <ul v-if="selected.effects?.length" class="mt-3 flex flex-wrap gap-1.5">
                        <li v-for="(effect, index) in selected.effects" :key="index" class="rounded bg-teal/10 px-2 py-0.5 text-xs text-teal">
                            {{ describeEffect(effect) }}
                        </li>
                    </ul>

                    <div v-if="selected.compendium_item" class="mt-3 rounded border border-edge2 bg-raised p-2">
                        <p class="font-mono text-[9px] uppercase tracking-widest text-faint">{{ selected.compendium_item.item_type }}</p>
                        <p class="text-sm text-ink">{{ selected.compendium_item.name }}</p>
                        <p v-if="selected.compendium_item.summary" class="mt-0.5 text-xs text-muted">{{ selected.compendium_item.summary }}</p>
                    </div>

                    <div v-if="selectedRequirementRows.length" class="mt-3">
                        <p class="font-mono text-[10px] uppercase tracking-widest text-faint">Requirements</p>
                        <div v-for="(row, index) in selectedRequirementRows" :key="index" class="text-xs" :style="{ paddingLeft: `${row.depth * 12}px` }">
                            <span v-if="row.group" class="text-faint">{{ row.text }}</span>
                            <span v-else :class="row.met ? 'text-teal' : 'text-blood'">{{ row.met ? '✓' : '✗' }} {{ row.text }}</span>
                        </div>
                    </div>

                    <div v-if="nodeTree" class="mt-3">
                        <EffectChoices
                            :node="nodeTree"
                            :choices="treeChoices"
                            :system="system"
                            :ctx="context"
                            :compendium-by-id="compendiumById"
                            :readonly="selectedState !== 'open' && selectedState !== 'taken'"
                            @change="onTreeChange"
                        />
                    </div>

                    <div v-if="diff.length" class="mt-4 rounded border border-edge2">
                        <p class="border-b border-edge2 px-3 py-1.5 font-mono text-[10px] uppercase tracking-widest text-faint">
                            {{ selectedState === 'taken' ? 'Currently granting' : 'If you allocate' }}
                        </p>
                        <div v-for="(row, index) in diff" :key="index" class="flex items-center gap-2 px-3 py-1.5 text-sm">
                            <span class="flex-1 text-muted">{{ row.label }}</span>
                            <span class="text-faint">{{ row.from }}</span>
                            <span class="text-faint">→</span>
                            <span class="font-display" :class="row.to > row.from ? 'text-teal' : 'text-blood'">{{ row.to }}</span>
                        </div>
                    </div>

                    <p class="mt-3 font-mono text-[10px] uppercase tracking-widest" :class="selectedState === 'taken' ? 'text-teal' : 'text-faint'">
                        {{ stateLabel }}
                    </p>

                    <div class="mt-3 flex gap-2">
                        <button v-if="selectedState === 'taken'" class="btn-ghost flex-1" @click="refund">Refund</button>
                        <button v-else-if="selectedState === 'open'" class="btn-primary flex-1" :disabled="!treeReady" @click="allocate">Allocate — {{ selected.cost }} pt</button>
                        <button v-else class="btn-ghost flex-1 opacity-60" disabled>{{ stateLabel }}</button>
                    </div>

                    <button
                        v-if="selectedState === 'locked' && pathToSelected.length && pathCost <= points.remaining"
                        class="btn-ghost mt-2 w-full text-xs"
                        @click="takePath"
                    >
                        Or take the whole path — {{ pathToSelected.length + 1 }} nodes, {{ pathCost }} pt
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
