<script setup>
// A family tree rendered with Vue Flow. Members sit in generation rows (roots on top, children below
// their deepest parent); partners are placed side by side and joined, children hang from their parents
// with orthogonal connectors. In editable mode each card sprouts + buttons to add a child or partner.
import {
    Handle,
    MarkerType,
    Position,
    useVueFlow,
    VueFlow,
} from "@vue-flow/core";
import "@vue-flow/core/dist/style.css";
import { computed, ref, watch } from "vue";

const props = defineProps({
    // [{ id, name, subtitle, image?, link:{type,slug,title}|null, parents:[{id,type}], partners:[id] }]
    members: { type: Array, default: () => [] },
    height: { type: Number, default: 520 },
    // Fill the parent's height instead of using a fixed `height` (for the full-height editor canvas).
    fill: { type: Boolean, default: false },
    linkBase: { type: String, default: "" }, // reader base "/w/slug/"; cards link out when set
    editable: { type: Boolean, default: false },
});
const emit = defineEmits([
    "add-child",
    "add-partner",
    "edit",
    "delete",
    "add-root",
]);

const COL = 210;
const ROW = 170;

function computeLayout(members) {
    const byId = new Map(members.map((m) => [m.id, m]));
    const parentsOf = (m) =>
        (m.parents ?? []).map((p) => p.id).filter((id) => byId.has(id));

    // Companions must share a generation and sit next to each other: partners, and co-parents (two
    // people who share a child) — so a demigod's mortal mother lands beside Zeus, not up at the roots.
    const companions = new Map(members.map((m) => [m.id, new Set()]));
    for (const m of members) {
        for (const pid of m.partners ?? []) {
            if (byId.has(pid)) {
                companions.get(m.id).add(pid);
                companions.get(pid).add(m.id);
            }
        }
        const ps = parentsOf(m);
        for (const a of ps) {
            for (const b of ps) {
                if (a !== b) companions.get(a).add(b);
            }
        }
    }

    // Generation by constraint propagation: a child sits one row below its parents, and companions are
    // pulled level with each other. Iterate (gens only rise) until stable.
    const gen = new Map(members.map((m) => [m.id, 0]));
    for (let iter = 0; iter <= members.length + 4; iter++) {
        let changed = false;
        for (const m of members) {
            const ps = parentsOf(m);
            if (ps.length) {
                const want = 1 + Math.max(...ps.map((id) => gen.get(id)));
                if (want > gen.get(m.id)) {
                    gen.set(m.id, want);
                    changed = true;
                }
            }
        }
        for (const m of members) {
            const group = [m.id, ...companions.get(m.id)];
            const maxG = Math.max(...group.map((id) => gen.get(id)));
            for (const id of group) {
                if (gen.get(id) < maxG) {
                    gen.set(id, maxG);
                    changed = true;
                }
            }
        }
        if (!changed) break;
    }

    const rows = new Map();
    for (const m of members) {
        const g = gen.get(m.id);
        (rows.get(g) ?? rows.set(g, []).get(g)).push(m);
    }
    const maxGen = Math.max(0, ...gen.values());

    const pos = new Map();
    for (let g = 0; g <= maxGen; g++) {
        let row = rows.get(g) ?? [];
        // Order each row under its parents (roots keep authored order).
        if (g > 0) {
            const avgParentX = (m) => {
                const xs = parentsOf(m)
                    .map((id) => pos.get(id)?.x)
                    .filter((x) => x != null);
                return xs.length
                    ? xs.reduce((a, b) => a + b, 0) / xs.length
                    : 0;
            };
            row = [...row].sort((a, b) => avgParentX(a) - avgParentX(b));
        }
        // Keep companions (partners + co-parents) adjacent.
        const ordered = [];
        const placed = new Set();
        for (const m of row) {
            if (placed.has(m.id)) continue;
            ordered.push(m);
            placed.add(m.id);
            for (const cid of companions.get(m.id)) {
                const companion = row.find(
                    (x) => x.id === cid && !placed.has(x.id),
                );
                if (companion) {
                    ordered.push(companion);
                    placed.add(companion.id);
                }
            }
        }
        const total = ordered.length * COL;
        ordered.forEach((m, i) =>
            pos.set(m.id, { x: i * COL - total / 2, y: g * ROW }),
        );
    }
    return pos;
}

const flowNodes = ref([]);
const baseEdges = ref([]);

function rebuild() {
    const pos = computeLayout(props.members);
    const has = new Set(props.members.map((m) => m.id));

    flowNodes.value = props.members.map((m) => {
        // "Married in": has no parents in the tree, yet sits below the top generation — i.e. they joined
        // the family by partnering someone who descends from the roots. Shown with a dashed avatar.
        const hasParentInTree = (m.parents ?? []).some((p) => has.has(p.id));
        const gen = Math.round((pos.get(m.id)?.y ?? 0) / ROW);
        return {
            id: m.id,
            type: "member",
            position: pos.get(m.id) ?? { x: 0, y: 0 },
            data: { ...m, marriedIn: !hasParentInTree && gen > 0 },
        };
    });

    const edges = [];
    for (const m of props.members) {
        for (const parent of m.parents ?? []) {
            if (!has.has(parent.id)) continue;
            edges.push({
                id: `p-${parent.id}-${m.id}`,
                source: parent.id,
                target: m.id,
                type: parent.type,
                adopted: parent.type !== "biological",
            });
        }
        // Partners are conveyed by sitting side by side (and by sharing children); no extra line.
    }
    baseEdges.value = edges;
}
watch(() => props.members, rebuild, { immediate: true, deep: true });

/* ---- hover a person to light up their bloodline (their descendants and the lines to them) ---- */
const hoveredId = ref(null);
const childrenMap = computed(() => {
    const map = new Map(props.members.map((m) => [m.id, []]));
    for (const m of props.members) {
        for (const p of m.parents ?? []) {
            if (map.has(p.id)) map.get(p.id).push(m.id);
        }
    }
    return map;
});
// Descendants (down the tree) and ancestors (up the tree) of the hovered person.
const descendantSet = computed(() => {
    const set = new Set();
    if (!hoveredId.value) return set;
    const queue = [hoveredId.value];
    while (queue.length) {
        for (const child of childrenMap.value.get(queue.shift()) ?? []) {
            if (!set.has(child)) {
                set.add(child);
                queue.push(child);
            }
        }
    }
    return set;
});
const ancestorSet = computed(() => {
    const set = new Set();
    if (!hoveredId.value) return set;
    const byId = new Map(props.members.map((m) => [m.id, m]));
    const queue = [hoveredId.value];
    while (queue.length) {
        for (const p of byId.get(queue.shift())?.parents ?? []) {
            if (byId.has(p.id) && !set.has(p.id) && p.id !== hoveredId.value) {
                set.add(p.id);
                queue.push(p.id);
            }
        }
    }
    return set;
});
// A node's role while hovering: the person themself, their descendants, their ancestors, or aside.
const roleOf = (id) => {
    if (!hoveredId.value) return null;
    if (id === hoveredId.value) return "focus";
    if (descendantSet.value.has(id)) return "down";
    if (ancestorSet.value.has(id)) return "up";
    return "off";
};

// Individual curved connectors (not a shared step-bus, which made everyone look co-parented). On hover,
// the line up the tree (ancestors) is amber and the line down (descendants) is teal.
const flowEdges = computed(() => {
    const active = !!hoveredId.value;
    const inUp = (id) => id === hoveredId.value || ancestorSet.value.has(id);
    const inDown = (id) => id === hoveredId.value || descendantSet.value.has(id);
    return baseEdges.value.map((e) => {
        const up = active && inUp(e.source) && inUp(e.target);
        const down = active && inDown(e.source) && inDown(e.target);
        const on = up || down;
        return {
            id: e.id,
            source: e.source,
            sourceHandle: "b",
            target: e.target,
            targetHandle: "t",
            type: "default",
            markerEnd: MarkerType.ArrowClosed,
            zIndex: on ? 10 : 0,
            style: {
                stroke: up ? "#e0a33e" : down ? "#6fbfc4" : "#5b6770",
                strokeWidth: on ? 2.5 : 1.5,
                strokeDasharray: e.adopted ? "5 4" : undefined,
                opacity: active && !on ? 0.2 : 1,
            },
            label: e.adopted ? e.type : undefined,
            labelBgStyle: { fill: "#14161b" },
            labelStyle: { fill: "#9aa0ab", fontSize: 9 },
        };
    });
});

const { fitView } = useVueFlow();
const onReady = () => fitView({ padding: 0.2 });
watch(
    () => props.members.length,
    () => setTimeout(onReady, 0),
);

const hrefFor = (member) =>
    member.link && props.linkBase
        ? `${props.linkBase}${member.link.type}/${member.link.slug}`
        : null;

const initials = (name) =>
    (name || "?")
        .trim()
        .split(/\s+/)
        .map((word) => word[0])
        .slice(0, 2)
        .join("")
        .toUpperCase();
</script>

<template>
    <div
        class="relative w-full overflow-hidden rounded-lg"
        :class="fill ? 'h-full' : ''"
        :style="fill ? {} : { height: height + 'px' }"
    >
        <VueFlow
            :nodes="flowNodes"
            :edges="flowEdges"
            :min-zoom="0.2"
            :max-zoom="2"
            :nodes-connectable="false"
            :nodes-draggable="false"
            :elements-selectable="false"
            fit-view-on-init
            @nodes-initialized="onReady"
        >
            <template #node-member="{ data }">
                <div
                    class="bl-node"
                    :class="[
                        {
                            'bl-node--edit': editable,
                            'bl-node--married': data.marriedIn,
                        },
                        roleOf(data.id) && `bl-node--${roleOf(data.id)}`,
                    ]"
                    @mouseenter="hoveredId = data.id"
                    @mouseleave="hoveredId = null"
                >
                    <Handle
                        id="t"
                        type="target"
                        :position="Position.Top"
                        class="bl-handle"
                    />
                    <Handle
                        id="b"
                        type="source"
                        :position="Position.Bottom"
                        class="bl-handle"
                    />
                    <Handle
                        id="l"
                        type="target"
                        :position="Position.Left"
                        class="bl-handle"
                    />
                    <Handle
                        id="r"
                        type="source"
                        :position="Position.Right"
                        class="bl-handle"
                    />

                    <button
                        v-if="editable"
                        class="bl-del nodrag nopan"
                        title="Remove"
                        @click.stop="emit('delete', data.id)"
                        @pointerdown.stop
                        @mousedown.stop
                    >
                        ✕
                    </button>

                    <div class="bl-avatar">
                        <img v-if="data.image" :src="data.image" alt="" />
                        <span v-else class="bl-initials">{{
                            initials(data.name)
                        }}</span>
                    </div>
                    <component
                        :is="hrefFor(data) ? 'a' : 'span'"
                        :href="hrefFor(data) || undefined"
                        class="bl-plate nodrag nopan"
                        :class="{
                            'bl-plate--link': hrefFor(data),
                            'bl-plate--edit': editable,
                        }"
                        @click="editable && emit('edit', data.id)"
                        @pointerdown.stop
                        @mousedown.stop
                    >
                        <span class="bl-name">{{
                            data.name || "Unnamed"
                        }}</span>
                        <span v-if="data.subtitle" class="bl-sub">{{
                            data.subtitle
                        }}</span>
                    </component>

                    <template v-if="editable">
                        <button
                            class="bl-add bl-add--partner nodrag nopan"
                            title="Add partner"
                            @click.stop="emit('add-partner', data.id)"
                            @pointerdown.stop
                            @mousedown.stop
                        >
                            ＋
                        </button>
                        <button
                            class="bl-add bl-add--child nodrag nopan"
                            title="Add child"
                            @click.stop="emit('add-child', data.id)"
                            @pointerdown.stop
                            @mousedown.stop
                        >
                            ＋
                        </button>
                    </template>
                </div>
            </template>
        </VueFlow>

        <div
            v-if="!members.length"
            class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm text-faint"
        >
            <p>No members yet.</p>
            <button
                v-if="editable"
                class="rounded-md border border-teal/50 px-3 py-1.5 text-teal hover:bg-teal/10"
                @click="emit('add-root')"
            >
                + Add the first person
            </button>
        </div>
    </div>
</template>

<style scoped>
/* Vue Flow disables pointer events on non-draggable/non-selectable nodes, which kills hover + clicks
   on our cards and + buttons. Force them back on (pan by dragging the empty canvas). */
:deep(.vue-flow__node) {
    pointer-events: all !important;
}
/* An avatar with a nameplate below — no card, on-theme with the dark reader. */
.bl-node {
    position: relative;
    display: flex;
    width: 120px;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
.bl-avatar {
    display: flex;
    height: 62px;
    width: 62px;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: 9999px;
    border: 2px solid #3a4048;
    background: #1a1d24;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45);
}
.bl-avatar img {
    height: 100%;
    width: 100%;
    object-fit: cover;
}
/* Married-in members (partnered into the family, no ancestors here) always wear a dashed avatar. */
.bl-node--married .bl-avatar {
    border-style: dashed;
    border-color: #6a7078;
}
/* Hover: the hovered person (cream) and their descendants (teal, down) and ancestors (amber, up) glow;
   everyone else just dims. */
.bl-node {
    transition: opacity 0.15s ease;
}
.bl-node--off {
    opacity: 0.85;
}
.bl-node--focus .bl-avatar {
    border-style: solid;
    border-color: #f0e6cf;
    box-shadow:
        0 0 0 3px rgba(240, 230, 207, 0.4),
        0 2px 6px rgba(0, 0, 0, 0.45);
}
.bl-node--down .bl-avatar {
    border-style: solid;
    border-color: #6fbfc4;
    box-shadow: 0 0 0 3px rgba(111, 191, 196, 0.35);
}
.bl-node--up .bl-avatar {
    border-style: solid;
    border-color: #e0a33e;
    box-shadow: 0 0 0 3px rgba(224, 163, 62, 0.35);
}
.bl-initials {
    font-family: "Spectral", Georgia, serif;
    font-size: 18px;
    color: #c8ccd3;
}
.bl-plate {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
    border-radius: 9999px;
    border: 1px solid #2a2f38;
    background: #171a20;
    padding: 4px 14px;
}
.bl-name {
    font-family: "Spectral", Georgia, serif;
    font-size: 12.5px;
    font-weight: 600;
    white-space: nowrap;
    color: #e7e9ee;
}
.bl-sub {
    font-size: 9.5px;
    white-space: nowrap;
    color: #8a909b;
}
.bl-plate--link:hover,
.bl-plate--edit:hover {
    cursor: pointer;
    border-color: #3f7d6e;
}
.bl-handle {
    opacity: 0 !important;
    width: 6px !important;
    height: 6px !important;
    min-width: 0 !important;
    min-height: 0 !important;
    border: 0 !important;
}
/* Editing affordances */
.bl-add {
    position: absolute;
    z-index: 5;
    display: flex;
    height: 20px;
    width: 20px;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    border: 1px solid #3f7d6e;
    background: #14171d;
    font-size: 12px;
    line-height: 1;
    color: #8fb9ad;
    cursor: pointer;
}
.bl-add:hover {
    background: #1a1f27;
    color: #cfeee6;
}
.bl-add--child {
    bottom: -12px;
    left: 50%;
    transform: translateX(-50%);
}
.bl-add--partner {
    right: -6px;
    top: 22px;
}
.bl-del {
    position: absolute;
    top: -4px;
    left: 24px;
    z-index: 5;
    display: none;
    height: 18px;
    width: 18px;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    border: 1px solid #7a3a3a;
    background: #241414;
    font-size: 10px;
    color: #d99;
}
.bl-node--edit:hover .bl-del {
    display: flex;
}
</style>
