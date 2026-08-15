<script setup>
// Connections web, rendered with Vue Flow (the free MIT graph library). We run a one-off force
// layout to scatter the nodes organically, then hand the settled positions to Vue Flow — so it
// never jitters, but nodes stay draggable and the pane pans/zooms. Edge phrases are drawn as Vue
// Flow labels (their own boxed text), so they never mirror or overprint the connecting line.
import {
    Handle,
    MarkerType,
    Position,
    useVueFlow,
    VueFlow,
} from "@vue-flow/core";
import "@vue-flow/core/dist/style.css";
import { computed, nextTick, ref, watch } from "vue";

const props = defineProps({
    nodes: { type: Array, default: () => [] },
    edges: { type: Array, default: () => [] },
    // When set, only this node and its direct neighbours are shown (per-article mini-graph).
    focus: { type: Number, default: undefined },
    height: { type: Number, default: 520 },
});
const emit = defineEmits(["select", "open"]);

// Relationship "zoom" when focused: how many hops out from the centre node to show (1–3).
const depth = ref(1);
watch(
    () => props.focus,
    () => (depth.value = 1),
);

const KIND_COLOURS = {
    article: "#c9a94e",
    location: "#4e91c9",
    npc: "#c96f4e",
    faction: "#8a4ec9",
    timeline: "#4ec9a0",
    item: "#c9c14e",
    rule: "#7d8590",
    session: "#c94e8a",
    quest: "#c9834e",
    lore: "#4ec96f",
    spell: "#4e6fc9",
    statblock: "#b0554e",
};
const colourFor = (kind) => KIND_COLOURS[kind] ?? "#4e91c9";

// Focus view: keep the centre node and everything within `depth` relationships of it (breadth-first),
// so "zoom 1" shows direct neighbours, "2" their neighbours too, "3" one hop further.
const view = computed(() => {
    if (props.focus == undefined) {
        return { nodes: props.nodes, edges: props.edges };
    }
    const adjacency = new Map();
    for (const e of props.edges) {
        (
            adjacency.get(e.from) ??
            adjacency.set(e.from, new Set()).get(e.from)
        ).add(e.to);
        (adjacency.get(e.to) ?? adjacency.set(e.to, new Set()).get(e.to)).add(
            e.from,
        );
    }
    const keep = new Set([props.focus]);
    let frontier = [props.focus];
    for (let hop = 0; hop < depth.value; hop++) {
        const next = [];
        for (const id of frontier) {
            for (const neighbour of adjacency.get(id) ?? []) {
                if (!keep.has(neighbour)) {
                    keep.add(neighbour);
                    next.push(neighbour);
                }
            }
        }
        frontier = next;
    }
    return {
        nodes: props.nodes.filter((n) => keep.has(n.id)),
        edges: props.edges.filter((e) => keep.has(e.from) && keep.has(e.to)),
    };
});

// Lay the cards out for Vue Flow. A focused view uses a radial layout — the centre card in the middle
// with neighbours on concentric rings — so the spoke edges never cross and interconnected neighbours
// sit next to each other (fewer chord crossings). The whole-web view falls back to a force scatter.
// A separation pass then guarantees no two cards overlap.
function computeLayout(nodes, edges, focusId) {
    const width = 900;
    const height = props.height;
    const cx = width / 2;
    const cy = height / 2;
    // Cards are far wider than tall (~152×54), so keep them apart as rectangles: horizontally-adjacent
    // cards need a wide gap, vertically-stacked cards only a short one — which keeps horizontal edges
    // roughly as they were while noticeably shortening vertical ones. Kept snug so lines stay short.
    const halfW = 88;
    const halfH = 40;

    const sim = nodes.map((node) => ({
        id: node.id,
        x: cx,
        y: cy,
        vx: 0,
        vy: 0,
        fx: 0,
        fy: 0,
    }));
    const byId = Object.fromEntries(sim.map((s) => [s.id, s]));

    // Adjacency within the visible set.
    const idSet = new Set(nodes.map((node) => node.id));
    const adjacency = new Map();
    for (const e of edges) {
        if (!idSet.has(e.from) || !idSet.has(e.to)) {
            continue;
        }
        (adjacency.get(e.from) ?? adjacency.set(e.from, []).get(e.from)).push(
            e.to,
        );
        (adjacency.get(e.to) ?? adjacency.set(e.to, []).get(e.to)).push(e.from);
    }

    // Pull apart any two cards whose rectangles overlap, along the axis of least penetration; the
    // pinned focus card never moves.
    const separate = () => {
        for (let i = 0; i < sim.length; i++) {
            for (let j = i + 1; j < sim.length; j++) {
                const a = sim[i];
                const b = sim[j];
                const dx = b.x - a.x;
                const dy = b.y - a.y;
                const overlapX = 2 * halfW - Math.abs(dx);
                const overlapY = 2 * halfH - Math.abs(dy);
                if (overlapX <= 0 || overlapY <= 0) {
                    continue;
                }
                if (overlapX < overlapY) {
                    const push = (overlapX / 2) * (dx >= 0 ? 1 : -1);
                    if (a.id !== focusId) a.x -= push;
                    if (b.id !== focusId) b.x += push;
                } else {
                    const push = (overlapY / 2) * (dy >= 0 ? 1 : -1);
                    if (a.id !== focusId) a.y -= push;
                    if (b.id !== focusId) b.y += push;
                }
            }
        }
    };

    // Order a ring so interconnected members sit next to each other (greedy walk over the subgraph).
    const orderRing = (ids) => {
        if (ids.length <= 2) {
            return ids;
        }
        const inRing = new Set(ids);
        const ringDegree = (id) =>
            (adjacency.get(id) ?? []).filter((x) => inRing.has(x)).length;
        const byDegree = (a, b) => ringDegree(b) - ringDegree(a);
        const remaining = new Set(ids);
        const result = [];
        let current = [...remaining].sort(byDegree)[0];
        while (remaining.size) {
            result.push(current);
            remaining.delete(current);
            const linked = (adjacency.get(current) ?? [])
                .filter((x) => remaining.has(x))
                .sort(byDegree);
            current = linked[0] ?? [...remaining].sort(byDegree)[0];
        }
        return result;
    };

    const radialLayout = () => {
        byId[focusId].x = cx;
        byId[focusId].y = cy;

        // Breadth-first hop distance + parent from the focus.
        const hop = new Map([[focusId, 0]]);
        const parent = new Map();
        const queue = [focusId];
        while (queue.length) {
            const id = queue.shift();
            for (const neighbour of adjacency.get(id) ?? []) {
                if (!hop.has(neighbour)) {
                    hop.set(neighbour, hop.get(id) + 1);
                    parent.set(neighbour, id);
                    queue.push(neighbour);
                }
            }
        }
        // Anything unreachable in the visible subgraph joins the first ring.
        for (const node of nodes) {
            if (!hop.has(node.id)) {
                hop.set(node.id, 1);
            }
        }

        const rings = new Map();
        for (const [id, h] of hop) {
            (rings.get(h) ?? rings.set(h, []).get(h)).push(id);
        }

        const ringGap = 190;
        const angleOf = new Map();

        const ring1 = orderRing(rings.get(1) ?? []);
        ring1.forEach((id, i) =>
            angleOf.set(id, (i / Math.max(ring1.length, 1)) * Math.PI * 2),
        );

        const maxHop = Math.max(0, ...hop.values());
        for (let h = 2; h <= maxHop; h++) {
            const byParent = new Map();
            for (const id of rings.get(h) ?? []) {
                const p = parent.get(id);
                (byParent.get(p) ?? byParent.set(p, []).get(p)).push(id);
            }
            // Fan a parent's children out in a wedge around the parent's own angle.
            for (const [p, kids] of byParent) {
                const base = angleOf.get(p) ?? 0;
                const wedge = Math.PI / 2.5;
                kids.forEach((id, i) => {
                    const offset =
                        kids.length === 1
                            ? 0
                            : (i / (kids.length - 1) - 0.5) * wedge;
                    angleOf.set(id, base + offset);
                });
            }
        }

        // Squash the rings vertically into ellipses so vertical spokes are shorter than horizontal ones.
        const yScale = 0.7;
        for (const [id, angle] of angleOf) {
            const r = (hop.get(id) ?? 1) * ringGap;
            byId[id].x = cx + Math.cos(angle) * r;
            byId[id].y = cy + Math.sin(angle) * r * yScale;
        }
    };

    const forceLayout = () => {
        sim.forEach((s, i) => {
            const angle = (i / Math.max(sim.length, 1)) * Math.PI * 2;
            const spread = 70 + (i % 5) * 26;
            s.x = cx + Math.cos(angle) * spread;
            s.y = cy + Math.sin(angle) * spread;
        });

        const repulsion = 7000;
        const spring = 0.02;
        const restLength = 210;
        const centring = 0.012;
        const damping = 0.85;
        let alpha = 1;

        for (let iter = 0; iter < 400 && alpha > 0.003; iter++) {
            for (const s of sim) {
                s.fx = 0;
                s.fy = 0;
            }
            for (let i = 0; i < sim.length; i++) {
                for (let j = i + 1; j < sim.length; j++) {
                    const a = sim[i];
                    const b = sim[j];
                    const dx = a.x - b.x;
                    const dy = a.y - b.y;
                    const distanceSq = dx * dx + dy * dy || 0.01;
                    const distance = Math.sqrt(distanceSq);
                    const force = (repulsion * alpha) / distanceSq;
                    a.fx += (dx / distance) * force;
                    a.fy += (dy / distance) * force;
                    b.fx -= (dx / distance) * force;
                    b.fy -= (dy / distance) * force;
                }
            }
            for (const e of edges) {
                const a = byId[e.from];
                const b = byId[e.to];
                if (!a || !b) continue;
                const dx = b.x - a.x;
                const dy = b.y - a.y;
                const distance = Math.sqrt(dx * dx + dy * dy) || 0.01;
                const force = spring * (distance - restLength) * alpha;
                a.fx += (dx / distance) * force;
                a.fy += (dy / distance) * force;
                b.fx -= (dx / distance) * force;
                b.fy -= (dy / distance) * force;
            }
            for (const s of sim) {
                s.fx += (cx - s.x) * centring * alpha;
                s.fy += (cy - s.y) * centring * alpha;
                s.vx = (s.vx + s.fx) * damping;
                s.vy = (s.vy + s.fy) * damping;
                s.x += s.vx;
                s.y += s.vy;
            }
            separate();
            alpha *= 0.985;
        }
    };

    if (focusId != undefined && byId[focusId]) {
        radialLayout();
    } else {
        forceLayout();
    }

    // Final separation passes guarantee no residual overlap (radial rings can crowd near a parent).
    for (let pass = 0; pass < 50; pass++) {
        separate();
    }

    return byId;
}

const flowNodes = ref([]);
const flowEdges = ref([]);

function rebuild() {
    const positions = computeLayout(
        view.value.nodes,
        view.value.edges,
        props.focus,
    );
    flowNodes.value = view.value.nodes.map((node) => ({
        id: String(node.id),
        type: "entity",
        position: {
            x: positions[node.id]?.x ?? 0,
            y: positions[node.id]?.y ?? 0,
        },
        data: { ...node, focused: node.id === props.focus },
    }));
    // Collapse reciprocal edges (A→B and B→A) into one per pair, so a connection shows a single label
    // instead of two overprinting each other. A field relationship (e.g. "Owner of") wins over a
    // hand-added/typed link, which in turn wins over a bare wiki "mentions".
    const edgeRank = (e) =>
        e.relationship === "reference"
            ? 2
            : e.relationship === "mentions"
              ? 0
              : 1;
    const byPair = new Map();
    for (const e of view.value.edges) {
        const key = e.from < e.to ? `${e.from}-${e.to}` : `${e.to}-${e.from}`;
        const kept = byPair.get(key);
        if (!kept || edgeRank(e) > edgeRank(kept)) {
            byPair.set(key, e);
        }
    }

    // Edge phrases are only legible when few edges are shown — i.e. a focused view. There we orient
    // every edge to emanate FROM the centre node and read from its perspective, so the arrow and the
    // phrase (e.g. "Owner of") both point outward from the focused entry.
    const focusId = props.focus;
    const withLabels = focusId != undefined;
    flowEdges.value = [...byPair.values()].map((e, i) => {
        let source = e.from;
        let target = e.to;
        let label = "";
        if (withLabels) {
            if (e.to === focusId) {
                source = e.to;
                target = e.from;
                label = e.inverseLabel ?? e.label ?? "";
            } else {
                label = e.label ?? "";
            }
        }
        return {
            id: `e${i}`,
            source: String(source),
            target: String(target),
            label,
            type: "straight",
            markerEnd: {
                type: MarkerType.ArrowClosed,
                color: "#6aa595",
                width: 16,
                height: 16,
            },
            style: { stroke: "#3f7d6e", strokeWidth: 1.5, strokeOpacity: 0.75 },
            labelBgStyle: { fill: "#0b0d10", fillOpacity: 0.9 },
            labelStyle: { fill: "#8fb9ad", fontSize: 10 },
            labelBgPadding: [4, 2],
            labelBgBorderRadius: 3,
        };
    });
}

watch(() => [props.nodes, props.edges, props.focus, depth.value], rebuild, {
    immediate: true,
});

const { fitView, updateNodeInternals, onNodesInitialized } = useVueFlow();
const frameGraph = () => fitView({ padding: 0.12, duration: 300 });
// Whenever the cards (re)measure — first paint, or after more hops reveal new cards — recompute the
// handle positions so every edge attaches, then re-frame so the whole set stays nicely visible.
onNodesInitialized(() => {
    updateNodeInternals();
    frameGraph();
});
// Zooming back in shows fewer cards and initialises none, so re-frame on any depth change too.
watch(depth, () => nextTick(frameGraph));
const onNodeClick = ({ node }) => emit("select", Number(node.id));
const setDepth = (value) => (depth.value = value);
</script>

<template>
    <div
        class="relative w-full overflow-hidden rounded-lg border border-edge2 bg-[#0b0d10]"
        :style="{ height: height + 'px' }"
    >
        <VueFlow
            :nodes="flowNodes"
            :edges="flowEdges"
            :min-zoom="0.2"
            :max-zoom="3"
            :nodes-connectable="false"
            :elements-selectable="false"
            fit-view-on-init
            class="cg-flow"
            @node-click="onNodeClick"
        >
            <template #node-entity="{ data }">
                <div
                    class="cg-card"
                    :class="{
                        'cg-card--focus': data.focused,
                        'cg-card--private': data.is_private,
                    }"
                >
                    <Handle
                        type="target"
                        :position="Position.Top"
                        class="cg-handle"
                    />
                    <Handle
                        type="source"
                        :position="Position.Top"
                        class="cg-handle"
                    />
                    <span
                        class="cg-badge"
                        :title="`${data.degree} connection${data.degree === 1 ? '' : 's'}`"
                        >{{ data.degree }}</span
                    >
                    <span
                        class="cg-avatar"
                        :style="{ background: colourFor(data.kind) }"
                    >
                        <img v-if="data.image" :src="data.image" alt="" />
                    </span>
                    <span class="cg-body">
                        <span
                            class="cg-kind"
                            :style="{ color: colourFor(data.kind) }"
                            >{{ data.kindLabel }}</span
                        >
                        <span class="cg-title">{{ data.title }}</span>
                        <span v-if="data.summary" class="cg-blurb">{{
                            data.summary
                        }}</span>
                    </span>
                    <!-- Opens the entry's real content page (not another graph view). -->
                    <button
                        class="cg-open"
                        title="Open entry"
                        @click.stop="emit('open', data)"
                        @pointerdown.stop
                        @mousedown.stop
                    >
                        ↗
                    </button>
                </div>
            </template>
        </VueFlow>

        <!-- Relationship "zoom": how many hops out from the focused card to reveal. -->
        <div
            v-if="focus != undefined"
            class="absolute left-3 top-3 z-10 flex items-center gap-1 rounded-md border border-edge2 bg-[#14171d]/90 px-1.5 py-1 shadow"
        >
            <span
                class="px-1 font-mono text-[9px] uppercase tracking-wider text-faint"
                >Hops</span
            >
            <button
                v-for="level in 3"
                :key="level"
                class="h-6 w-6 rounded text-xs"
                :class="
                    depth === level
                        ? 'bg-teal/20 text-teal'
                        : 'text-muted hover:text-ink'
                "
                @click="setDepth(level)"
            >
                {{ level }}
            </button>
        </div>

        <div
            v-if="!flowNodes.length"
            class="absolute inset-0 flex items-center justify-center text-sm text-faint"
        >
            No connections yet.
        </div>
    </div>
</template>

<style scoped>
.cg-flow {
    background: transparent;
}
/* Keep the node cards above the edge labels so the focused card in the centre stays readable on load. */
:deep(.vue-flow__node) {
    z-index: 4 !important;
}
/* Card node: an avatar (picture or kind colour), title, kind and a one-line blurb. */
.cg-card {
    position: relative;
    display: flex;
    align-items: center;
    gap: 7px;
    width: 152px;
    padding: 6px 8px;
    border-radius: 9px;
    background: #14171d;
    border: 1px solid #262b34;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.45);
    cursor: pointer;
}
.cg-card--focus {
    border-color: #f0e6cf;
    box-shadow: 0 0 0 2px rgba(240, 230, 207, 0.35);
}
.cg-card--private {
    opacity: 0.6;
}
.cg-avatar {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    overflow: hidden;
    border-radius: 9999px;
    border: 2px solid rgba(255, 255, 255, 0.85);
}
.cg-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.cg-body {
    display: flex;
    min-width: 0;
    flex-direction: column;
}
.cg-kind {
    font-family: "JetBrains Mono", ui-monospace, monospace;
    font-size: 7.5px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.cg-title {
    overflow: hidden;
    font-size: 11px;
    font-weight: 600;
    color: #e7e9ee;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cg-blurb {
    overflow: hidden;
    font-size: 9px;
    color: #8b93a1;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cg-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 15px;
    height: 15px;
    padding: 0 4px;
    border-radius: 9999px;
    border: 1px solid #0b0d10;
    background: #3f7d6e;
    font-size: 8.5px;
    font-weight: 700;
    color: #eafff8;
}
.cg-open {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 5px;
    border: 1px solid #2e3844;
    background: #1a1f27;
    color: #8fb9ad;
    font-size: 10px;
    line-height: 1;
    cursor: pointer;
}
.cg-open:hover {
    border-color: #3f7d6e;
    color: #cfeee6;
}
/* Centre both handles on the card and hide them — edges float from the middle of each card. */
.cg-handle {
    opacity: 0 !important;
    min-width: 0 !important;
    min-height: 0 !important;
    width: 4px !important;
    height: 4px !important;
    border: 0 !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
}
</style>
