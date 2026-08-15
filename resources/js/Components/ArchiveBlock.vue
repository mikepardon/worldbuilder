<script setup>
// Renders one archive (section listing) template block on the reader. The `filter` block drives a shared
// `query` (search text + sort) that the grid/table/index blocks below it read from, so a section template
// can put a live filter bar above its listing.
import ArchiveBlock from "@/Components/ArchiveBlock.vue";
import ReaderBlock from "@/Components/ReaderBlock.vue";
import { deviceClass } from "@/lib/templateVars";
import { isRecent } from "@/lib/readerDate";
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    block: { type: Object, required: true },
    section: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    campaignSlug: { type: String, default: "" },
    // Shared filter state for the section (search text, sort, selected kinds and tags), owned by the page
    // and mutated by the `filter` and `facets` blocks.
    query: {
        type: Object,
        default: () => ({ q: "", sort: "", kinds: [], tags: [] }),
    },
    // { id: [blocks] } — resolved block sets for "reusable" blocks.
    reusableBlocks: { type: Object, default: () => ({}) },
    // { fieldKey: label } — labels for the section's fields used as table columns.
    fieldLabels: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["update:query"]);

const settings = computed(() => props.block.settings ?? {});

const sortItems = (items, sort) => {
    const list = [...items];
    if (sort === "title") {
        return list.sort((a, b) => a.title.localeCompare(b.title));
    }
    return list.sort((a, b) =>
        (b.updated_at ?? "").localeCompare(a.updated_at ?? ""),
    );
};

// The listing blocks (grid/table/index) all honour the shared search text, the selected kind and tag
// facets, then the filter bar's sort (or the block's own).
const visibleItems = computed(() => {
    const search = (props.query.q ?? "").trim().toLowerCase();
    const kinds = props.query.kinds ?? [];
    const tags = props.query.tags ?? [];
    const filtered = props.items.filter((e) => {
        if (
            search &&
            !e.title.toLowerCase().includes(search) &&
            !(e.summary ?? "").toLowerCase().includes(search)
        ) {
            return false;
        }
        if (kinds.length && !kinds.includes(e.kind)) return false;
        // An entry must carry every selected tag.
        if (tags.length && !tags.every((t) => (e.tags ?? []).includes(t))) {
            return false;
        }
        return true;
    });
    return sortItems(filtered, props.query.sort || settings.value.sort);
});

// Facet chips: the kinds present in this section (labelled), and the tags across its entries by frequency.
const toggleFacet = (key, value) => {
    const current = props.query[key] ?? [];
    const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];
    emit("update:query", { [key]: next });
};
const kindFacets = computed(() => {
    const seen = new Map();
    for (const e of props.items) {
        if (!seen.has(e.kind)) seen.set(e.kind, e.kindLabel);
    }
    return [...seen.entries()].map(([kind, label]) => ({ kind, label }));
});
const tagFacets = computed(() => {
    const counts = new Map();
    for (const e of props.items) {
        for (const tag of e.tags ?? []) {
            counts.set(tag, (counts.get(tag) ?? 0) + 1);
        }
    }
    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
        .slice(0, Number(settings.value.maxTags) || 20)
        .map(([tag]) => tag);
});

const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${Number(settings.value.columns) || 3}, minmax(0, 1fr))`,
}));

// The section-field columns the table shows (in order).
const tableFields = computed(() => settings.value.fields ?? []);
// How many columns the table shows (Title is always on) — for the empty-row colspan.
const tableColumnCount = computed(
    () =>
        1 +
        (settings.value.showKind !== false ? 1 : 0) +
        (settings.value.summary !== false ? 1 : 0) +
        (settings.value.showUpdated !== false ? 1 : 0) +
        (settings.value.showCreated === true ? 1 : 0) +
        tableFields.value.length,
);

// A–Z index: entries grouped by uppercased first letter (non-letters fall under "#"), letters in order.
const indexGroups = computed(() => {
    const groups = new Map();
    for (const entry of sortItems(props.items, "title")) {
        const first = (entry.title[0] ?? "#").toUpperCase();
        const letter = /[A-Z]/.test(first) ? first : "#";
        if (!groups.has(letter)) groups.set(letter, []);
        groups.get(letter).push(entry);
    }
    return [...groups.entries()].map(([letter, entries]) => ({
        letter,
        entries,
    }));
});

const href = (entry) => `/w/${props.campaignSlug}/${entry.type}/${entry.slug}`;
const shortDate = (value) => (value ? value.slice(0, 10) : "");
</script>

<template>
    <!-- Section heading -->
    <div v-if="block.type === 'heading'" class="wb-heading">
        <h1 class="wb-title font-display text-4xl text-bright">
            {{ section.label }}
        </h1>
        <div v-if="settings.count !== false" class="mt-1 text-sm text-muted">
            {{ items.length }} {{ items.length === 1 ? "entry" : "entries" }}
        </div>
    </div>

    <!-- Filter & sort bar -->
    <div v-else-if="block.type === 'filter'" class="flex flex-wrap gap-2">
        <input
            :value="query.q"
            type="search"
            :placeholder="settings.placeholder || 'Search…'"
            class="wb-filter-input min-w-0 flex-1 rounded-lg border border-edge2 bg-card px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-teal focus:outline-none"
            @input="emit('update:query', { q: $event.target.value })"
        />
        <select
            v-if="settings.sort !== false"
            :value="query.sort"
            class="rounded-lg border border-edge2 bg-card px-3 py-2 text-sm text-ink focus:border-teal focus:outline-none"
            @change="emit('update:query', { sort: $event.target.value })"
        >
            <option value="">Recently updated</option>
            <option value="title">A–Z</option>
        </select>
    </div>

    <!-- Filter chips (kinds + tags) -->
    <div
        v-else-if="block.type === 'facets'"
        class="wb-facets flex flex-wrap items-center gap-1.5"
    >
        <template v-if="settings.showKinds !== false && kindFacets.length > 1">
            <button
                v-for="f in kindFacets"
                :key="f.kind"
                class="wb-facet rounded-full border px-2.5 py-1 text-[12px] transition"
                :class="
                    (query.kinds ?? []).includes(f.kind)
                        ? 'wb-facet--on border-teal bg-teal/15 text-teal'
                        : 'border-edge2 text-muted hover:border-teal hover:text-teal'
                "
                @click="toggleFacet('kinds', f.kind)"
            >
                {{ f.label }}
            </button>
        </template>
        <template v-if="settings.showTags !== false">
            <button
                v-for="tag in tagFacets"
                :key="tag"
                class="wb-facet rounded-full border px-2.5 py-1 font-mono text-[11px] lowercase transition"
                :class="
                    (query.tags ?? []).includes(tag)
                        ? 'wb-facet--on border-amber bg-amber/15 text-amber'
                        : 'border-edge2 text-faint hover:border-amber hover:text-amber'
                "
                @click="toggleFacet('tags', tag)"
            >
                #{{ tag }}
            </button>
        </template>
        <button
            v-if="(query.kinds ?? []).length || (query.tags ?? []).length"
            class="ml-1 text-[11px] text-faint underline hover:text-ink"
            @click="emit('update:query', { kinds: [], tags: [] })"
        >
            Clear
        </button>
    </div>

    <!-- Entry grid -->
    <div v-else-if="block.type === 'grid'">
        <div class="grid gap-4" :style="gridStyle">
            <Link
                v-for="e in visibleItems"
                :key="e.id"
                :href="href(e)"
                class="wb-card overflow-hidden rounded-lg border border-edge2 bg-card transition hover:border-teal"
            >
                <img
                    v-if="settings.showImage !== false && e.card_url"
                    :src="e.card_url"
                    alt=""
                    class="h-32 w-full object-cover"
                />
                <div class="p-5">
                    <div class="flex items-center gap-2">
                        <div
                            class="font-mono text-[10px] uppercase tracking-widest text-teal"
                        >
                            {{ e.kindLabel }}
                        </div>
                        <span
                            v-if="isRecent(e.updated_at)"
                            class="rounded-full bg-teal/15 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-teal"
                            >New</span
                        >
                    </div>
                    <div class="mt-1 font-serif text-lg text-[#f3efe6]">
                        {{ e.title }}
                    </div>
                    <p
                        v-if="settings.showSummary !== false && e.summary"
                        class="mt-1 line-clamp-3 text-sm text-muted"
                    >
                        {{ e.summary }}
                    </p>
                </div>
            </Link>
            <div
                v-if="!visibleItems.length"
                class="rounded-lg border border-dashed border-edge3 p-10 text-center text-sm text-muted"
            >
                Nothing here yet.
            </div>
        </div>
    </div>

    <!-- Table view -->
    <div v-else-if="block.type === 'table'" class="overflow-x-auto">
        <table class="wb-table w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-edge2 text-faint">
                    <th class="py-2 pr-3 font-mono text-[11px] uppercase tracking-wider">
                        Title
                    </th>
                    <th
                        v-if="settings.showKind !== false"
                        class="py-2 pr-3 font-mono text-[11px] uppercase tracking-wider"
                    >
                        Kind
                    </th>
                    <th
                        v-if="settings.summary !== false"
                        class="py-2 pr-3 font-mono text-[11px] uppercase tracking-wider"
                    >
                        Summary
                    </th>
                    <th
                        v-for="key in tableFields"
                        :key="key"
                        class="py-2 pr-3 font-mono text-[11px] uppercase tracking-wider"
                    >
                        {{ fieldLabels[key] || key }}
                    </th>
                    <th
                        v-if="settings.showUpdated !== false"
                        class="py-2 pr-3 font-mono text-[11px] uppercase tracking-wider"
                    >
                        Updated
                    </th>
                    <th
                        v-if="settings.showCreated === true"
                        class="py-2 font-mono text-[11px] uppercase tracking-wider"
                    >
                        Created
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="e in visibleItems"
                    :key="e.id"
                    class="border-b border-edge2/60 hover:bg-card"
                >
                    <td class="py-2 pr-3">
                        <Link :href="href(e)" class="text-ink hover:text-teal">{{
                            e.title
                        }}</Link>
                    </td>
                    <td
                        v-if="settings.showKind !== false"
                        class="py-2 pr-3 text-muted"
                    >
                        {{ e.kindLabel }}
                    </td>
                    <td
                        v-if="settings.summary !== false"
                        class="max-w-[28ch] truncate py-2 pr-3 text-muted"
                    >
                        {{ e.summary }}
                    </td>
                    <td
                        v-for="key in tableFields"
                        :key="key"
                        class="py-2 pr-3 text-muted"
                    >
                        {{ (e.fields || {})[key] || "—" }}
                    </td>
                    <td
                        v-if="settings.showUpdated !== false"
                        class="py-2 pr-3 font-mono text-[12px] text-faint"
                    >
                        {{ shortDate(e.updated_at) }}
                    </td>
                    <td
                        v-if="settings.showCreated === true"
                        class="py-2 font-mono text-[12px] text-faint"
                    >
                        {{ shortDate(e.created_at) }}
                    </td>
                </tr>
                <tr v-if="!visibleItems.length">
                    <td :colspan="tableColumnCount" class="py-8 text-center text-muted">
                        Nothing here yet.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- A–Z index -->
    <div v-else-if="block.type === 'index'" class="wb-index">
        <div
            v-if="settings.jumpbar !== false && indexGroups.length"
            class="mb-4 flex flex-wrap gap-1.5"
        >
            <a
                v-for="g in indexGroups"
                :key="g.letter"
                :href="`#wb-index-${g.letter}`"
                class="rounded border border-edge2 px-2 py-0.5 font-mono text-[12px] text-muted transition hover:border-teal hover:text-teal"
                >{{ g.letter }}</a
            >
        </div>
        <div class="space-y-5">
            <div v-for="g in indexGroups" :key="g.letter">
                <div
                    :id="`wb-index-${g.letter}`"
                    class="wb-index-letter mb-1.5 border-b border-edge2 pb-1 font-display text-2xl text-teal"
                >
                    {{ g.letter }}
                </div>
                <ul class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    <li v-for="e in g.entries" :key="e.id">
                        <Link :href="href(e)" class="text-ink hover:text-teal">{{
                            e.title
                        }}</Link>
                    </li>
                </ul>
            </div>
            <div
                v-if="!indexGroups.length"
                class="rounded-lg border border-dashed border-edge3 p-10 text-center text-sm text-muted"
            >
                Nothing here yet.
            </div>
        </div>
    </div>

    <!-- Columns: split the row, then render each child block (recursing for archive listings). -->
    <div
        v-else-if="block.type === 'columns'"
        class="grid gap-5"
        :style="{
            gridTemplateColumns: `repeat(${settings.count || 2}, minmax(0, 1fr))`,
        }"
    >
        <div
            v-for="(col, ci) in settings.cols || []"
            :key="ci"
            class="wb-col space-y-3"
        >
            <div
                v-for="child in col"
                :key="child.id"
                :class="[`wb-block-${child.id}`, deviceClass(child)]"
            >
                <ArchiveBlock
                    :block="child"
                    :section="section"
                    :items="items"
                    :campaign-slug="campaignSlug"
                    :query="query"
                    :reusable-blocks="reusableBlocks"
                    :field-labels="fieldLabels"
                    @update:query="emit('update:query', $event)"
                />
            </div>
        </div>
    </div>

    <!-- Any other block is a self-contained "common" block, rendered by the shared reader block. -->
    <ReaderBlock
        v-else
        :block="block"
        :campaign-slug="campaignSlug"
        :reusable-blocks="reusableBlocks"
    />
</template>
