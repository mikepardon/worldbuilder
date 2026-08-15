<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import ArchiveBlock from "@/Components/ArchiveBlock.vue";
import { scopeBlockCss } from "@/lib/readerCss";
import { deviceClass } from "@/lib/templateVars";
import { isRecent } from "@/lib/readerDate";
import { Head, Link } from "@inertiajs/vue3";
import { computed, reactive, ref } from "vue";

const props = defineProps({
    campaign: Object,
    sections: Array,
    section: Object,
    items: Array,
    viewer: { type: Object, default: () => ({}) },
    // The GM's archive template for this section as a block list; empty falls back to the default grid.
    blocks: { type: Array, default: () => [] },
    // { id: [blocks] } — resolved block sets for "reusable" blocks.
    reusableBlocks: { type: Object, default: () => ({}) },
    // { fieldKey: label } — labels for section fields used as table columns.
    fieldLabels: { type: Object, default: () => ({}) },
});

// Every block's custom CSS — top-level and the children inside columns — scoped to its own element.
const flattenedBlocks = computed(() => {
    const out = [];
    for (const b of props.blocks) {
        out.push(b);
        if (b.type === "columns")
            for (const col of b.settings.cols ?? []) out.push(...col);
        if (b.type === "reusable")
            out.push(...(props.reusableBlocks[b.settings.refId] ?? []));
    }
    return out;
});
const blockCss = computed(() =>
    flattenedBlocks.value
        .map((b) => scopeBlockCss(b.css, b.id))
        .filter(Boolean)
        .join("\n"),
);

// Shared filter state for this section's listing: the `filter` and `facets` blocks write it, the
// grid/table/index blocks read it.
const query = reactive({ q: "", sort: "", kinds: [], tags: [] });

// Sub-tabs for the built-in listing: split a mixed section (e.g. People → Person / Faction / Bloodline)
// by kind, in the section's declared kind order. Only shown when more than one kind is present.
const activeKind = ref("all");
const kindTabs = computed(() => {
    const present = new Map();
    for (const item of props.items) {
        if (!present.has(item.kind)) {
            present.set(item.kind, {
                kind: item.kind,
                label: item.kindLabel,
                count: 0,
            });
        }
        present.get(item.kind).count += 1;
    }
    const order = props.section.kinds ?? [];
    return [...present.values()].sort(
        (a, b) => order.indexOf(a.kind) - order.indexOf(b.kind),
    );
});
const visibleItems = computed(() =>
    activeKind.value === "all"
        ? props.items
        : props.items.filter((e) => e.kind === activeKind.value),
);
// "Person" → "People"; otherwise a simple plural for the tab label.
const pluralLabel = (label) =>
    label === "Person"
        ? "People"
        : label.endsWith("y")
          ? `${label.slice(0, -1)}ies`
          : `${label}s`;
</script>

<template>
    <Head :title="`${section.label} — ${campaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        :active="section.slug"
    >
        <!-- Block-driven archive (an archive template targets this section). -->
        <div v-if="blocks.length" class="mx-auto max-w-5xl space-y-6 px-6 py-14">
            <component :is="'style'" v-if="blockCss" v-text="blockCss" />
            <div v-for="b in blocks" :key="b.id" :class="[`wb-block-${b.id}`, deviceClass(b)]">
                <ArchiveBlock
                    :block="b"
                    :section="section"
                    :items="items"
                    :campaign-slug="campaign.slug"
                    :query="query"
                    :reusable-blocks="reusableBlocks"
                    :field-labels="fieldLabels"
                    @update:query="Object.assign(query, $event)"
                />
            </div>
        </div>

        <!-- Built-in listing (default). -->
        <div v-else class="mx-auto max-w-5xl px-6 py-14">
            <h1 class="font-display text-4xl text-[#f5f1e8]">
                {{ section.label }}
            </h1>
            <div class="mt-1 text-sm text-[#9aa0ab]">
                {{ items.length }}
                {{ items.length === 1 ? "entry" : "entries" }}
            </div>

            <!-- Kind sub-tabs: only when a section mixes more than one kind. -->
            <div
                v-if="kindTabs.length > 1"
                class="mt-6 flex flex-wrap gap-1 border-b border-[#262a33]"
            >
                <button
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm transition"
                    :class="
                        activeKind === 'all'
                            ? 'border-[#6fbfc4] text-[#f5f1e8]'
                            : 'border-transparent text-[#9aa0ab] hover:text-[#f5f1e8]'
                    "
                    @click="activeKind = 'all'"
                >
                    All <span class="text-[#6b7180]">{{ items.length }}</span>
                </button>
                <button
                    v-for="tab in kindTabs"
                    :key="tab.kind"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm transition"
                    :class="
                        activeKind === tab.kind
                            ? 'border-[#6fbfc4] text-[#f5f1e8]'
                            : 'border-transparent text-[#9aa0ab] hover:text-[#f5f1e8]'
                    "
                    @click="activeKind = tab.kind"
                >
                    {{ pluralLabel(tab.label) }}
                    <span class="text-[#6b7180]">{{ tab.count }}</span>
                </button>
            </div>

            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="e in visibleItems"
                    :key="e.id"
                    :href="`/w/${campaign.slug}/${e.type}/${e.slug}`"
                    class="overflow-hidden rounded-lg border border-[#262a33] bg-[#181b21] transition hover:border-[#6fbfc4]"
                >
                    <img
                        v-if="e.card_url"
                        :src="e.card_url"
                        alt=""
                        class="h-32 w-full object-cover"
                    />
                    <div class="p-5">
                        <div class="flex items-center gap-2">
                            <div
                                class="font-mono text-[10px] uppercase tracking-widest text-[#6fbfc4]"
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
                            v-if="e.summary"
                            class="mt-1 line-clamp-3 text-sm text-[#9aa0ab]"
                        >
                            {{ e.summary }}
                        </p>
                    </div>
                </Link>
                <div
                    v-if="!visibleItems.length"
                    class="rounded-lg border border-dashed border-[#2e323c] p-10 text-center text-sm text-[#9aa0ab]"
                >
                    Nothing here yet.
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
