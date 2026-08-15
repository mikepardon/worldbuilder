<script setup>
import ConnectionGraph from "@/Components/ConnectionGraph.vue";
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    graph: Object,
});

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

const REL_LABELS = {
    related_to: "Related to",
    mentions: "Mentions",
    located_in: "Located in",
    part_of: "Part of",
    member_of: "Member of",
    ally_of: "Ally of",
    rival_of: "Rival of",
    parent_of: "Parent of",
    owns: "Owns",
    created_by: "Created by",
    serves: "Serves",
    reference: "Field links",
};

const nodesById = computed(() =>
    Object.fromEntries(props.graph.nodes.map((n) => [n.id, n])),
);

/* ---- filters ---- */
const kinds = [
    ...new Map(props.graph.nodes.map((n) => [n.kind, n.kindLabel])),
].map(([kind, label]) => ({ kind, label, colour: colourFor(kind) }));
const kindOn = reactive(Object.fromEntries(kinds.map((k) => [k.kind, true])));

const relKeys = [...new Set(props.graph.edges.map((e) => e.relationship))];
const rels = relKeys.map((key) => ({ key, label: REL_LABELS[key] ?? key }));
const relOn = reactive(Object.fromEntries(relKeys.map((k) => [k, true])));

const filteredNodes = computed(() =>
    props.graph.nodes.filter((n) => kindOn[n.kind]),
);
const filteredNodeIds = computed(
    () => new Set(filteredNodes.value.map((n) => n.id)),
);
const filteredEdges = computed(() =>
    props.graph.edges.filter(
        (e) =>
            relOn[e.relationship] &&
            filteredNodeIds.value.has(e.from) &&
            filteredNodeIds.value.has(e.to),
    ),
);

/* ---- focus (kept in the address bar as ?focus=<id|all> for reload + back/forward) ---- */
const topNode = props.graph.nodes.reduce(
    (best, n) => (n.degree > (best?.degree ?? -1) ? n : best),
    null,
);
const OVERVIEW = "all";
const readFocusParam = () =>
    new URLSearchParams(window.location.search).get("focus");
// Resolve the URL's focus param to { id, overview }, falling back to the most-connected entry.
const stateFromUrl = () => {
    const param = readFocusParam();
    if (param === OVERVIEW) {
        return { id: null, overview: true };
    }
    const id = param ? Number(param) : Number.NaN;
    if (Number.isFinite(id) && props.graph.nodes.some((n) => n.id === id)) {
        return { id, overview: false };
    }
    return topNode && topNode.degree > 0
        ? { id: topNode.id, overview: false }
        : { id: null, overview: true };
};
const initialState = stateFromUrl();
const focusId = ref(initialState.id);
const overview = ref(initialState.overview);

// The id we actually hand the graph: null in overview, or the focus if it survives the filters.
const effectiveFocus = computed(() => {
    if (overview.value) return undefined;
    return focusId.value !== null && filteredNodeIds.value.has(focusId.value)
        ? focusId.value
        : undefined;
});
const focusedNode = computed(() =>
    focusId.value !== null ? nodesById.value[focusId.value] : null,
);

// Write the current view to the URL (client-side; Inertia's state is carried so its own back/forward
// keeps working). A push adds a history entry; a replace just keeps the bar in step on first load.
const writeUrl = (param, replace) => {
    const url = new URL(window.location.href);
    url.searchParams.set("focus", param);
    if (replace) {
        window.history.replaceState(window.history.state, "", url);
    } else {
        window.history.pushState(window.history.state, "", url);
    }
};

const setFocus = (id) => {
    focusId.value = id;
    overview.value = false;
    writeUrl(String(id), false);
};
const showOverview = () => {
    overview.value = true;
    writeUrl(OVERVIEW, false);
};
const onPopState = () => {
    const next = stateFromUrl();
    focusId.value = next.id;
    overview.value = next.overview;
};
onMounted(() => {
    // Stamp the initial view into the address bar so back/forward has a target from the very first click.
    if (!readFocusParam()) {
        writeUrl(overview.value ? OVERVIEW : String(focusId.value), true);
    }
    window.addEventListener("popstate", onPopState);
});
onBeforeUnmount(() => window.removeEventListener("popstate", onPopState));
const openEntry = (id) => router.get(route("documents.edit", id));

/* ---- neighbours of the focused entry, grouped by relationship phrase ---- */
const neighbours = computed(() => {
    if (overview.value || focusId.value === null) return [];
    const groups = new Map();
    for (const e of filteredEdges.value) {
        let phrase, otherId;
        if (e.from === focusId.value) {
            phrase = e.label;
            otherId = e.to;
        } else if (e.to === focusId.value) {
            phrase = e.inverseLabel;
            otherId = e.from;
        } else continue;
        const other = nodesById.value[otherId];
        if (!other) continue;
        if (!groups.has(phrase)) groups.set(phrase, []);
        groups.get(phrase).push(other);
    }
    return [...groups].map(([phrase, items]) => ({ phrase, items }));
});

/* ---- search ---- */
const search = ref("");
const searchResults = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    return filteredNodes.value
        .filter((n) => n.title.toLowerCase().includes(q))
        .slice(0, 12);
});
const pick = (id) => {
    setFocus(id);
    search.value = "";
};

const linkedCount = computed(
    () => props.graph.nodes.filter((n) => n.degree > 0).length,
);
</script>

<template>
    <Head title="Connections web" />

    <WorldLayout :world="world">
        <div class="flex items-end justify-between gap-5">
            <div class="flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    {{ campaign.name }}
                </div>
                <div
                    class="font-display text-[32px] leading-[1.05] text-bright"
                >
                    Connections web
                </div>
            </div>
            <div class="font-mono text-[11px] text-faint">
                {{ graph.nodes.length }} entries · {{ linkedCount }} linked ·
                {{ graph.edges.length }} connections
            </div>
        </div>

        <!-- Toolbar: search + focus/overview -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <input
                    v-model="search"
                    class="field !w-[260px] !py-1.5 text-sm"
                    placeholder="Find an entry to focus…"
                />
                <div
                    v-if="searchResults.length"
                    class="absolute z-10 mt-1 max-h-64 w-[260px] overflow-auto rounded-md border border-edge2 bg-surface shadow-xl"
                >
                    <button
                        v-for="n in searchResults"
                        :key="n.id"
                        class="flex w-full items-center gap-2 border-b border-edge px-3 py-1.5 text-left last:border-0 hover:bg-raised"
                        @click="pick(n.id)"
                    >
                        <span
                            class="inline-block h-2.5 w-2.5 shrink-0 rounded-full"
                            :style="{ background: colourFor(n.kind) }"
                        />
                        <span
                            class="min-w-0 flex-1 truncate text-[13.5px] text-ink"
                            >{{ n.title }}</span
                        >
                        <span class="font-mono text-[10px] text-faint">{{
                            n.kindLabel
                        }}</span>
                    </button>
                </div>
            </div>
            <button
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm"
                :class="
                    overview
                        ? 'border-amber text-amber'
                        : 'border-edge3 text-muted hover:border-amber hover:text-amber'
                "
                @click="showOverview"
            >
                Whole web
            </button>
            <span
                v-if="!overview && focusedNode"
                class="font-mono text-[11px] text-faint"
            >
                Focused on
                <span class="text-teal">{{ focusedNode.title }}</span> — click
                any node to re-centre
            </span>
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-2">
            <div
                v-if="kinds.length"
                class="flex flex-wrap items-center gap-1.5"
            >
                <span
                    class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                    >Types</span
                >
                <button
                    v-for="k in kinds"
                    :key="k.kind"
                    class="flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs transition"
                    :class="
                        kindOn[k.kind]
                            ? 'border-edge2 text-ink'
                            : 'border-edge2 text-faint opacity-50'
                    "
                    @click="kindOn[k.kind] = !kindOn[k.kind]"
                >
                    <span
                        class="inline-block h-2.5 w-2.5 rounded-full"
                        :style="{ background: k.colour }"
                    />
                    {{ k.label }}
                </button>
            </div>
            <div
                v-if="rels.length > 1"
                class="flex flex-wrap items-center gap-1.5"
            >
                <span
                    class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                    >Links</span
                >
                <button
                    v-for="r in rels"
                    :key="r.key"
                    class="rounded-full border px-2.5 py-0.5 text-xs transition"
                    :class="
                        relOn[r.key]
                            ? 'border-teal/50 text-teal'
                            : 'border-edge2 text-faint opacity-50'
                    "
                    @click="relOn[r.key] = !relOn[r.key]"
                >
                    {{ r.label }}
                </button>
            </div>
        </div>

        <p class="max-w-2xl text-sm text-muted">
            Start on your busiest entry and step outward — click a node (or
            search) to re-centre on it and see just its connections. Switch to
            <b class="text-ink">Whole web</b> for the full picture. Links come
            from <b class="text-ink">[[wiki-links]]</b> and relationships you
            add by hand.
        </p>

        <!-- Graph + neighbours -->
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
            <ConnectionGraph
                :nodes="filteredNodes"
                :edges="filteredEdges"
                :focus="effectiveFocus"
                :height="600"
                @select="setFocus"
                @open="openEntry($event.id)"
            />

            <aside class="flex flex-col gap-4">
                <div v-if="!overview && focusedNode" class="panel p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-display text-lg text-bright">
                                {{ focusedNode.title }}
                            </div>
                            <div
                                class="font-mono text-[10px] uppercase tracking-wider text-faint"
                            >
                                {{ focusedNode.kindLabel }}
                            </div>
                        </div>
                        <button
                            class="shrink-0 rounded-md border border-edge3 px-2.5 py-1 text-xs text-muted hover:border-amber hover:text-amber"
                            @click="openEntry(focusedNode.id)"
                        >
                            Open
                        </button>
                    </div>

                    <div
                        v-if="neighbours.length"
                        class="mt-4 flex flex-col gap-3"
                    >
                        <div v-for="group in neighbours" :key="group.phrase">
                            <div
                                class="mb-1 font-mono text-[10px] uppercase tracking-wider text-teal"
                            >
                                {{ group.phrase }}
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <button
                                    v-for="n in group.items"
                                    :key="n.id"
                                    class="flex items-center gap-2 rounded px-1.5 py-1 text-left text-[13.5px] text-ink hover:bg-raised"
                                    @click="setFocus(n.id)"
                                >
                                    <span
                                        class="inline-block h-2 w-2 shrink-0 rounded-full"
                                        :style="{
                                            background: colourFor(n.kind),
                                        }"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{
                                        n.title
                                    }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-4 text-sm text-muted">
                        No connections yet. Add
                        <b class="text-ink">[[wiki-links]]</b> in its text or a
                        relationship from its editor.
                    </p>
                </div>

                <div v-else class="panel p-4 text-sm text-muted">
                    Showing the whole web. Click a node or search to focus on
                    one entry and read its connections.
                </div>
            </aside>
        </div>
    </WorldLayout>
</template>
