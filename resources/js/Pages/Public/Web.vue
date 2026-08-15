<script setup>
import ConnectionGraph from "@/Components/ConnectionGraph.vue";
import PublicLayout from "@/Layouts/PublicLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    campaign: Object,
    sections: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
    graph: Object,
});

const nodesById = computed(() =>
    Object.fromEntries(props.graph.nodes.map((n) => [n.id, n])),
);

// The most-connected entry, used as the default focus when the URL names none.
const topNode = props.graph.nodes.reduce(
    (best, n) => (n.degree > (best?.degree ?? -1) ? n : best),
    null,
);

// The focus is kept in the address bar (?focus=<id|all>) so it survives a reload and browser
// back/forward lands you back on the entry you were exploring.
const OVERVIEW = "all";
const readFocusParam = () =>
    new URLSearchParams(window.location.search).get("focus");
const focusFromUrl = () => {
    const param = readFocusParam();
    if (param === OVERVIEW) {
        return undefined;
    }
    const id = param ? Number(param) : Number.NaN;
    if (Number.isFinite(id) && props.graph.nodes.some((n) => n.id === id)) {
        return id;
    }
    return topNode && topNode.degree > 0 ? topNode.id : undefined;
};
const focusId = ref(focusFromUrl());
const focused = computed(() =>
    focusId.value == undefined ? null : nodesById.value[focusId.value],
);

// Write the view to the URL (client-side; no server round-trip). We carry the existing history state so
// Inertia's own back/forward handling keeps working. Push adds a history entry; replace just syncs.
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
    writeUrl(String(id), false);
};
const showAll = () => {
    focusId.value = undefined;
    writeUrl(OVERVIEW, false);
};

const onPopState = () => {
    focusId.value = focusFromUrl();
};
onMounted(() => {
    if (!readFocusParam()) {
        writeUrl(
            focusId.value == undefined ? OVERVIEW : String(focusId.value),
            true,
        );
    }
    window.addEventListener("popstate", onPopState);
});
onBeforeUnmount(() => window.removeEventListener("popstate", onPopState));

const entryUrl = (node) =>
    `/w/${props.campaign.slug}/${node.type}/${node.slug}`;
// The card's "Open" button jumps to the entry's real reader page.
const openEntry = (node) => router.visit(entryUrl(node));
</script>

<template>
    <Head :title="`Connections — ${campaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="web"
    >
        <div class="mx-auto w-full max-w-6xl space-y-4 px-6 py-6">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <div
                        class="font-mono text-[10px] uppercase tracking-[0.2em] text-teal"
                    >
                        {{ campaign.name }}
                    </div>
                    <h1 class="font-display text-[30px] text-bright">
                        Connections
                    </h1>
                </div>
                <button
                    v-if="focusId != undefined"
                    class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted transition hover:border-teal hover:text-teal"
                    @click="showAll"
                >
                    Whole web
                </button>
            </div>

            <p v-if="focused" class="text-sm text-muted">
                Focused on
                <span class="text-teal">{{ focused.title }}</span> —
                <Link
                    :href="entryUrl(focused)"
                    class="text-amber hover:underline"
                    >open entry ↗</Link
                >. Click any card to re-centre, drag to rearrange, scroll to
                zoom.
            </p>
            <p v-else class="text-sm text-muted">
                Click a card to focus on an entry and see how it connects to the
                rest of the world.
            </p>

            <ConnectionGraph
                :nodes="graph.nodes"
                :edges="graph.edges"
                :focus="focusId"
                :height="620"
                @select="setFocus"
                @open="openEntry"
            />
        </div>
    </PublicLayout>
</template>
