<script setup>
import EffectGroupEditor from '@/Components/TalentWeb/EffectGroupEditor.vue';
import MuseChat from '@/Components/TalentWeb/MuseChat.vue';
import RequirementEditor from '@/Components/TalentWeb/RequirementEditor.vue';
import TalentPreview from '@/Components/TalentWeb/TalentPreview.vue';
import WebCanvas from '@/Components/TalentWeb/WebCanvas.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';

const props = defineProps({
    system: { type: Object, required: true },
    context: { type: String, default: 'world' },
    backHref: { type: String, required: true },
    options: { type: Object, required: true },
    // Spells/feats/abilities a node can grant (world entries, or a template's prerequisite entries).
    compendium: { type: Array, default: () => [] },
    // Global library "compendiums" (sources) that can be marked as prerequisites of this system.
    compendiumSources: { type: Array, default: () => [] },
    // Races a preview character can pick ({ id, name, effects, grants }).
    races: { type: Array, default: () => [] },
});

const tab = ref('details');
const tabs = [
    { key: 'details', label: 'Details' },
    { key: 'stats', label: 'Stats' },
    { key: 'resources', label: 'Resources' },
    { key: 'skills', label: 'Skills' },
    { key: 'levels', label: 'Levels' },
    { key: 'kinds', label: 'Node kinds' },
    { key: 'compendium', label: 'Compendium' },
    { key: 'webs', label: 'Talent web' },
];

// One form holds the system meta and every list-editable table; a single Save reconciles them all.
const form = useForm({
    name: props.system.name,
    description: props.system.description ?? '',
    settings: { ...props.system.settings },
    stats: clone(props.system.stats),
    resources: clone(props.system.resources),
    skills: clone(props.system.skills),
    levels: clone(props.system.levels),
    nodeKinds: clone(props.system.nodeKinds),
});

function clone(list) {
    return (list ?? []).map((item) => ({ ...item }));
}

// Which node kind the + menu creates for each role; null means "auto" (fall back by size / source).
if (!form.settings.role_kinds) {
    form.settings.role_kinds = { child: null, adjacent: null, master: null };
}
// The library sources marked as prerequisites of this system.
if (!Array.isArray(form.settings.compendium_sources)) {
    form.settings.compendium_sources = [];
}

const isSourceSelected = (id) => form.settings.compendium_sources.includes(id);
const toggleSource = (id) => {
    form.settings.compendium_sources = isSourceSelected(id)
        ? form.settings.compendium_sources.filter((selected) => selected !== id)
        : [...form.settings.compendium_sources, id];
};
const importing = ref(false);
const importCompendium = () =>
    router.post(
        route('rule-systems.import-compendium', props.system.id),
        {},
        { preserveScroll: true, onStart: () => (importing.value = true), onFinish: () => (importing.value = false) },
    );

const saveSystem = () => form.put(route('rule-systems.update', props.system.id), { preserveScroll: true });

// Each list tab is a compact table; editing a row opens a focused modal. All edits live in `form`
// and persist together on "Save system". Schemas describe both the table columns and the modal fields.
const listSchemas = {
    stats: {
        rowsKey: 'stats',
        title: 'Stat',
        blurb: 'Core stats (Strength, Dexterity, …). Referenced by key from node effects.',
        newRow: () => ({ id: null, key: '', label: '', abbreviation: '', description: '', default_value: 0 }),
        columns: [
            { key: 'key', label: 'Key', class: 'w-32', mono: true },
            { key: 'label', label: 'Label' },
            { key: 'abbreviation', label: 'Abbr', class: 'w-20' },
            { key: 'default_value', label: 'Base', class: 'w-16' },
            { key: 'description', label: 'Description', truncate: true },
        ],
        fields: [
            { key: 'key', label: 'Key', type: 'text' },
            { key: 'label', label: 'Label', type: 'text' },
            { key: 'abbreviation', label: 'Abbreviation', type: 'text' },
            { key: 'default_value', label: 'Base value', type: 'number' },
            { key: 'description', label: 'Description', type: 'textarea' },
        ],
    },
    resources: {
        rowsKey: 'resources',
        title: 'Resource',
        blurb: 'Pools (Life, Mana, …). Node effects add flat or percentage bonuses.',
        newRow: () => ({ id: null, key: '', label: '', description: '', base_value: 0, colour: '' }),
        columns: [
            { key: 'key', label: 'Key', class: 'w-32', mono: true },
            { key: 'label', label: 'Label' },
            { key: 'base_value', label: 'Base', class: 'w-16' },
            { key: 'colour', label: 'Colour', class: 'w-24' },
            { key: 'description', label: 'Description', truncate: true },
        ],
        fields: [
            { key: 'key', label: 'Key', type: 'text' },
            { key: 'label', label: 'Label', type: 'text' },
            { key: 'base_value', label: 'Base value', type: 'number' },
            { key: 'colour', label: 'Colour (hex)', type: 'text' },
            { key: 'description', label: 'Description', type: 'textarea' },
        ],
    },
    skills: {
        rowsKey: 'skills',
        title: 'Skill',
        blurb: 'Skills (Athletics, Stealth, …), each governed by a stat.',
        newRow: () => ({ id: null, key: '', label: '', governing_stat_key: '', description: '' }),
        columns: [
            { key: 'key', label: 'Key', class: 'w-32', mono: true },
            { key: 'label', label: 'Label' },
            { key: 'governing_stat_key', label: 'Governing stat', class: 'w-32' },
            { key: 'description', label: 'Description', truncate: true },
        ],
        fields: [
            { key: 'key', label: 'Key', type: 'text' },
            { key: 'label', label: 'Label', type: 'text' },
            { key: 'governing_stat_key', label: 'Governing stat', type: 'select', optionsFrom: 'stats', allowNone: true },
            { key: 'description', label: 'Description', type: 'textarea' },
        ],
    },
    levels: {
        rowsKey: 'levels',
        title: 'Level',
        blurb: 'The progression table — XP is optional (leave blank for milestone play).',
        newRow: () => ({
            id: null,
            level: form.levels.length ? Math.max(...form.levels.map((l) => l.level)) + 1 : 1,
            xp_required: null,
            talent_points: 0,
            notes: '',
        }),
        columns: [
            { key: 'level', label: 'Level', class: 'w-16' },
            { key: 'xp_required', label: 'XP required', class: 'w-28' },
            { key: 'talent_points', label: 'Points', class: 'w-20' },
            { key: 'notes', label: 'Notes', truncate: true },
        ],
        fields: [
            { key: 'level', label: 'Level', type: 'number' },
            { key: 'xp_required', label: 'XP required (blank = milestone)', type: 'number' },
            { key: 'talent_points', label: 'Talent points', type: 'number' },
            { key: 'notes', label: 'Notes', type: 'text' },
        ],
    },
    kinds: {
        rowsKey: 'nodeKinds',
        title: 'Node kind',
        blurb: 'Node types (Minor, Notable, Keystone, …). Cap limits how many a character may hold.',
        newRow: () => ({ id: null, key: '', label: '', default_cost: 1, shape: 'circle', glyph: '', size: 34, colour: '', max_per_character: null }),
        columns: [
            { key: 'key', label: 'Key', class: 'w-28', mono: true },
            { key: 'label', label: 'Label' },
            { key: 'default_cost', label: 'Cost', class: 'w-16' },
            { key: 'shape', label: 'Shape', class: 'w-24' },
            { key: 'glyph', label: 'Glyph', class: 'w-16' },
            { key: 'size', label: 'Size', class: 'w-16' },
            { key: 'max_per_character', label: 'Cap', class: 'w-16' },
        ],
        fields: [
            { key: 'key', label: 'Key', type: 'text' },
            { key: 'label', label: 'Label', type: 'text' },
            { key: 'default_cost', label: 'Default cost', type: 'number' },
            { key: 'shape', label: 'Shape', type: 'select', optionsFrom: 'shapes' },
            { key: 'glyph', label: 'Glyph', type: 'text' },
            { key: 'size', label: 'Size (px)', type: 'number' },
            { key: 'max_per_character', label: 'Cap — max per character (blank = unlimited)', type: 'number' },
            { key: 'colour', label: 'Colour (hex)', type: 'text' },
        ],
    },
};

const editing = ref(null); // { list, index }
const activeSchema = computed(() => listSchemas[tab.value]);
const activeRows = computed(() => (activeSchema.value ? form[activeSchema.value.rowsKey] : []));
const editingSchema = computed(() => (editing.value ? listSchemas[editing.value.list] : null));
const editingRow = computed(() => (editing.value ? form[editingSchema.value.rowsKey][editing.value.index] : null));

const addRow = () => {
    const schema = activeSchema.value;
    form[schema.rowsKey].push(schema.newRow());
    editing.value = { list: tab.value, index: form[schema.rowsKey].length - 1 };
};
const editRow = (index) => (editing.value = { list: tab.value, index });
const deleteRow = (index) => form[activeSchema.value.rowsKey].splice(index, 1);
const closeRow = () => (editing.value = null);

const fieldOptions = (field) => {
    if (field.optionsFrom === 'stats') {
        return form.stats.map((stat) => ({ value: stat.key, label: stat.label || stat.key }));
    }
    if (field.optionsFrom === 'shapes') {
        return props.options.shapes;
    }
    return field.options ?? [];
};

const cellValue = (row, column) => {
    const value = row[column.key];
    return value === null || value === undefined || value === '' ? '—' : value;
};

// --- web / canvas ---
// A system can hold several webs in the database, but the builder presents just one — the primary web
// (first by sort). Everything works against that single web.
const activeWeb = computed(() => props.system.webs?.[0] ?? null);
const museOpen = ref(false);
const layoutMode = ref(false);
const previewOpen = ref(false);
const selectedNodeId = ref(null);
const selectedNode = computed(() => activeWeb.value?.nodes.find((node) => node.id === selectedNodeId.value));

// Create the single talent web when a system doesn't have one yet.
const newWeb = useForm({ name: 'Talent web' });
const createWeb = () => newWeb.post(route('talent-webs.store', props.system.id), { preserveScroll: true });

const centreOf = (web) => {
    const centre = web.layout?.centre;
    return centre ?? { x: 1300, y: 1300 };
};

const addNode = () => {
    if (!activeWeb.value || !props.system.nodeKinds.length) {
        alert('Add at least one node kind first.');
        return;
    }
    createStandalone();
};

// Build the editable effect tree for a node: its own tree if present, else migrated from the node's
// legacy flat effects (an ALL group) or choose-one options (an ANY group of ALL sub-groups).
const treeUid = () => Math.random().toString(36).slice(2, 8);
const effectLeaf = (effect) => ({ id: treeUid(), effect: { ...effect } });
const buildEffectTree = (node) => {
    const existing = node.config?.effect_tree;
    if (existing && typeof existing === 'object') {
        return JSON.parse(JSON.stringify(existing));
    }
    if (Array.isArray(node.options) && node.options.length) {
        return {
            id: treeUid(),
            op: 'any',
            children: node.options.map((option) => ({
                id: treeUid(),
                op: 'all',
                label: option.name || '',
                children: (Array.isArray(option.effects) && option.effects.length ? option.effects : [{ type: 'stat', key: '', delta: 1 }]).map(effectLeaf),
            })),
        };
    }
    const effects = Array.isArray(node.effects) ? node.effects : [];
    return { id: treeUid(), op: 'all', children: effects.length ? effects.map(effectLeaf) : [effectLeaf({ type: 'stat', key: '', delta: 1 })] };
};

let suppressSave = false;
const onNodeClick = (node) => {
    // In "link" mode a click connects instead of selecting.
    if (linkingFrom.value) {
        if (node.id !== linkingFrom.value) {
            connectNodes(linkingFrom.value, node.id);
        }
        linkingFrom.value = null;
        return;
    }
    // Flush a pending debounced save for the node we're leaving, so a quick edit isn't lost.
    if (saveTimer && nodeDraft.value) {
        clearTimeout(saveTimer);
        saveTimer = undefined;
        saveNode();
    }
    selectedNodeId.value = node.id;
    suppressSave = true;
    nodeDraft.value = { ...node, config: { ...node.config } };
    // Normalise a legacy flat-list requirement into the nested group the editor expects.
    if (Array.isArray(nodeDraft.value.config.requires)) {
        nodeDraft.value.config.requires = { op: 'all', children: nodeDraft.value.config.requires };
    }
    // Author benefits as a nested tree — built from the node's own tree, else migrated from its legacy
    // flat effects / choose-one options.
    nodeDraft.value.config.effect_tree = buildEffectTree(node);
    nextTick(() => (suppressSave = false));
};

// --- structured node linking (click a "Link" button, then click a target node) ---
const linkingFrom = ref(null);
const startLinking = () => (linkingFrom.value = selectedNodeId.value);
const connectNodes = (from, to) => {
    const existing = activeWeb.value.edges.find(
        (edge) =>
            (edge.from_node_id === from && edge.to_node_id === to) ||
            (edge.from_node_id === to && edge.to_node_id === from),
    );
    if (existing) {
        return;
    }
    router.post(
        route('talent-edges.store', activeWeb.value.id),
        { from_node_id: from, to_node_id: to },
        { preserveScroll: true, preserveState: true },
    );
};

// --- live editing: the canvas reflects the editor immediately, and saves are debounced ---
const nodeOverrides = ref({});
const layoutOverride = ref(null);
const displayWeb = computed(() => {
    const web = activeWeb.value;
    if (!web) {
        return web;
    }
    const hasNodeOverrides = Object.keys(nodeOverrides.value).length > 0;
    if (!hasNodeOverrides && !layoutOverride.value) {
        return web;
    }
    return {
        ...web,
        nodes: hasNodeOverrides ? web.nodes.map((node) => (nodeOverrides.value[node.id] ? { ...node, ...nodeOverrides.value[node.id] } : node)) : web.nodes,
        ...(layoutOverride.value ? { layout: layoutOverride.value } : {}),
    };
});

// The selected node's connections, so they can be unlinked from the editor (no freeform edge dragging).
const selectedEdges = computed(() => {
    const node = selectedNode.value;
    if (!node) {
        return [];
    }
    return activeWeb.value.edges
        .filter((edge) => edge.from_node_id === node.id || edge.to_node_id === node.id)
        .map((edge) => {
            const otherId = edge.from_node_id === node.id ? edge.to_node_id : edge.from_node_id;
            const other = activeWeb.value.nodes.find((candidate) => candidate.id === otherId);
            return { id: edge.id, name: other?.name ?? `#${otherId}`, otherId };
        });
});
const hoveredConnectionId = ref(null);
const removeEdge = (edgeId) => router.delete(route('talent-edges.destroy', edgeId), { preserveScroll: true, preserveState: true });

const nodeDraft = ref(null);

// Node requirements (a nested AND/OR group): show the editor once there's something to edit.
const hasRequires = computed(() => {
    const requires = nodeDraft.value?.config?.requires;
    return Boolean(requires && requires.op && Array.isArray(requires.children) && requires.children.length);
});
const initRequires = () => {
    const existing = nodeDraft.value.config.requires;
    nodeDraft.value.config.requires =
        Array.isArray(existing) && existing.length ? { op: 'all', children: existing } : { op: 'all', children: [{ type: 'nodes', count: 1, kind: '' }] };
};
const clearRequires = () => delete nodeDraft.value.config.requires;

// Editing a node reflects on the canvas immediately (via nodeOverrides) and saves on a short debounce.
let saveTimer;
watch(
    nodeDraft,
    () => {
        const draft = nodeDraft.value;
        if (!draft) {
            return;
        }
        nodeOverrides.value = {
            ...nodeOverrides.value,
            [draft.id]: {
                name: draft.name,
                kind: draft.kind,
                cost: draft.cost,
                gate_level: draft.gate_level,
                description: draft.description,
                effects: draft.effects,
                config: draft.config,
            },
        };
        if (suppressSave) {
            return;
        }
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveNode, 600);
    },
    { deep: true },
);

// Adding grows the web structurally from a source node — no freeform placement. `addTarget.source`
// is the node the new one branches from.
const addTarget = ref(null);
const openAddPicker = (source) => (addTarget.value = { source });
const closeAddPicker = () => (addTarget.value = null);

// The smallest kind reads as a "line" node; the largest as a "centre/master" node.
const kindBySize = (largest) => {
    const list = props.system.nodeKinds;
    if (!list.length) {
        return undefined;
    }
    return list.reduce((best, kind) => ((largest ? kind.size > best.size : kind.size < best.size) ? kind : best), list[0]);
};

// The kind to create for a role: the GM's mapping if set and valid, else a sensible default.
const kindByRole = (role) => {
    const mapped = form.settings.role_kinds?.[role];
    if (mapped) {
        const kind = props.system.nodeKinds.find((entry) => entry.key === mapped);
        if (kind) {
            return kind;
        }
    }
    if (role === 'master') {
        return kindBySize(true);
    }
    if (role === 'child') {
        return kindBySize(false);
    }
    return undefined; // adjacent → store() falls back to the source's own kind
};

const centre = () => centreOf(activeWeb.value);
const neighbourCount = (source) =>
    activeWeb.value.edges.filter((edge) => edge.from_node_id === source.id || edge.to_node_id === source.id).length;

// Unit vector pointing from the web centre out through the source (the direction a branch grows).
const outwardUnit = (source) => {
    let dx = source.x - centre().x;
    let dy = source.y - centre().y;
    const length = Math.hypot(dx, dy);
    if (length < 1) {
        return { x: 0, y: -1 };
    }
    return { x: dx / length, y: dy / length };
};

// role/parentId let the server lay the new node out as a branch child (fanned off its parent, following
// it when moved) or a draggable adjacent sibling. The x/y here is a provisional spot; for a child the
// server re-fans the whole set of siblings so they sit comfortably.
const store = (source, kind, x, y, role, parentId) =>
    router.post(
        route('talent-nodes.store', activeWeb.value.id),
        {
            name: 'New node',
            kind: kind?.key ?? source?.kind ?? props.system.nodeKinds[0]?.key,
            x: Math.round(x),
            y: Math.round(y),
            gate_level: source?.gate_level ?? 1,
            cost: kind?.default_cost ?? source?.cost ?? 1,
            config: { colour: source?.config?.colour ?? null },
            layout_role: role ?? 'master',
            ...(parentId ? { parent_node_id: parentId } : {}),
            ...(source ? { connect_to: source.id } : {}),
        },
        { preserveScroll: true },
    );

const chooseAction = (action) => {
    const source = addTarget.value?.source;
    closeAddPicker();
    if (!source) {
        return;
    }
    const direction = outwardUnit(source);
    if (action === 'child') {
        // A branch child hanging off the source: the server fans it (and its siblings) outward.
        store(source, kindBySize(false), source.x + direction.x * 120, source.y + direction.y * 120, 'child', source.id);
    } else if (action === 'adjacent') {
        // A sibling beside it, tangent to the branch (alternating sides). It shares the source's parent
        // so it follows the same hub, but stays individually draggable.
        const side = neighbourCount(source) % 2 === 0 ? 1 : -1;
        store(
            source,
            kindByRole('adjacent') ?? props.system.nodeKinds.find((kind) => kind.key === source.kind),
            source.x - direction.y * 96 * side,
            source.y + direction.x * 96 * side,
            'adjacent',
            source.parent_node_id ?? source.id,
        );
    } else if (action === 'master') {
        // A whole cluster further out: a centre node ringed by nodes, with its own disc.
        spawnCluster(source, source.x + direction.x * 210, source.y + direction.y * 210);
    }
};

// Create a cluster (master centre + ring + disc) in one request, matching the seeded clusters. With no
// source it seeds an empty web as the root; otherwise it connects to the source.
const spawnCluster = (source, cx, cy) => {
    const master = kindByRole('master') ?? kindBySize(true);
    const ring = kindByRole('adjacent') ?? kindBySize(false);
    if (!master || !ring) {
        alert('Add node kinds first, on the Node kinds tab.');
        return;
    }
    router.post(
        route('talent-nodes.cluster', activeWeb.value.id),
        {
            ...(source ? { connect_to: source.id } : { is_root: true }),
            x: Math.round(cx),
            y: Math.round(cy),
            master_kind: master.key,
            ring_kind: ring.key,
            ring_count: 6,
            gate_level: source?.gate_level ?? 1,
            colour: source?.config?.colour ?? null,
        },
        { preserveScroll: true },
    );
};

const webEmpty = computed(() => activeWeb.value && !activeWeb.value.nodes.length);
const createStandalone = () => spawnCluster(null, centre().x, centre().y);
const saveNode = () => {
    const draft = nodeDraft.value;
    router.put(
        route('talent-nodes.update', draft.id),
        {
            name: draft.name,
            kind: draft.kind,
            x: draft.x,
            y: draft.y,
            ring: draft.ring,
            gate_level: draft.gate_level,
            cost: draft.cost,
            description: draft.description,
            // Benefits now live in config.effect_tree; clear the legacy flat effects/options.
            effects: [],
            options: [],
            config: draft.config,
            compendium_item_id: draft.compendium_item_id ?? null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const cleared = { ...nodeOverrides.value };
                delete cleared[draft.id];
                nodeOverrides.value = cleared;
            },
            onError: (errors) => alert(Object.values(errors)[0] ?? 'Could not save the node.'),
        },
    );
};
const deleteNode = (node) => {
    if (confirm(`Delete “${node.name}”?`)) {
        router.delete(route('talent-nodes.destroy', node.id), { preserveScroll: true, onSuccess: () => (selectedNodeId.value = null) });
    }
};

// Layout mode: a drag on the canvas reports every moved node (the hub and everything that follows it).
// Reflect it immediately via overrides, then persist the batch; clear the overrides once saved.
const onNodeMove = ({ positions, layout }) => {
    if (!positions.length && !layout) {
        return;
    }
    const overrides = { ...nodeOverrides.value };
    for (const position of positions) {
        overrides[position.id] = { ...(overrides[position.id] ?? {}), x: position.x, y: position.y };
    }
    nodeOverrides.value = overrides;
    if (layout) {
        layoutOverride.value = layout;
    }

    router.put(
        route('talent-nodes.positions', activeWeb.value.id),
        { positions, ...(layout ? { layout } : {}) },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const cleared = { ...nodeOverrides.value };
                for (const position of positions) {
                    delete cleared[position.id];
                }
                nodeOverrides.value = cleared;
                layoutOverride.value = null;
            },
        },
    );
};

// Re-fan a hub's branch children into an even outward spread.
const tidyChildren = (node) =>
    router.post(route('talent-nodes.arrange', activeWeb.value.id), { parent_id: node.id }, { preserveScroll: true, preserveState: true });

// Layout mode is exclusive with Muse and link mode: entering it leaves those.
const toggleLayoutMode = () => {
    layoutMode.value = !layoutMode.value;
    museOpen.value = false;
    linkingFrom.value = null;
};
</script>

<template>
    <Head :title="`${system.name} · Builder`" />

    <div class="min-h-screen bg-night text-ink">
        <header class="flex items-center gap-3 border-b border-edge bg-surface px-4 py-3">
            <Link :href="backHref" class="btn-ghost !px-3 !py-1.5 text-xs">← Back</Link>
            <div>
                <p class="eyebrow-muted">{{ context === 'admin' ? 'Template' : 'World system' }} builder</p>
                <h1 class="font-display text-xl text-bright">{{ system.name }}</h1>
            </div>
            <button class="btn-ghost ml-auto" @click="previewOpen = true">Preview</button>
            <button class="btn-primary" :disabled="form.processing" @click="saveSystem">Save system</button>
        </header>

        <div class="flex">
            <nav class="w-44 shrink-0 border-r border-edge bg-surface p-2">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    class="mb-1 block w-full rounded px-3 py-2 text-left text-sm"
                    :class="tab === item.key ? 'bg-amber/15 text-amber' : 'text-muted hover:text-ink'"
                    @click="tab = item.key"
                >
                    {{ item.label }}
                </button>
            </nav>

            <main class="min-w-0 flex-1 p-5">
                <!-- Details -->
                <section v-if="tab === 'details'" class="max-w-xl space-y-4">
                    <label class="block">
                        <span class="eyebrow-muted">Name</span>
                        <input v-model="form.name" class="field mt-1" />
                    </label>
                    <label class="block">
                        <span class="eyebrow-muted">Description</span>
                        <textarea v-model="form.description" rows="3" class="field mt-1"></textarea>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="eyebrow-muted">Progression</span>
                            <select v-model="form.settings.progression_mode" class="field mt-1">
                                <option v-for="mode in options.progressionModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="eyebrow-muted">Starting points</span>
                            <input v-model.number="form.settings.starting_points" type="number" class="field mt-1" />
                        </label>
                        <label class="block">
                            <span class="eyebrow-muted">Level cap</span>
                            <input v-model.number="form.settings.level_cap" type="number" class="field mt-1" />
                        </label>
                        <label class="col-span-2 flex items-center gap-2 text-sm text-muted">
                            <input v-model="form.settings.points_cumulative" type="checkbox" class="rounded border-edge3 bg-raised" />
                            Level points accumulate as characters level up
                        </label>
                    </div>

                    <div class="border-t border-edge pt-4">
                        <p class="eyebrow-muted mb-1">Node roles</p>
                        <p class="mb-2 text-xs text-faint">Which kind the canvas “+” menu creates for each role. Leave on Auto to pick by size.</p>
                        <div class="grid grid-cols-3 gap-3">
                            <label v-for="role in ['child', 'adjacent', 'master']" :key="role" class="block">
                                <span class="text-[10px] capitalize text-faint">{{ role }}</span>
                                <select v-model="form.settings.role_kinds[role]" class="field mt-1">
                                    <option :value="null">Auto</option>
                                    <option v-for="kind in form.nodeKinds" :key="kind.key" :value="kind.key">{{ kind.label || kind.key }}</option>
                                </select>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- List editors (stats / resources / skills / levels / node kinds) -->
                <section v-else-if="activeSchema">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-sm text-muted">{{ activeSchema.blurb }}</p>
                        <button class="btn-ghost shrink-0 text-xs" @click="addRow">+ {{ activeSchema.title }}</button>
                    </div>
                    <div class="panel overflow-hidden">
                        <table class="wb-table w-full table-fixed">
                            <thead>
                                <tr>
                                    <th v-for="col in activeSchema.columns" :key="col.key" :class="col.class">{{ col.label }}</th>
                                    <th class="w-24 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in activeRows" :key="index">
                                    <td
                                        v-for="col in activeSchema.columns"
                                        :key="col.key"
                                        :class="[col.truncate ? 'truncate' : '', col.mono ? 'font-mono text-faint' : '']"
                                    >
                                        {{ cellValue(row, col) }}
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <button class="text-xs text-teal hover:underline" @click="editRow(index)">Edit</button>
                                        <button class="ml-3 text-xs text-faint hover:text-blood" @click="deleteRow(index)">Delete</button>
                                    </td>
                                </tr>
                                <tr v-if="!activeRows.length">
                                    <td :colspan="activeSchema.columns.length + 1" class="py-6 text-center text-faint">
                                        Nothing yet — add one with the button above.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Compendium prerequisites -->
                <section v-else-if="tab === 'compendium'" class="max-w-2xl">
                    <p class="mb-3 text-sm text-muted">
                        Mark the library compendiums this system needs. A world that adopts this system imports their entries, so its
                        nodes can grant those spells, feats and abilities.
                    </p>
                    <div class="panel divide-y divide-edge2">
                        <label
                            v-for="source in compendiumSources"
                            :key="source.id"
                            class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                :checked="isSourceSelected(source.id)"
                                class="rounded border-edge3 bg-raised"
                                @change="toggleSource(source.id)"
                            />
                            <span class="flex-1 text-ink">{{ source.name }} <span class="text-faint">· {{ source.item_type }}</span></span>
                            <span class="font-mono text-xs text-faint">{{ source.count }}</span>
                        </label>
                        <p v-if="!compendiumSources.length" class="px-3 py-6 text-center text-faint">No library compendiums are available yet.</p>
                    </div>
                    <div v-if="context === 'world'" class="mt-3">
                        <button
                            class="btn-primary text-xs"
                            :disabled="importing || !form.settings.compendium_sources.length"
                            @click="importCompendium"
                        >
                            Import selected into this world now
                        </button>
                        <p class="mt-1 text-[11px] text-faint">Save the system first so your selection is stored.</p>
                    </div>
                    <p v-else class="mt-3 text-[11px] text-faint">These import automatically when a world adopts this template.</p>
                </section>

                <!-- Web -->
                <section v-else-if="tab === 'webs'">
                    <div v-if="activeWeb" class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <div class="mb-2 flex items-center gap-2">
                                <template v-if="linkingFrom">
                                    <span class="rounded bg-amber/15 px-2 py-1 text-xs text-amber">Click a node to link it, or press Cancel.</span>
                                    <button class="btn-ghost text-xs" @click="linkingFrom = null">Cancel</button>
                                </template>
                                <template v-else-if="layoutMode">
                                    <span class="rounded bg-amber/15 px-2 py-1 text-xs text-amber">Layout mode — drag hubs to reposition. Structure and effects stay locked.</span>
                                </template>
                                <template v-else>
                                    <button v-if="webEmpty" class="btn-teal text-xs" @click="addNode">+ First cluster</button>
                                    <span v-else class="text-xs text-faint">Hover a node and press + to grow the web.</span>
                                </template>
                                <div class="ml-auto flex items-center gap-2">
                                    <button
                                        class="btn-ghost text-xs"
                                        :class="layoutMode ? 'border-amber text-amber' : ''"
                                        :disabled="webEmpty"
                                        @click="toggleLayoutMode"
                                    >
                                        ⋮⋮ UI layout
                                    </button>
                                    <button
                                        class="btn-ghost text-xs"
                                        :class="museOpen ? 'border-amber text-amber' : ''"
                                        @click="museOpen = !museOpen"
                                    >
                                        ✨ Muse
                                    </button>
                                </div>
                            </div>
                            <div class="panel h-[560px] overflow-hidden">
                                <WebCanvas
                                    :key="activeWeb.id"
                                    :web="displayWeb"
                                    :system="system"
                                    :selected-id="selectedNodeId"
                                    :highlight-id="hoveredConnectionId"
                                    editable
                                    :layout-mode="layoutMode"
                                    @node-click="onNodeClick"
                                    @node-add="openAddPicker"
                                    @node-move="onNodeMove"
                                    @background-click="linkingFrom = null; selectedNodeId = null"
                                />
                            </div>
                        </div>

                        <!-- Muse chat / layout controls / node editor -->
                        <div class="col-span-1">
                            <div v-if="museOpen" class="h-[520px]">
                                <MuseChat :key="activeWeb.id" :web="activeWeb" />
                            </div>
                            <div v-else-if="layoutMode" class="panel space-y-3 p-3">
                                <p class="eyebrow-muted">UI layout mode</p>
                                <p class="text-xs text-faint">
                                    Drag a hub or standalone node to move it — its branch and ring follow. Child and ring nodes can't be
                                    dragged on their own; they follow their parent.
                                </p>
                                <div v-if="selectedNode" class="border-t border-edge pt-2">
                                    <p class="text-xs text-ink">{{ selectedNode.name }}</p>
                                    <button class="btn-ghost mt-1 w-full text-xs" @click="tidyChildren(selectedNode)">Tidy its branch children</button>
                                </div>
                                <button class="btn-primary w-full text-xs" @click="layoutMode = false">Done</button>
                            </div>
                            <div v-else-if="nodeDraft && selectedNode" class="panel space-y-2 p-3">
                                <div class="flex items-center justify-between">
                                    <p class="eyebrow-muted">Edit node</p>
                                    <button class="text-xs text-faint hover:text-blood" @click="deleteNode(selectedNode)">Delete</button>
                                </div>
                                <input v-model="nodeDraft.name" placeholder="Name" class="field" />
                                <select v-model="nodeDraft.kind" class="field">
                                    <option v-for="kind in system.nodeKinds" :key="kind.key" :value="kind.key">{{ kind.label }}</option>
                                </select>
                                <div class="grid grid-cols-3 gap-2">
                                    <label class="block"><span class="text-[10px] text-faint">Glyph</span><input v-model="nodeDraft.config.glyph" placeholder="kind default" class="field" /></label>
                                    <label class="block"><span class="text-[10px] text-faint">Cost (pts)</span><input v-model.number="nodeDraft.cost" type="number" class="field" /></label>
                                    <label class="block" title="The character level a player must reach to allocate this node"><span class="text-[10px] text-faint">Unlocks at lvl</span><input v-model.number="nodeDraft.gate_level" type="number" class="field" /></label>
                                </div>
                                <textarea v-model="nodeDraft.description" rows="2" placeholder="Description" class="field"></textarea>
                                <div>
                                    <div class="mb-1 flex items-center justify-between">
                                        <span class="text-[10px] text-faint">Effects</span>
                                        <span class="text-[10px] text-faint">ANY group = the player chooses one</span>
                                    </div>
                                    <EffectGroupEditor
                                        v-if="nodeDraft.config.effect_tree"
                                        :node="nodeDraft.config.effect_tree"
                                        :system="system"
                                        :compendium="compendium"
                                        is-root
                                    />
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between">
                                        <span class="text-[10px] text-faint">Requirements (to allocate at all)</span>
                                        <button v-if="!hasRequires" class="text-xs text-teal" @click="initRequires">+ requirement</button>
                                        <button v-else class="text-xs text-faint hover:text-blood" @click="clearRequires">clear</button>
                                    </div>
                                    <RequirementEditor v-if="hasRequires" :group="nodeDraft.config.requires" :system="system" :compendium="compendium" />
                                    <p v-else class="text-[11px] text-faint">No prerequisites — allocatable from any connected node.</p>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="block">
                                        <span class="text-[10px] text-faint">Branch colour</span>
                                        <input v-model="nodeDraft.config.colour" placeholder="#hex" class="field" />
                                    </label>
                                    <label class="block">
                                        <span class="text-[10px] text-faint">Drawback</span>
                                        <input v-model="nodeDraft.config.drawback" placeholder="Optional" class="field" />
                                    </label>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] text-faint">Connected to</span>
                                        <button class="text-xs text-teal" @click="startLinking">+ link to node</button>
                                    </div>
                                    <div v-if="selectedEdges.length" class="mt-1 flex flex-wrap gap-1">
                                        <button
                                            v-for="connection in selectedEdges"
                                            :key="connection.id"
                                            class="rounded border border-edge2 px-2 py-0.5 text-[11px] text-faint hover:border-amber hover:text-amber"
                                            @mouseenter="hoveredConnectionId = connection.otherId"
                                            @mouseleave="hoveredConnectionId = null"
                                            @click="removeEdge(connection.id)"
                                        >
                                            {{ connection.name }} ✕
                                        </button>
                                    </div>
                                </div>
                                <p class="text-center text-[11px] text-faint">Changes save automatically.</p>
                            </div>
                            <div v-else class="panel p-4 text-center text-sm text-faint">
                                Click a node to edit it. Hover a node and press + to grow the web.
                            </div>
                        </div>
                    </div>
                    <div v-else class="max-w-md">
                        <p class="text-sm text-faint">This system has no talent web yet.</p>
                        <button class="btn-primary mt-2 text-xs" :disabled="newWeb.processing" @click="createWeb">Create the talent web</button>
                    </div>
                </section>
            </main>
        </div>

        <!-- Row edit modal (stats / resources / skills / levels / node kinds) -->
        <div
            v-if="editingRow"
            class="fixed inset-0 z-50 flex items-center justify-center bg-night/70 p-4"
            @click.self="closeRow"
        >
            <div class="panel w-full max-w-md p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-display text-lg text-bright">Edit {{ editingSchema.title.toLowerCase() }}</h3>
                    <button class="text-faint hover:text-ink" @click="closeRow">✕</button>
                </div>
                <div class="space-y-3">
                    <label v-for="field in editingSchema.fields" :key="field.key" class="block">
                        <span class="eyebrow-muted">{{ field.label }}</span>
                        <textarea
                            v-if="field.type === 'textarea'"
                            v-model="editingRow[field.key]"
                            rows="2"
                            class="field mt-1"
                        ></textarea>
                        <select v-else-if="field.type === 'select'" v-model="editingRow[field.key]" class="field mt-1">
                            <option v-if="field.allowNone" value="">— none —</option>
                            <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <input
                            v-else-if="field.type === 'number'"
                            v-model.number="editingRow[field.key]"
                            type="number"
                            class="field mt-1"
                        />
                        <input v-else v-model="editingRow[field.key]" type="text" class="field mt-1" />
                    </label>
                </div>
                <div class="mt-4 flex items-center justify-between">
                    <p class="text-[11px] text-faint">Kept when you press “Save system”.</p>
                    <button class="btn-primary" @click="closeRow">Done</button>
                </div>
            </div>
        </div>

        <!-- Structural add menu (shown when growing from a node) -->
        <div
            v-if="addTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-night/70 p-4"
            @click.self="closeAddPicker"
        >
            <div class="panel w-full max-w-sm p-5">
                <div class="mb-1 flex items-center justify-between">
                    <h3 class="font-display text-lg text-bright">Grow from “{{ addTarget.source?.name }}”</h3>
                    <button class="text-faint hover:text-ink" @click="closeAddPicker">✕</button>
                </div>
                <p class="mb-3 text-xs text-faint">You can change its type and details after, in the editor.</p>
                <div class="space-y-2">
                    <button class="block w-full rounded border border-edge2 p-3 text-left hover:border-teal" @click="chooseAction('child')">
                        <span class="block text-sm text-ink">Add child node</span>
                        <span class="block text-xs text-faint">Extends the line outward — a small node continuing the branch.</span>
                    </button>
                    <button class="block w-full rounded border border-edge2 p-3 text-left hover:border-teal" @click="chooseAction('adjacent')">
                        <span class="block text-sm text-ink">Add adjacent node</span>
                        <span class="block text-xs text-faint">A sibling beside this one, in the same cluster ring.</span>
                    </button>
                    <button class="block w-full rounded border border-edge2 p-3 text-left hover:border-teal" @click="chooseAction('master')">
                        <span class="block text-sm text-ink">Add master node</span>
                        <span class="block text-xs text-faint">A large centre node further out that this one branches into.</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Preview: test the system on a throwaway character -->
        <TalentPreview v-if="previewOpen" :system="system" :compendium="compendium" :races="races" @close="previewOpen = false" />
    </div>
</template>
