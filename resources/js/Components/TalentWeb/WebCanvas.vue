<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref } from 'vue';
import { descendantIds, isDraggableRole, kindMap, nodeAccent, nodeVisual, shapeRadius, shapeSpin } from '@/lib/talentWeb';

const props = defineProps({
    web: { type: Object, required: true },
    system: { type: Object, required: true },
    // nodeId -> visual state ('taken'|'open'|'poor'|'gated'|'locked'); omit for a neutral builder view.
    states: { type: Object, default: () => ({}) },
    selectedId: { type: [Number, null], default: null },
    // A node to spotlight (with the edge joining it to the selected node) — e.g. when hovering a
    // "Connected to" chip in the editor.
    highlightId: { type: [Number, null], default: null },
    // Shows the + badge for structural growth. Nodes are otherwise click-to-select.
    editable: { type: Boolean, default: false },
    // Layout mode: drag hubs ('master') and standalone siblings ('adjacent') to reposition them; their
    // children/ring follow. 'child'/'ring' nodes are not draggable directly (they follow their parent).
    layoutMode: { type: Boolean, default: false },
});

const emit = defineEmits(['node-click', 'node-add', 'background-click', 'node-move']);

const kinds = computed(() => kindMap(props.system));
const nodesById = computed(() => {
    const map = {};
    for (const node of props.web.nodes ?? []) {
        map[node.id] = node;
    }
    return map;
});

const viewport = ref(null);
const view = reactive({ tx: 0, ty: 0, scale: 0.5 });

let panState;
let nodeTap;
let nodeDrag;
// Live positions while dragging in layout mode ({ id: { x, y } }); empty at rest, when the node's own
// x/y is used. Cleared on drop, once the parent has taken the final positions.
const dragPositions = ref({});
const nodePos = (node) => dragPositions.value[node.id] ?? { x: node.x, y: node.y };

const kindFor = (node) => kinds.value[node.kind] ?? { shape: 'circle', glyph: '', size: 34 };

const nodeStyle = (node) => {
    const kind = kindFor(node);
    const size = kind.size ?? 34;
    const accent = nodeAccent(node);
    const visual = nodeVisual(props.states[node.id] ?? 'open', accent, size >= 50);
    const selected = props.selectedId === node.id;
    const highlighted = props.highlightId === node.id;
    const pos = nodePos(node);
    return {
        left: `${pos.x - size / 2}px`,
        top: `${pos.y - size / 2}px`,
        width: `${size}px`,
        height: `${size}px`,
        background: visual.bg,
        border: `${selected || highlighted ? 3 : visual.borderWidth}px solid ${selected ? '#f5f1e8' : highlighted ? '#f0d27a' : visual.border}`,
        borderRadius: shapeRadius(kind.shape),
        boxShadow: selected ? '0 0 0 3px rgba(224,163,62,0.4)' : highlighted ? '0 0 0 3px rgba(240,210,122,0.45), 0 0 16px rgba(240,210,122,0.4)' : visual.glow,
        opacity: visual.opacity,
        transform: `rotate(${shapeSpin(kind.shape)}deg)`,
        color: visual.glyphColor,
        cursor: props.layoutMode ? (isDraggableRole(node) ? 'grab' : 'default') : 'pointer',
    };
};

const glyphStyle = (node) => {
    const size = kindFor(node).size ?? 34;
    return { transform: `rotate(${-shapeSpin(kindFor(node).shape)}deg)`, fontSize: `${Math.max(9, Math.round(size * 0.42))}px` };
};

const glyphFor = (node) => {
    if (node.config?.glyph) {
        return node.config.glyph;
    }
    const state = props.states[node.id];
    if (state === 'taken' && (node.kind === 'notable' || node.kind === 'upgrade')) {
        return '✓';
    }
    return kindFor(node).glyph || '';
};

const bigLabel = (node) => (kindFor(node).size ?? 34) >= 40;

// While a master's cluster is dragged, its backing disc and label follow it (overridden in place).
const discOverride = ref(null);
const titleOverride = ref(null);
const discs = computed(() =>
    (props.web.layout?.discs ?? []).map((disc, index) =>
        discOverride.value?.index === index ? { ...disc, x: discOverride.value.x, y: discOverride.value.y } : disc,
    ),
);
const titles = computed(() =>
    (props.web.layout?.titles ?? []).map((title, index) =>
        titleOverride.value?.index === index ? { ...title, x: titleOverride.value.x, y: titleOverride.value.y } : title,
    ),
);

const edges = computed(() =>
    (props.web.edges ?? [])
        .map((edge) => {
            const from = nodesById.value[edge.from_node_id];
            const to = nodesById.value[edge.to_node_id];
            if (!from || !to) {
                return null;
            }
            const fromPos = nodePos(from);
            const toPos = nodePos(to);
            const dx = toPos.x - fromPos.x;
            const dy = toPos.y - fromPos.y;
            const bothTaken = props.states[from.id] === 'taken' && props.states[to.id] === 'taken';
            const highlighted =
                (from.id === props.selectedId && to.id === props.highlightId) ||
                (from.id === props.highlightId && to.id === props.selectedId);
            return {
                id: edge.id,
                style: {
                    left: `${fromPos.x}px`,
                    top: `${fromPos.y}px`,
                    width: `${Math.hypot(dx, dy)}px`,
                    transform: `rotate(${(Math.atan2(dy, dx) * 180) / Math.PI}deg)`,
                    background: highlighted ? '#f0d27a' : bothTaken ? nodeAccent(from) : '#2e323c',
                    height: highlighted || bothTaken ? '4px' : '2px',
                    boxShadow: highlighted ? '0 0 8px rgba(240,210,122,0.6)' : 'none',
                    zIndex: highlighted ? 5 : 'auto',
                },
            };
        })
        .filter(Boolean),
);

// The + badge shows only on the hovered or selected node, in screen space so it stays a constant,
// clickable size at any zoom.
const hoverId = ref(null);
let hoverTimer;
const setHover = (id) => {
    clearTimeout(hoverTimer);
    hoverId.value = id;
};
const clearHover = () => {
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(() => (hoverId.value = null), 80);
};

const badges = computed(() => {
    if (!props.editable || props.layoutMode || view.scale < 0.18) {
        return [];
    }
    return (props.web.nodes ?? [])
        .filter((node) => node.id === hoverId.value || node.id === props.selectedId)
        .map((node) => {
            const half = ((kindFor(node).size ?? 34) / 2) * view.scale;
            return { id: node.id, node, x: node.x * view.scale + view.tx + half, y: node.y * view.scale + view.ty - half - 18 };
        });
});

// --- pan / zoom / tap ---
const onBackgroundDown = (event) => {
    panState = { x: event.clientX, y: event.clientY, tx: view.tx, ty: view.ty, moved: 0 };
};

const onNodeDown = (event, node) => {
    event.stopPropagation();

    // In layout mode a drag on a hub/adjacent node moves it and everything that follows it.
    if (props.layoutMode && isDraggableRole(node)) {
        const ids = descendantIds(props.web, node.id);
        ids.add(node.id);

        let discIndex = -1;
        let discOrigin = null;
        let titleIndex = -1;
        let titleOrigin = null;

        // A master carries its whole cluster: the backing disc, its label, and every node inside the disc
        // (so seeded rings that aren't parent-linked still move as one circle).
        if (node.layout_role === 'master') {
            const clusterDiscs = props.web.layout?.discs ?? [];
            for (let i = 0; i < clusterDiscs.length; i++) {
                if (Math.hypot(clusterDiscs[i].x - node.x, clusterDiscs[i].y - node.y) <= 24) {
                    discIndex = i;
                    discOrigin = { x: clusterDiscs[i].x, y: clusterDiscs[i].y };
                    const radius = (clusterDiscs[i].size ?? 0) / 2;
                    for (const other of props.web.nodes ?? []) {
                        if (Math.hypot(other.x - clusterDiscs[i].x, other.y - clusterDiscs[i].y) <= radius) {
                            ids.add(other.id);
                        }
                    }
                    break;
                }
            }
            const clusterTitles = props.web.layout?.titles ?? [];
            for (let i = 0; i < clusterTitles.length; i++) {
                if (Math.hypot(clusterTitles[i].x - node.x, clusterTitles[i].y - node.y) <= 60) {
                    titleIndex = i;
                    titleOrigin = { x: clusterTitles[i].x, y: clusterTitles[i].y };
                    break;
                }
            }
        }

        const origin = {};
        for (const other of props.web.nodes ?? []) {
            if (ids.has(other.id)) {
                origin[other.id] = { x: other.x, y: other.y };
            }
        }
        nodeDrag = { id: node.id, sx: event.clientX, sy: event.clientY, ids, origin, discIndex, discOrigin, titleIndex, titleOrigin, moved: 0 };
        return;
    }

    nodeTap = { id: node.id, moved: 0 };
};

const onMove = (event) => {
    if (nodeDrag) {
        nodeDrag.moved += Math.abs(event.movementX) + Math.abs(event.movementY);
        const dx = (event.clientX - nodeDrag.sx) / view.scale;
        const dy = (event.clientY - nodeDrag.sy) / view.scale;
        const next = {};
        for (const id of nodeDrag.ids) {
            const start = nodeDrag.origin[id];
            next[id] = { x: Math.round(start.x + dx), y: Math.round(start.y + dy) };
        }
        dragPositions.value = next;
        if (nodeDrag.discIndex >= 0) {
            discOverride.value = { index: nodeDrag.discIndex, x: Math.round(nodeDrag.discOrigin.x + dx), y: Math.round(nodeDrag.discOrigin.y + dy) };
        }
        if (nodeDrag.titleIndex >= 0) {
            titleOverride.value = { index: nodeDrag.titleIndex, x: Math.round(nodeDrag.titleOrigin.x + dx), y: Math.round(nodeDrag.titleOrigin.y + dy) };
        }
        return;
    }
    if (nodeTap) {
        nodeTap.moved += Math.abs(event.movementX) + Math.abs(event.movementY);
        return;
    }
    if (panState) {
        panState.moved += Math.abs(event.movementX) + Math.abs(event.movementY);
        view.tx = panState.tx + (event.clientX - panState.x);
        view.ty = panState.ty + (event.clientY - panState.y);
    }
};

const onUp = () => {
    if (nodeDrag) {
        if (nodeDrag.moved <= 5) {
            // Barely moved — treat as a tap to select, not a reposition.
            emit('node-click', nodesById.value[nodeDrag.id]);
        } else {
            const positions = [];
            for (const id of nodeDrag.ids) {
                const pos = dragPositions.value[id];
                if (pos) {
                    positions.push({ id, x: pos.x, y: pos.y });
                }
            }
            // Carry the moved cluster's disc and label along in the web layout.
            let layout = null;
            if (nodeDrag.discIndex >= 0 || nodeDrag.titleIndex >= 0) {
                layout = JSON.parse(JSON.stringify(props.web.layout ?? {}));
                if (nodeDrag.discIndex >= 0 && layout.discs?.[nodeDrag.discIndex] && discOverride.value) {
                    layout.discs[nodeDrag.discIndex].x = discOverride.value.x;
                    layout.discs[nodeDrag.discIndex].y = discOverride.value.y;
                }
                if (nodeDrag.titleIndex >= 0 && layout.titles?.[nodeDrag.titleIndex] && titleOverride.value) {
                    layout.titles[nodeDrag.titleIndex].x = titleOverride.value.x;
                    layout.titles[nodeDrag.titleIndex].y = titleOverride.value.y;
                }
            }
            emit('node-move', { positions, layout });
        }
        nodeDrag = undefined;
        dragPositions.value = {};
        discOverride.value = null;
        titleOverride.value = null;
        return;
    }
    if (nodeTap) {
        if (nodeTap.moved <= 5) {
            emit('node-click', nodesById.value[nodeTap.id]);
        }
        nodeTap = undefined;
        return;
    }
    if (panState) {
        if (panState.moved <= 5) {
            emit('background-click');
        }
        panState = undefined;
    }
};

const onWheel = (event) => {
    event.preventDefault();
    const rect = viewport.value.getBoundingClientRect();
    const cx = event.clientX - rect.left;
    const cy = event.clientY - rect.top;
    const factor = event.deltaY < 0 ? 1.1 : 1 / 1.1;
    const next = Math.min(2, Math.max(0.12, view.scale * factor));
    const k = next / view.scale;
    view.tx = cx - (cx - view.tx) * k;
    view.ty = cy - (cy - view.ty) * k;
    view.scale = next;
};

const fit = () => {
    const nodes = props.web.nodes ?? [];
    const el = viewport.value;
    if (!nodes.length || !el) {
        return;
    }
    const xs = nodes.map((n) => n.x);
    const ys = nodes.map((n) => n.y);
    const minX = Math.min(...xs) - 80;
    const maxX = Math.max(...xs) + 80;
    const minY = Math.min(...ys) - 80;
    const maxY = Math.max(...ys) + 80;
    const scale = Math.min(el.clientWidth / (maxX - minX), el.clientHeight / (maxY - minY), 1.4);
    view.scale = scale;
    view.tx = el.clientWidth / 2 - ((minX + maxX) / 2) * scale;
    view.ty = el.clientHeight / 2 - ((minY + maxY) / 2) * scale;
};

defineExpose({ fit });

onMounted(() => {
    fit();
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
});
onBeforeUnmount(() => {
    window.removeEventListener('mousemove', onMove);
    window.removeEventListener('mouseup', onUp);
});
</script>

<template>
    <div
        ref="viewport"
        class="relative h-full w-full select-none overflow-hidden bg-night"
        :style="{ cursor: panState ? 'grabbing' : 'grab' }"
        @mousedown="onBackgroundDown"
        @wheel="onWheel"
    >
        <div
            class="absolute left-0 top-0 origin-top-left"
            :style="{ transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})` }"
        >
            <!-- Cluster discs -->
            <div
                v-for="(disc, index) in discs"
                :key="`d${index}`"
                class="absolute rounded-full"
                :style="{
                    left: `${disc.x - disc.size / 2}px`,
                    top: `${disc.y - disc.size / 2}px`,
                    width: `${disc.size}px`,
                    height: `${disc.size}px`,
                    background: 'radial-gradient(closest-side, rgba(46,32,22,0.35) 30%, rgba(18,12,8,0.55) 72%, rgba(10,11,14,0) 100%)',
                    boxShadow: 'inset 0 0 0 1px rgba(201,162,39,0.08), inset 0 0 60px rgba(0,0,0,0.85)',
                }"
            ></div>

            <!-- Edges -->
            <div v-for="edge in edges" :key="`e${edge.id}`" class="absolute origin-left" :style="edge.style"></div>

            <!-- Titles -->
            <div
                v-for="(title, index) in titles"
                :key="`t${index}`"
                class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 text-center"
                :style="{ left: `${title.x}px`, top: `${title.y}px` }"
            >
                <div class="font-display text-[15px] font-bold uppercase tracking-widest" :style="{ color: title.colour || '#c8b79b' }">{{ title.name }}</div>
                <div v-if="title.sub" class="font-mono text-[9px] uppercase tracking-[0.2em] text-faint">{{ title.sub }}</div>
            </div>

            <!-- Node labels (large nodes) -->
            <div
                v-for="node in web.nodes"
                :key="`l${node.id}`"
                class="pointer-events-none absolute -translate-x-1/2 text-center font-display text-[13px] leading-tight text-ink"
                :style="{ left: `${nodePos(node).x}px`, top: `${nodePos(node).y + (kindFor(node).size ?? 34) / 2 + 6}px`, width: '160px', textShadow: '0 1px 4px #000' }"
            >
                <template v-if="bigLabel(node)">{{ node.name }}</template>
            </div>

            <!-- Nodes -->
            <button
                v-for="node in web.nodes"
                :key="`n${node.id}`"
                type="button"
                class="absolute flex items-center justify-center p-0"
                :style="nodeStyle(node)"
                :title="node.name"
                @mousedown="onNodeDown($event, node)"
                @mouseenter="setHover(node.id)"
                @mouseleave="clearHover"
            >
                <span class="font-mono font-semibold leading-none" :style="glyphStyle(node)">{{ glyphFor(node) }}</span>
            </button>
        </div>

        <!-- + badge (screen space, constant size) -->
        <button
            v-for="badge in badges"
            :key="`b${badge.id}`"
            type="button"
            class="absolute flex h-5 w-5 items-center justify-center rounded-full border border-teal bg-night text-xs leading-none text-teal shadow hover:bg-teal hover:text-night"
            :style="{ left: `${badge.x}px`, top: `${badge.y}px` }"
            title="Add a node here"
            @mousedown.stop
            @mouseenter="setHover(badge.id)"
            @mouseleave="clearHover"
            @click.stop="emit('node-add', badge.node)"
        >
            +
        </button>

        <div class="pointer-events-none absolute bottom-3 left-3 font-mono text-[10px] uppercase tracking-widest text-faint">
            <template v-if="layoutMode">drag a hub to move it — its branch follows · scroll to zoom · drag empty space to pan</template>
            <template v-else-if="editable">click a node to edit · + to grow · scroll to zoom · drag to pan</template>
            <template v-else>scroll to zoom · drag to pan</template>
        </div>
    </div>
</template>
