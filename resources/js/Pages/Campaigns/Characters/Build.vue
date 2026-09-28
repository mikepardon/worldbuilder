<script setup>
import EffectChoices from '@/Components/TalentWeb/EffectChoices.vue';
import TalentSheetTabs from '@/Components/TalentWeb/TalentSheetTabs.vue';
import WebCanvas from '@/Components/TalentWeb/WebCanvas.vue';
import { buildContext, computePoints, computeSheet, grantItemsFor, learnedSpellsFor, nodeState, requirementRows, talentCost, treeProblems } from '@/lib/talentWeb';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    system: { type: Object, default: null },
    build: { type: Object, default: () => ({ talents: [] }) },
    sheet: { type: Object, default: null },
    character: { type: Object, required: true },
    // The chosen race: { id, name, effects, grants } — its base modifiers fold into the sheet and its
    // grants show in the tabs. Null when no race is set.
    race: { type: Object, default: null },
    // Spells/feats/abilities the system's nodes can grant, so the tabs can resolve them.
    compendium: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    backHref: { type: String, required: true },
});

const compendiumById = computed(() => {
    const map = {};
    for (const item of props.compendium) {
        map[item.id] = item;
    }
    return map;
});

const page = usePage();
const talentError = computed(() => page.props.errors?.talent);

const nodeIndex = computed(() => {
    const map = {};
    for (const web of props.system?.webs ?? []) {
        for (const node of web.nodes) {
            map[node.id] = node;
        }
    }
    return map;
});

const allocations = computed(() =>
    (props.build?.talents ?? [])
        .map((talent) => ({ ...talent, node: nodeIndex.value[talent.node_id] }))
        .filter((allocation) => allocation.node),
);

const mergedWeb = computed(() => ({
    nodes: Object.values(nodeIndex.value),
    edges: (props.system?.webs ?? []).flatMap((web) => web.edges),
}));

const context = computed(() =>
    props.system
        ? buildContext(props.system, props.character, props.build, allocations.value, mergedWeb.value, props.race?.effects ?? [], {
              raceGrantIds: (props.race?.grants ?? []).map((item) => item.id),
              compendiumById: compendiumById.value,
          })
        : null,
);

const states = computed(() => {
    const result = {};
    if (!context.value) {
        return result;
    }
    for (const node of mergedWeb.value.nodes) {
        result[node.id] = nodeState(node, context.value);
    }
    return result;
});

const points = computed(() =>
    props.system ? computePoints(props.system, props.character, props.build, allocations.value) : { total: 0, spent: 0, remaining: 0 },
);

const liveSheet = computed(() =>
    props.system ? computeSheet(props.system, allocations.value, props.race?.effects ?? []) : null,
);

// Spells/feats/abilities the character has: the race's grants plus those the allocated talents grant,
// resolved from their grant effects (deduped by id).
const grants = computed(() => {
    const map = {};
    for (const item of props.race?.grants ?? []) {
        map[item.id] = item;
    }
    for (const allocation of allocations.value) {
        for (const item of grantItemsFor(allocation.node, compendiumById.value, allocation)) {
            map[item.id] = item;
        }
    }
    return Object.values(map);
});

// The character level and the leveled spells they've learned, so the sheet can show each castable rank's mana.
const characterLevel = computed(() => context.value?.level ?? 1);
const spellLevels = computed(() => learnedSpellsFor(grants.value, allocations.value, characterLevel.value));

// The selected node's requirements as rows for the modal, evaluated against the current build.
const selectedRequirementRows = computed(() =>
    selectedNode.value && context.value ? requirementRows(selectedNode.value.config?.requires, props.system, context.value) : [],
);

const activeWebId = ref(props.system?.webs?.[0]?.id ?? null);
const activeWeb = computed(() => (props.system?.webs ?? []).find((web) => web.id === activeWebId.value) ?? props.system?.webs?.[0]);

const sheetOpen = ref(true);
const selectedId = ref(null);
const pickedOption = ref(null);
const selectedNode = computed(() => (selectedId.value ? nodeIndex.value[selectedId.value] : null));
const selectedAllocation = computed(() => allocations.value.find((a) => a.node_id === selectedId.value));

// Nested-effect nodes: the player's chosen path (a copy so nothing persists until they act).
const treeChoices = ref({});
const nodeTree = computed(() => selectedNode.value?.config?.effect_tree ?? null);
const openNode = (node) => {
    selectedId.value = node.id;
    pickedOption.value = selectedAllocation.value?.chosen_option ?? null;
    treeChoices.value = { ...(selectedAllocation.value?.choices ?? {}) };
};
const closeNode = () => {
    selectedId.value = null;
    pickedOption.value = null;
    treeChoices.value = {};
};
// Complete choices with every prerequisite met, so the node can be taken (or is already valid).
const treeReady = computed(() => !nodeTree.value || (context.value && treeProblems(nodeTree.value, treeChoices.value, context.value).length === 0));
// Persist a changed choice on an already-allocated node.
const onTreeChange = () => {
    if (selectedAllocation.value && treeReady.value) {
        post('character-build.choices', { node_id: selectedNode.value.id, choices: treeChoices.value });
    }
};

const statLabel = (key) => props.system?.stats.find((s) => s.key === key)?.label ?? key;
const resourceLabel = (key) => props.system?.resources.find((r) => r.key === key)?.label ?? key;
const skillLabel = (key) => props.system?.skills.find((s) => s.key === key)?.label ?? key;

const describeEffect = (effect) => {
    const sign = (effect.delta ?? 0) >= 0 ? '+' : '';
    if (effect.type === 'stat') {
        return `${sign}${effect.delta} ${statLabel(effect.key)}`;
    }
    if (effect.type === 'resource') {
        const pct = effect.pct != undefined ? ` ${effect.pct > 0 ? '+' : ''}${Math.round(effect.pct * 100)}%` : '';
        return `${effect.delta ? `${sign}${effect.delta} ` : ''}${resourceLabel(effect.key)}${pct}`;
    }
    if (effect.type === 'skill') {
        return effect.proficiency ? `${skillLabel(effect.key)} proficiency` : `${sign}${effect.delta} ${skillLabel(effect.key)}`;
    }
    return `${sign}${effect.delta} ${effect.key}`;
};

const kindLabel = (kind) => props.system?.nodeKinds.find((k) => k.key === kind)?.label ?? kind;
const stateLabel = (state) =>
    ({ taken: 'Allocated', open: 'Available', poor: 'Not enough points', gated: 'Locked by level', locked: 'No connected path' })[state] ??
    'Unavailable';

const post = (name, extra = {}, onDone) =>
    router.post(route(name, props.character.id), extra, { preserveScroll: true, preserveState: true, onSuccess: onDone });

const allocate = () => {
    if (!selectedNode.value) {
        return;
    }
    post('character-build.allocate', { node_id: selectedNode.value.id, option: pickedOption.value, choices: treeChoices.value }, closeNode);
};
const deallocate = () => post('character-build.deallocate', { node_id: selectedNode.value.id }, closeNode);
const enhance = () => post('character-build.enhance', { node_id: selectedNode.value.id });
const chooseOption = (key) => {
    pickedOption.value = key;
    if (selectedAllocation.value) {
        post('character-build.option', { node_id: selectedNode.value.id, option: key });
    }
};
const respec = () => {
    if (confirm('Refund every talent and start this build over?')) {
        post('character-build.respec');
    }
};

const modalState = computed(() => (selectedNode.value ? states.value[selectedNode.value.id] : null));
const canAllocate = computed(
    () => modalState.value === 'open' && (!selectedNode.value?.options?.length || pickedOption.value) && treeReady.value,
);
const enhancements = computed(() => selectedNode.value?.config?.enhancements ?? []);
</script>

<template>
    <Head :title="`${character.name} · Talents`" />

    <div class="fixed inset-0 flex flex-col bg-night text-ink">
        <!-- Empty state -->
        <div v-if="!system" class="flex flex-1 items-center justify-center p-6">
            <div class="panel max-w-md p-8 text-center">
                <p class="eyebrow-muted">Talent web</p>
                <h1 class="mt-2 font-display text-2xl text-bright">No system enabled</h1>
                <p class="mt-3 text-sm text-muted">
                    This campaign doesn’t have a rule system turned on yet. Its GM can enable one from the world’s rule systems.
                </p>
                <Link :href="backHref" class="btn-ghost mt-6">Back to campaign</Link>
            </div>
        </div>

        <template v-else>
            <!-- Top bar -->
            <header class="flex items-center gap-3 border-b border-edge bg-surface px-4 py-2">
                <Link :href="backHref" class="btn-ghost !px-3 !py-1.5 text-xs">← Back</Link>
                <div class="min-w-0">
                    <div class="truncate font-display text-lg text-bright">{{ character.name }}</div>
                    <div class="truncate text-xs text-faint">Level {{ character.level ?? 1 }} · {{ system.name }}</div>
                </div>
                <div class="ml-auto flex items-center gap-3">
                    <div class="rounded-md border border-amber/40 bg-amber/10 px-3 py-1 text-center">
                        <div class="font-mono text-[9px] uppercase tracking-widest text-muted">Points</div>
                        <div class="font-display text-xl leading-none text-amber">{{ points.remaining }}</div>
                    </div>
                    <button class="btn-ghost !px-3 !py-1.5 text-xs" @click="sheetOpen = !sheetOpen">Sheet</button>
                    <button class="btn-ghost !px-3 !py-1.5 text-xs" @click="respec">Respec</button>
                </div>
            </header>

            <!-- Web tabs -->
            <div v-if="system.webs.length > 1" class="flex gap-1 border-b border-edge bg-surface px-4 py-1">
                <button
                    v-for="web in system.webs"
                    :key="web.id"
                    class="rounded px-3 py-1 text-xs"
                    :class="web.id === activeWebId ? 'bg-amber/15 text-amber' : 'text-faint hover:text-ink'"
                    @click="activeWebId = web.id"
                >
                    {{ web.name }}
                </button>
            </div>

            <div class="relative flex-1 overflow-hidden">
                <WebCanvas
                    v-if="activeWeb"
                    :key="activeWeb.id"
                    :web="activeWeb"
                    :system="system"
                    :states="states"
                    :selected-id="selectedId"
                    @node-click="openNode"
                    @background-click="closeNode"
                />

                <!-- Character sheet -->
                <aside
                    v-if="sheetOpen && liveSheet"
                    class="panel absolute left-3 top-3 flex max-h-[calc(100%-1.5rem)] w-72 flex-col gap-3 overflow-auto p-4"
                >
                    <p v-if="race" class="font-mono text-[10px] uppercase tracking-widest text-faint">
                        Race · <span class="text-ink">{{ race.name }}</span>
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="stat in system.stats"
                            :key="stat.key"
                            class="rounded border border-edge2 bg-raised px-2 py-1"
                        >
                            <span class="font-mono text-[9px] uppercase tracking-wide text-faint">{{ stat.abbreviation || stat.label }}</span>
                            <span class="ml-1 font-display text-sm text-bright">{{ liveSheet.stats[stat.key] }}</span>
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="resource in system.resources"
                            :key="resource.key"
                            class="rounded border border-edge2 bg-raised px-2 py-1"
                        >
                            <span class="font-mono text-[9px] uppercase tracking-wide text-faint">{{ resource.label }}</span>
                            <span class="ml-1 font-display text-sm" :style="{ color: resource.colour || '#e8e3d9' }">
                                {{ liveSheet.resources[resource.key] }}
                            </span>
                        </span>
                    </div>
                    <div class="border-t border-edge pt-2">
                        <TalentSheetTabs :system="system" :skills="liveSheet.skills" :grants="grants" :spell-levels="spellLevels" :character-level="characterLevel" />
                    </div>
                    <p class="text-[11px] text-faint">{{ points.spent }} of {{ points.total }} points spent.</p>
                </aside>

                <!-- Error toast -->
                <div
                    v-if="talentError"
                    class="absolute bottom-6 left-1/2 -translate-x-1/2 rounded-md border border-blood/60 bg-blood/20 px-4 py-2 text-sm text-bright"
                >
                    {{ talentError }}
                </div>

                <!-- Node modal -->
                <div
                    v-if="selectedNode"
                    class="absolute inset-0 flex items-center justify-center bg-night/70 p-4"
                    @click.self="closeNode"
                >
                    <div class="panel w-full max-w-md overflow-auto p-5" style="max-height: 90%">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <h2 class="font-display text-xl text-bright">{{ selectedNode.name }}</h2>
                                <p class="mt-0.5 font-mono text-[10px] uppercase tracking-widest text-faint">
                                    {{ kindLabel(selectedNode.kind) }} · {{ selectedNode.cost }} pt
                                    <span v-if="selectedNode.gate_level > 1"> · level {{ selectedNode.gate_level }}</span>
                                </p>
                            </div>
                            <button class="text-faint hover:text-ink" @click="closeNode">✕</button>
                        </div>

                        <p v-if="selectedNode.description" class="mt-3 text-sm leading-relaxed text-muted">{{ selectedNode.description }}</p>

                        <ul v-if="selectedNode.effects?.length" class="mt-3 flex flex-wrap gap-1.5">
                            <li
                                v-for="(effect, index) in selectedNode.effects"
                                :key="index"
                                class="rounded bg-teal/10 px-2 py-0.5 text-xs text-teal"
                            >
                                {{ describeEffect(effect) }}
                            </li>
                        </ul>

                        <div v-if="selectedNode.compendium_item" class="mt-3 rounded border border-edge2 bg-raised p-2">
                            <p class="font-mono text-[9px] uppercase tracking-widest text-faint">{{ selectedNode.compendium_item.item_type }}</p>
                            <p class="text-sm text-ink">{{ selectedNode.compendium_item.name }}</p>
                            <p v-if="selectedNode.compendium_item.summary" class="mt-0.5 text-xs text-muted">{{ selectedNode.compendium_item.summary }}</p>
                        </div>

                        <div v-if="selectedRequirementRows.length" class="mt-3">
                            <p class="font-mono text-[10px] uppercase tracking-widest text-faint">Requirements</p>
                            <div
                                v-for="(row, index) in selectedRequirementRows"
                                :key="index"
                                class="text-xs"
                                :style="{ paddingLeft: `${row.depth * 12}px` }"
                            >
                                <span v-if="row.group" class="text-faint">{{ row.text }}</span>
                                <span v-else :class="row.met ? 'text-teal' : 'text-blood'">{{ row.met ? '✓' : '✗' }} {{ row.text }}</span>
                            </div>
                        </div>

                        <div v-if="selectedNode.config?.drawback" class="mt-3 rounded border border-blood/50 bg-blood/10 p-2">
                            <p class="font-mono text-[9px] uppercase tracking-widest text-blood">Drawback</p>
                            <p class="mt-0.5 text-sm text-ink">{{ selectedNode.config.drawback }}</p>
                        </div>

                        <!-- Options -->
                        <div v-if="selectedNode.options?.length" class="mt-4">
                            <p class="eyebrow-muted mb-1">Choose one</p>
                            <button
                                v-for="option in selectedNode.options"
                                :key="option.key"
                                class="mb-1.5 block w-full rounded border p-2 text-left text-sm"
                                :class="pickedOption === option.key ? 'border-amber bg-amber/10' : 'border-edge2 hover:border-edge3'"
                                @click="chooseOption(option.key)"
                            >
                                <span class="text-ink">{{ option.name }}</span>
                                <span v-if="option.desc" class="mt-0.5 block text-xs text-faint">{{ option.desc }}</span>
                            </button>
                        </div>

                        <!-- Nested effect choices -->
                        <div v-if="nodeTree" class="mt-4">
                            <EffectChoices
                                :node="nodeTree"
                                :choices="treeChoices"
                                :system="system"
                                :ctx="context"
                                :compendium-by-id="compendiumById"
                                :readonly="!(modalState === 'open' || selectedAllocation)"
                                @change="onTreeChange"
                            />
                        </div>

                        <!-- Enhancements (allocated only) -->
                        <div v-if="selectedAllocation && enhancements.length" class="mt-4">
                            <p class="eyebrow-muted mb-1">Enhancements</p>
                            <div
                                v-for="(enhancement, index) in enhancements"
                                :key="index"
                                class="mb-1.5 flex items-center justify-between rounded border border-edge2 p-2 text-sm"
                            >
                                <div class="min-w-0">
                                    <span :class="index < selectedAllocation.rank ? 'text-teal' : 'text-ink'">{{ enhancement.name }}</span>
                                    <span v-if="enhancement.desc" class="block text-xs text-faint">{{ enhancement.desc }}</span>
                                </div>
                                <span v-if="index < selectedAllocation.rank" class="font-mono text-[10px] uppercase text-teal">Taken</span>
                                <button
                                    v-else-if="index === selectedAllocation.rank"
                                    class="btn-ghost !px-2 !py-1 text-xs"
                                    @click="enhance"
                                >
                                    {{ enhancement.cost }} pt
                                </button>
                            </div>
                        </div>

                        <p class="mt-4 font-mono text-[10px] uppercase tracking-widest" :class="modalState === 'taken' ? 'text-teal' : 'text-faint'">
                            {{ stateLabel(modalState) }}
                        </p>

                        <div class="mt-3 flex gap-2">
                            <button v-if="selectedAllocation" class="btn-ghost flex-1" @click="deallocate">Remove</button>
                            <button v-else-if="canAllocate" class="btn-primary flex-1" @click="allocate">
                                Allocate — {{ selectedNode.cost }} pt
                            </button>
                            <button v-else class="btn-ghost flex-1 opacity-60" disabled>
                                {{ selectedNode.options?.length && !pickedOption ? 'Choose an option' : stateLabel(modalState) }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
