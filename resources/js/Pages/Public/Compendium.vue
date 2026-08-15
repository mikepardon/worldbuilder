<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    sections: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    columns: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ q: "", type: "" }) },
    items: { type: Object, default: () => ({ data: [] }) },
});

const q = ref(props.filters.q ?? "");

// Push a new search/tab to the server (resetting to page 1), reloading just the results + columns.
const go = (params) =>
    router.get(
        `/w/${props.campaign.slug}/compendium`,
        { q: params.q || undefined, type: params.type || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ["items", "filters", "columns"],
        },
    );

// Debounce typing so we don't fire a request per keystroke.
let searchTimer;
watch(q, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(
        () => go({ q: value, type: props.filters.type }),
        250,
    );
});
const setType = (type) => {
    q.value = "";
    go({ type });
};
</script>

<template>
    <Head :title="`Compendium — ${campaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="compendium"
    >
        <div class="mx-auto max-w-5xl px-6 py-14">
            <h1 class="font-display text-4xl text-[#f5f1e8]">Compendium</h1>
            <div class="mt-1 text-sm text-[#9aa0ab]">
                {{ total }} {{ total === 1 ? "entry" : "entries" }} across
                {{ types.length }} categories
            </div>

            <!-- One tab per type -->
            <div class="mt-6 flex flex-wrap gap-2 border-b border-edge2 pb-px">
                <button
                    v-for="t in types"
                    :key="t.type"
                    type="button"
                    class="-mb-px rounded-t-md border-b-2 px-3 py-2 font-mono text-[11px] uppercase tracking-wider transition"
                    :class="
                        filters.type === t.type
                            ? 'border-amber text-amber'
                            : 'border-transparent text-muted hover:text-ink'
                    "
                    @click="setType(t.type)"
                >
                    {{ t.plural }} <span class="text-faint">{{ t.count }}</span>
                </button>
            </div>

            <!-- Search -->
            <div class="relative mt-5">
                <svg
                    class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-faint"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                <input
                    v-model="q"
                    type="search"
                    placeholder="Search this category…"
                    class="w-full rounded-lg border border-edge2 bg-[#15181e] py-2.5 pl-10 pr-3 text-[15px] text-ink placeholder:text-faint focus:border-amber focus:outline-none"
                />
            </div>

            <!-- Table -->
            <div
                class="mt-6 overflow-x-auto rounded-xl border border-edge2 bg-[#15181e]"
            >
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-edge2 font-mono text-[10px] uppercase tracking-widest text-faint"
                        >
                            <th class="px-5 py-3 font-normal">Name</th>
                            <th
                                v-for="c in columns"
                                :key="c.key"
                                class="whitespace-nowrap px-5 py-3 font-normal"
                            >
                                {{ c.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in items.data"
                            :key="e.slug"
                            class="group border-b border-edge2/60 transition last:border-0 hover:bg-[#1b1f27]"
                        >
                            <td class="px-5 py-3">
                                <Link
                                    :href="`/w/${campaign.slug}/compendium/${e.slug}`"
                                    class="font-serif text-[15px] text-[#f3efe6] group-hover:text-amber"
                                    >{{ e.name }}</Link
                                >
                                <div
                                    v-if="e.summary"
                                    class="mt-0.5 line-clamp-1 text-[13px] text-[#9aa0ab]"
                                >
                                    {{ e.summary }}
                                </div>
                            </td>
                            <td
                                v-for="c in columns"
                                :key="c.key"
                                class="whitespace-nowrap px-5 py-3 align-top font-mono text-[13px] text-muted"
                            >
                                {{ e.facts[c.key] || "—" }}
                            </td>
                        </tr>
                        <tr v-if="!items.data.length">
                            <td
                                :colspan="columns.length + 1"
                                class="px-5 py-12 text-center text-sm text-[#9aa0ab]"
                            >
                                Nothing matches your search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="items.last_page > 1"
                class="mt-6 flex items-center justify-between font-mono text-xs text-muted"
            >
                <Link
                    v-if="items.prev_page_url"
                    :href="items.prev_page_url"
                    preserve-scroll
                    class="rounded-md border border-edge3 px-3 py-1.5 text-ink hover:border-amber"
                >
                    ← Prev
                </Link>
                <span
                    v-else
                    class="rounded-md border border-edge2 px-3 py-1.5 text-faint"
                    >← Prev</span
                >
                <span
                    >Page {{ items.current_page }} of
                    {{ items.last_page }}</span
                >
                <Link
                    v-if="items.next_page_url"
                    :href="items.next_page_url"
                    preserve-scroll
                    class="rounded-md border border-edge3 px-3 py-1.5 text-ink hover:border-amber"
                >
                    Next →
                </Link>
                <span
                    v-else
                    class="rounded-md border border-edge2 px-3 py-1.5 text-faint"
                    >Next →</span
                >
            </div>
        </div>
    </PublicLayout>
</template>
