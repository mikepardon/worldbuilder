<script setup>
// The World Bible as an expandable web: the world sits in the centre with its families around it.
// Click a family and its children are ADDED to the graph — its types fan out, and clicking a type
// (or a single-type family) reveals the entries themselves as nodes, each showing its thumbnail when
// it has one. Clicking an entry opens a detail panel with links to view its connections or open its
// page. Several branches can be open at once, each laid out within its own angular slice.
import { colourFor, familyColourFor } from "@/lib/kindColours";
import { computed, ref } from "vue";

const props = defineProps({
    world: { type: Object, required: true },
    families: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    height: { type: Number, default: 720 },
});
const emit = defineEmits(["open", "focus"]);

/* ---- geometry ---- */
const VB = 1100;
const CX = 550;
const CY = 550;
const R1 = 155; // radius of the family ring
const STEP = 140; // extra radius per level as branches expand
const TAU = Math.PI * 2;

const short = (text, max = 22) =>
    text.length > max ? `${text.slice(0, max - 1)}…` : text;

const nodesByKind = computed(() => {
    const map = {};
    for (const node of props.nodes) {
        (map[node.kind] ??= []).push(node);
    }
    return map;
});
const kindLabelFor = (kind) => nodesByKind.value[kind]?.[0]?.kindLabel ?? kind;
const entriesFor = (kind) =>
    [...(nodesByKind.value[kind] ?? [])].sort(
        (a, b) =>
            (b.degree ?? 0) - (a.degree ?? 0) || a.title.localeCompare(b.title),
    );
const entryNode = (entry) => ({
    id: `doc:${entry.id}`,
    docId: entry.id,
    label: entry.title,
    title: entry.title,
    colour: colourFor(entry.kind),
    image: entry.image ?? undefined,
    summary: entry.summary ?? undefined,
    kindLabel: entry.kindLabel ?? kindLabelFor(entry.kind),
    type: "entry",
    children: [],
});

// The full tree: world → families → (types → entries), collapsing the type level for single-type
// families so "Locations" opens straight onto its places rather than a redundant middle node.
const treeFamilies = computed(() =>
    props.families
        .map((family) => {
            const kinds = family.kinds
                .map((kind) => ({
                    kind,
                    label: kindLabelFor(kind),
                    colour: colourFor(kind),
                    count: nodesByKind.value[kind]?.length ?? 0,
                }))
                .filter((k) => k.count > 0);
            if (kinds.length === 0) return undefined;

            const children =
                kinds.length === 1
                    ? entriesFor(kinds[0].kind).map(entryNode)
                    : kinds.map((k) => ({
                          id: `kind:${family.slug}:${k.kind}`,
                          label: k.label,
                          colour: k.colour,
                          count: k.count,
                          type: "kind",
                          children: entriesFor(k.kind).map(entryNode),
                      }));

            return {
                id: `fam:${family.slug}`,
                label: family.label,
                colour: familyColourFor(family.slug),
                count: kinds.reduce((sum, k) => sum + k.count, 0),
                type: "family",
                children,
            };
        })
        .filter(Boolean),
);

/* ---- expansion + selection state ---- */
const expanded = ref(new Set());
const isExpanded = (id) => expanded.value.has(id);
const toggle = (id) => {
    const next = new Set(expanded.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    expanded.value = next;
};
const selected = ref(undefined); // the entry whose detail panel is open
const clickNode = (node) => {
    if (node.type === "world") {
        expanded.value = new Set();
        selected.value = undefined;
    } else if (node.type === "entry") {
        selected.value = node;
    } else {
        toggle(node.id);
    }
};

const worldInitials = computed(() => {
    const parts = String(props.world.name ?? "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (parts.length === 0) return "?";
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[1][0]).toUpperCase();
});

// Walk the visible tree, laying every open branch inside its own angular slice so nothing collides.
const layout = computed(() => {
    const nodeList = [];
    const edgeList = [];

    const place = (children, start, end, radius, depth, parent) => {
        const count = children.length;
        children.forEach((child, i) => {
            const angle = start + ((end - start) * (i + 0.5)) / count;
            const x = CX + radius * Math.cos(angle);
            const y = CY + radius * Math.sin(angle);
            const r = child.type === "entry" ? 22 : depth === 1 ? 36 : 26;
            nodeList.push({ ...child, x, y, r, depth });
            edgeList.push({
                x1: parent.x,
                y1: parent.y,
                x2: x,
                y2: y,
                colour: child.colour,
            });
            if (isExpanded(child.id) && child.children.length) {
                const span = (end - start) / count;
                place(
                    child.children,
                    angle - span / 2,
                    angle + span / 2,
                    radius + STEP,
                    depth + 1,
                    { x, y },
                );
            }
        });
    };

    place(treeFamilies.value, -Math.PI / 2, -Math.PI / 2 + TAU, R1, 1, {
        x: CX,
        y: CY,
    });

    return { nodes: nodeList, edges: edgeList };
});

// Entry nodes with a thumbnail, so we can define one SVG pattern per image.
const thumbNodes = computed(() =>
    layout.value.nodes.filter((n) => n.type === "entry" && n.image),
);
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div class="panel relative overflow-hidden p-2">
            <p class="px-2 pt-1 font-mono text-[11px] text-faint">
                Click a family to expand its types, a type to reveal its entries,
                and an entry for its details. Click the centre to collapse.
            </p>

            <p
                v-if="!treeFamilies.length"
                class="p-10 text-center text-sm text-muted"
            >
                No entries yet. Create people, places and lore and they'll gather
                into families here.
            </p>
            <svg
                v-else
                :viewBox="`0 0 ${VB} ${VB}`"
                class="w-full"
                :style="{ maxHeight: height + 'px' }"
                preserveAspectRatio="xMidYMid meet"
            >
                <defs>
                    <pattern
                        v-for="node in thumbNodes"
                        :id="`thumb-${node.docId}`"
                        :key="node.docId"
                        patternContentUnits="objectBoundingBox"
                        width="1"
                        height="1"
                    >
                        <image
                            :href="node.image"
                            preserveAspectRatio="xMidYMid slice"
                            width="1"
                            height="1"
                        />
                    </pattern>
                </defs>

                <!-- edges under the nodes -->
                <g>
                    <line
                        v-for="(edge, i) in layout.edges"
                        :key="i"
                        :x1="edge.x1"
                        :y1="edge.y1"
                        :x2="edge.x2"
                        :y2="edge.y2"
                        :stroke="edge.colour"
                        stroke-width="1.4"
                        opacity="0.25"
                    />
                </g>

                <!-- centre: the world; click to collapse everything -->
                <g class="cursor-pointer" @click="clickNode({ type: 'world' })">
                    <circle
                        :cx="CX"
                        :cy="CY"
                        r="46"
                        fill="#1b1d22"
                        stroke="#3a3f47"
                        stroke-width="1.5"
                    />
                    <text
                        :x="CX"
                        :y="CY"
                        text-anchor="middle"
                        dominant-baseline="middle"
                        fill="#efe9e2"
                        style="font-size: 15px; font-weight: 700"
                    >
                        {{ worldInitials }}
                    </text>
                    <text
                        :x="CX"
                        :y="CY + 66"
                        text-anchor="middle"
                        fill="#8a847d"
                        style="font-size: 12px"
                    >
                        {{ world.name }}
                    </text>
                </g>

                <!-- every visible node -->
                <g
                    v-for="node in layout.nodes"
                    :key="node.id"
                    class="cursor-pointer"
                    @click="clickNode(node)"
                >
                    <circle
                        :cx="node.x"
                        :cy="node.y"
                        :r="node.r"
                        :fill="
                            node.type === 'entry' && node.image
                                ? `url(#thumb-${node.docId})`
                                : node.colour
                        "
                        :fill-opacity="
                            node.type === 'entry'
                                ? 1
                                : isExpanded(node.id)
                                  ? 0.95
                                  : 0.55
                        "
                        :stroke="
                            selected && selected.id === node.id
                                ? '#f2e5e1'
                                : node.colour
                        "
                        :stroke-width="selected && selected.id === node.id ? 3 : 2"
                        :stroke-opacity="isExpanded(node.id) ? 1 : 0.65"
                    />
                    <text
                        v-if="node.type !== 'entry'"
                        :x="node.x"
                        :y="node.y"
                        text-anchor="middle"
                        dominant-baseline="middle"
                        fill="#141414"
                        :style="`font-size:${node.depth === 1 ? 15 : 12}px;font-weight:700;`"
                    >
                        {{ node.count }}
                    </text>
                    <text
                        :x="node.x"
                        :y="node.y + node.r + 13"
                        text-anchor="middle"
                        fill="#cbc5be"
                        :style="`font-size:${node.type === 'entry' ? 10 : 12.5}px;`"
                    >
                        {{ short(node.label, node.type === "entry" ? 18 : 22) }}
                    </text>
                </g>
            </svg>
        </div>

        <!-- Detail panel for the selected entry -->
        <aside class="flex flex-col gap-3">
            <div v-if="selected" class="panel flex flex-col gap-3 p-4">
                <div class="flex items-start gap-3">
                    <span
                        class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border-2"
                        :style="{ borderColor: selected.colour }"
                    >
                        <img
                            v-if="selected.image"
                            :src="selected.image"
                            alt=""
                            class="h-full w-full object-cover"
                        />
                        <span
                            v-else
                            class="font-display text-sm text-bright"
                            :style="{ background: selected.colour }"
                            >{{ selected.title.slice(0, 2) }}</span
                        >
                    </span>
                    <div class="min-w-0">
                        <div class="font-display text-lg leading-tight text-bright">
                            {{ selected.title }}
                        </div>
                        <div
                            class="font-mono text-[10px] uppercase tracking-wider text-faint"
                        >
                            {{ selected.kindLabel }}
                        </div>
                    </div>
                    <button
                        class="ml-auto text-faint hover:text-ink"
                        title="Close"
                        @click="selected = undefined"
                    >
                        ✕
                    </button>
                </div>

                <p v-if="selected.summary" class="text-[13px] text-muted">
                    {{ selected.summary }}
                </p>

                <div class="mt-1 flex flex-col gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-edge3 px-3 py-1.5 text-left text-sm text-ink hover:border-amber hover:text-amber"
                        @click="emit('focus', selected.docId)"
                    >
                        View connections →
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-edge3 px-3 py-1.5 text-left text-sm text-ink hover:border-amber hover:text-amber"
                        @click="emit('open', selected.docId)"
                    >
                        Open page →
                    </button>
                </div>
            </div>

            <div v-else class="panel p-4 text-sm text-muted">
                Your world at a glance. Expand a family to its entries, then click
                one to see its details, connections, and page.
            </div>
        </aside>
    </div>
</template>

<style scoped>
/* Animate positions so expanding a branch visibly grows the web. */
svg circle,
svg text,
svg line {
    transition:
        cx 0.25s ease,
        cy 0.25s ease,
        x 0.25s ease,
        y 0.25s ease,
        x1 0.25s ease,
        y1 0.25s ease,
        x2 0.25s ease,
        y2 0.25s ease;
}
</style>
