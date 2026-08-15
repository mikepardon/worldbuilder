<script setup>
// The styled preview of a single (non-columns) block, used by the template builder's canvas for both
// top-level blocks and the children inside a Columns block. Pure display — selection/chrome live in the
// parent. It renders with the real .wb-* classes so per-block Custom CSS shows live.
import RenderedContent from "@/Components/RenderedContent.vue";
import { buildVars, resolveVars } from "@/lib/templateVars";
import { computed, ref } from "vue";

const props = defineProps({
    block: { type: Object, required: true },
    // Sample or real entry data driving the preview.
    preview: { type: Object, default: () => ({}) },
    // { key: label } for the template's kind, so a facts block can label chosen fields.
    fieldLabels: { type: Object, default: () => ({}) },
    // [{ id, name, type }] — to name a reference block's chosen entry.
    compendiumItems: { type: Array, default: () => [] },
    // [{ id, name }] — to name a map block's chosen map.
    mapOptions: { type: Array, default: () => [] },
    // [{ id, name }] — to name a reusable block's chosen set.
    reusableOptions: { type: Array, default: () => [] },
});

const refItem = computed(() =>
    props.compendiumItems.find((i) => i.id === props.block.settings.refId),
);
const mapItem = computed(() =>
    props.mapOptions.find((m) => m.id === props.block.settings.mapId),
);
const reusableItem = computed(() =>
    props.reusableOptions.find((r) => r.id === props.block.settings.refId),
);
const calloutClass = {
    info: "border-teal/60 bg-teal/5",
    tip: "border-emerald-400/60 bg-emerald-400/5",
    warning: "border-amber/60 bg-amber/5",
    lore: "border-violet-400/60 bg-violet-400/5",
};
const spacerClass = { sm: "h-4", md: "h-8", lg: "h-16" };
const activeTab = ref(0);

const bannerHeight = computed(
    () =>
        ({ sm: "h-16", md: "h-28", lg: "h-40" })[props.block.settings.height] ??
        "h-28",
);

const factsPreview = computed(() => {
    const chosen = (props.block.settings.fields ?? []).filter(
        (key) =>
            key in props.fieldLabels ||
            (props.preview.facts ?? []).some((f) => f.key === key),
    );
    const facts = props.preview.facts ?? [];
    const source = chosen.length
        ? chosen.map(
              (key) =>
                  facts.find((f) => f.key === key) ?? {
                      key,
                      label: props.fieldLabels[key] ?? key,
                      value: "…",
                  },
          )
        : facts;
    return source.slice(0, 6);
});

// Resolve {{ field }} tokens against the previewed entry's data (facts + computed vars), so the canvas
// shows the real per-entry text (e.g. a quote set to "{{ epigraph }}") exactly as the reader will.
const vars = computed(() =>
    buildVars(
        {
            title: props.preview.title,
            summary: props.preview.summary,
            kindLabel: props.preview.eyebrow,
            reading_minutes: props.preview.readingTime,
            word_count: props.preview.words,
        },
        props.preview.facts ?? [],
        { related: (props.preview.related ?? []).length },
    ),
);
const resolve = (text) => resolveVars(text, vars.value);

const meterColour = {
    teal: "bg-teal",
    amber: "bg-amber",
    red: "bg-red-500",
    green: "bg-emerald-500",
    violet: "bg-violet-500",
};
const meterPct = computed(() => {
    const value = Number(resolve(props.block.settings.value)) || 0;
    const max = Number(resolve(props.block.settings.max)) || 100;
    return Math.max(0, Math.min(100, (value / (max || 1)) * 100));
});

</script>

<template>
    <!-- Banner -->
    <div
        v-if="block.type === 'banner'"
        class="w-full bg-gradient-to-br from-[#243244] to-[#12161d] bg-cover bg-center"
        :class="bannerHeight"
        :style="
            preview.bannerUrl
                ? { backgroundImage: `url(${preview.bannerUrl})` }
                : {}
        "
    ></div>

    <!-- Header -->
    <div v-else-if="block.type === 'header'" class="space-y-1.5 px-6 py-4">
        <div
            v-if="block.settings.eyebrow"
            class="wb-eyebrow font-mono text-[10px] uppercase tracking-[0.2em] text-teal"
        >
            {{ preview.eyebrow }}
        </div>
        <div class="wb-title font-display text-[32px] leading-tight text-bright">
            {{ preview.title }}
        </div>
        <div
            v-if="block.settings.summary && preview.summary"
            class="wb-summary text-[15px] font-light italic text-[#b8bcc4]"
        >
            {{ preview.summary }}
        </div>
        <div
            v-if="block.settings.readingTime"
            class="wb-readingtime font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
        >
            {{ preview.readingTime }} min read · {{ preview.words }} words
        </div>
    </div>

    <!-- Facts -->
    <div v-else-if="block.type === 'facts'" class="px-6 py-4">
        <div
            class="rounded-lg border border-edge2 bg-card p-3.5"
            :class="block.settings.style === 'band' ? '' : 'max-w-sm'"
        >
            <div
                class="wb-facts-title mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
            >
                Quick facts
            </div>
            <div
                class="grid gap-x-6 gap-y-1.5"
                :style="{
                    gridTemplateColumns: `repeat(${block.settings.columns || 2}, minmax(0, 1fr))`,
                }"
            >
                <div
                    v-for="f in factsPreview"
                    :key="f.label"
                    class="wb-fact flex flex-col"
                >
                    <span
                        class="wb-fact-label font-mono text-[9px] uppercase tracking-[0.14em] text-faint"
                        >{{ f.label }}</span
                    >
                    <span class="wb-fact-value text-[14px] text-[#e6e2d8]">{{
                        f.value
                    }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div v-else-if="block.type === 'content'" class="px-6 py-4">
        <div :class="block.settings.width === 'wide' ? '' : 'max-w-[560px]'">
            <p
                v-if="preview.content"
                class="line-clamp-4 text-[14px] leading-relaxed text-[#c8ccd3]"
            >
                {{ preview.content }}
            </p>
            <div v-else class="space-y-2">
                <div class="h-2.5 w-full rounded bg-edge2"></div>
                <div class="h-2.5 w-11/12 rounded bg-edge2"></div>
                <div class="h-2.5 w-full rounded bg-edge2"></div>
                <div class="h-2.5 w-4/5 rounded bg-edge2"></div>
            </div>
        </div>
    </div>

    <!-- Related -->
    <div v-else-if="block.type === 'related'" class="px-6 py-4">
        <div
            class="wb-related-title mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
        >
            Related
        </div>
        <div
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 2}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="r in preview.related"
                :key="r.title"
                class="wb-card rounded-lg border border-edge2 bg-card p-2.5"
            >
                <div class="text-[13px] text-[#f3efe6]">{{ r.title }}</div>
                <div
                    class="font-mono text-[9px] uppercase tracking-wider text-faint"
                >
                    {{ r.kind }}
                </div>
            </div>
        </div>
    </div>

    <!-- Custom text -->
    <div v-else-if="block.type === 'text'" class="px-6 py-4 text-[#c8ccd3]">
        <RenderedContent
            v-if="block.settings.markdown"
            :content="resolve(block.settings.markdown)"
            :wiki-targets="[]"
            link-base="#"
            :single-column="true"
        />
        <div v-else class="text-[14px] text-faint">
            Custom text block — add Markdown in the settings panel.
        </div>
    </div>

    <!-- Hero (home) -->
    <div
        v-else-if="block.type === 'hero'"
        class="flex flex-col justify-end px-6 py-6"
        :class="block.settings.height === 'sm' ? 'h-40' : 'h-56'"
        style="background: linear-gradient(135deg, #1c2230, #0e1014 60%)"
    >
        <div class="wb-title font-display text-[30px] text-bright">
            {{ preview.name }}
        </div>
        <p
            class="wb-summary mt-1 max-w-[520px] text-[14px] italic text-[#c8ccd3]"
        >
            {{ preview.description }}
        </p>
        <div
            v-if="block.settings.stats !== false"
            class="mt-2 flex gap-4 font-mono text-[10px] uppercase tracking-wide text-faint"
        >
            <span>36 entries</span
            ><span>{{ preview.sections?.length }} sections</span>
        </div>
    </div>

    <!-- Featured (home) -->
    <div v-else-if="block.type === 'featured'" class="px-6 py-4">
        <div
            class="grid gap-3"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 3}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="c in preview.featured"
                :key="c.id"
                class="wb-card rounded-lg border border-edge2 bg-card p-3"
            >
                <div
                    class="font-mono text-[9px] uppercase tracking-wider text-teal"
                >
                    {{ c.kindLabel }}
                </div>
                <div class="font-display text-[15px] text-bright">
                    {{ c.title }}
                </div>
            </div>
        </div>
    </div>

    <!-- Section doors (home) -->
    <div v-else-if="block.type === 'sections'" class="px-6 py-4">
        <div
            class="grid gap-3"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 3}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="s in preview.sections"
                :key="s.label"
                class="wb-card rounded-lg border border-edge2 bg-card p-3"
            >
                <div class="font-display text-[16px] text-bright">
                    {{ s.label }}
                </div>
                <div class="font-mono text-[10px] text-faint">
                    {{ s.count }} entries
                </div>
            </div>
        </div>
    </div>

    <!-- Entries (home) -->
    <div v-else-if="block.type === 'recent'" class="px-6 py-4">
        <div
            v-if="block.settings.title"
            class="mb-2 font-mono text-[10px] uppercase tracking-wide text-teal"
        >
            {{ block.settings.title }}
        </div>
        <div
            class="grid gap-3"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 1}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="c in preview.recent"
                :key="c.id"
                class="wb-card overflow-hidden rounded-lg border border-edge2 bg-card"
            >
                <div
                    v-if="block.settings.showImage !== false"
                    class="h-16 bg-gradient-to-br from-[#243244] to-[#12161d]"
                    :style="c.card_url ? { backgroundImage: `url(${c.card_url})`, backgroundSize: 'cover' } : {}"
                ></div>
                <div class="p-2.5">
                    <div
                        v-if="block.settings.showKind !== false"
                        class="font-mono text-[9px] uppercase tracking-wider text-teal"
                    >
                        {{ c.kindLabel }}
                    </div>
                    <div class="text-[14px] text-ink">{{ c.title }}</div>
                    <p
                        v-if="block.settings.showSummary && c.summary"
                        class="mt-0.5 line-clamp-2 text-[12px] text-muted"
                    >
                        {{ c.summary }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Search bar (home) -->
    <div v-else-if="block.type === 'search'" class="px-6 py-5">
        <div
            class="wb-search-input mx-auto max-w-md rounded-lg border border-edge2 bg-card px-4 py-3 text-sm text-faint"
        >
            {{ block.settings.placeholder || "Search the world…" }}
        </div>
    </div>

    <!-- Campaign spotlight (home) -->
    <div v-else-if="block.type === 'spotlight'" class="px-6 py-4">
        <div
            class="mb-2 font-mono text-[10px] uppercase tracking-wide text-teal"
        >
            Campaigns
        </div>
        <div
            class="grid gap-3"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 2}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="n in Number(block.settings.columns) || 2"
                :key="n"
                class="wb-card rounded-lg border border-edge2 bg-card p-3"
            >
                <div class="font-display text-[16px] text-bright">
                    Campaign {{ n }}
                </div>
                <div
                    class="mt-1 font-mono text-[9px] uppercase tracking-wider text-faint"
                >
                    12 sessions
                </div>
            </div>
        </div>
    </div>

    <!-- Session recaps (home) -->
    <div v-else-if="block.type === 'recaps'" class="px-6 py-4">
        <div
            class="mb-2 font-mono text-[10px] uppercase tracking-wide text-teal"
        >
            Latest recaps
        </div>
        <div class="flex flex-col gap-2">
            <div
                v-for="n in Number(block.settings.count) || 3"
                :key="n"
                class="wb-card rounded-lg border border-edge2 bg-card p-2.5"
            >
                <div class="font-serif text-[14px] text-[#f3efe6]">
                    Session {{ n }}
                </div>
                <div
                    class="font-mono text-[9px] uppercase tracking-wider text-teal"
                >
                    The campaign
                </div>
            </div>
        </div>
    </div>

    <!-- Section heading (archive) -->
    <div v-else-if="block.type === 'heading'" class="px-6 py-4">
        <div class="wb-title font-display text-[30px] text-bright">
            {{ preview.sectionLabel }}
        </div>
        <div v-if="block.settings.count !== false" class="mt-0.5 text-sm text-muted">
            {{ (preview.items || []).length }} entries
        </div>
    </div>

    <!-- Entry grid (archive) -->
    <div v-else-if="block.type === 'grid'" class="px-6 py-4">
        <div
            class="grid gap-3"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 3}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="c in preview.items"
                :key="c.id"
                class="wb-card rounded-lg border border-edge2 bg-card p-3"
            >
                <div
                    class="font-mono text-[9px] uppercase tracking-wider text-teal"
                >
                    {{ c.kindLabel }}
                </div>
                <div class="font-serif text-[15px] text-[#f3efe6]">
                    {{ c.title }}
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & sort bar (archive) -->
    <div v-else-if="block.type === 'filter'" class="px-6 py-4">
        <div class="flex flex-wrap gap-2">
            <div
                class="wb-filter-input min-w-0 flex-1 rounded-lg border border-edge2 bg-card px-3 py-2 text-sm text-faint"
            >
                {{ block.settings.placeholder || "Search…" }}
            </div>
            <div
                v-if="block.settings.sort !== false"
                class="rounded-lg border border-edge2 bg-card px-3 py-2 text-sm text-muted"
            >
                Recently updated ⌄
            </div>
        </div>
    </div>

    <!-- Filter chips (archive) -->
    <div v-else-if="block.type === 'facets'" class="px-6 py-4">
        <div class="flex flex-wrap gap-1.5">
            <span
                v-if="block.settings.showKinds !== false"
                class="wb-facet wb-facet--on rounded-full border border-teal bg-teal/15 px-2.5 py-1 text-[12px] text-teal"
                >Locations</span
            >
            <span
                v-if="block.settings.showKinds !== false"
                class="wb-facet rounded-full border border-edge2 px-2.5 py-1 text-[12px] text-muted"
                >People</span
            >
            <span
                v-for="tag in ['coastal', 'ruined', 'sacred']"
                v-show="block.settings.showTags !== false"
                :key="tag"
                class="wb-facet rounded-full border border-edge2 px-2.5 py-1 font-mono text-[11px] text-faint"
                >#{{ tag }}</span
            >
        </div>
    </div>

    <!-- Table view (archive) -->
    <div v-else-if="block.type === 'table'" class="px-6 py-4">
        <table class="wb-table w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-edge2 text-faint">
                    <th class="py-1.5 pr-3 font-mono text-[10px] uppercase tracking-wider">
                        Title
                    </th>
                    <th
                        v-if="block.settings.showKind !== false"
                        class="py-1.5 pr-3 font-mono text-[10px] uppercase tracking-wider"
                    >
                        Kind
                    </th>
                    <th
                        v-if="block.settings.summary !== false"
                        class="py-1.5 pr-3 font-mono text-[10px] uppercase tracking-wider"
                    >
                        Summary
                    </th>
                    <th
                        v-for="key in block.settings.fields || []"
                        :key="key"
                        class="py-1.5 pr-3 font-mono text-[10px] uppercase tracking-wider"
                    >
                        {{ fieldLabels[key] || key }}
                    </th>
                    <th
                        v-if="block.settings.showUpdated !== false"
                        class="py-1.5 pr-3 font-mono text-[10px] uppercase tracking-wider"
                    >
                        Updated
                    </th>
                    <th
                        v-if="block.settings.showCreated === true"
                        class="py-1.5 font-mono text-[10px] uppercase tracking-wider"
                    >
                        Created
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="c in preview.items"
                    :key="c.id"
                    class="border-b border-edge2/60"
                >
                    <td class="py-1.5 pr-3 text-ink">{{ c.title }}</td>
                    <td
                        v-if="block.settings.showKind !== false"
                        class="py-1.5 pr-3 text-muted"
                    >
                        {{ c.kindLabel }}
                    </td>
                    <td
                        v-if="block.settings.summary !== false"
                        class="max-w-[24ch] truncate py-1.5 text-muted"
                    >
                        {{ c.summary }}
                    </td>
                    <td
                        v-for="key in block.settings.fields || []"
                        :key="key"
                        class="py-1.5 pr-3 text-muted"
                    >
                        {{ (c.fields || {})[key] || "…" }}
                    </td>
                    <td
                        v-if="block.settings.showUpdated !== false"
                        class="py-1.5 pr-3 font-mono text-[10px] text-faint"
                    >
                        —
                    </td>
                    <td
                        v-if="block.settings.showCreated === true"
                        class="py-1.5 font-mono text-[10px] text-faint"
                    >
                        —
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- A–Z index (archive) -->
    <div v-else-if="block.type === 'index'" class="px-6 py-4">
        <div
            v-if="block.settings.jumpbar !== false"
            class="mb-3 flex flex-wrap gap-1.5"
        >
            <span
                v-for="l in ['A', 'B', 'C', 'D']"
                :key="l"
                class="rounded border border-edge2 px-2 py-0.5 font-mono text-[11px] text-muted"
                >{{ l }}</span
            >
        </div>
        <div class="wb-index-letter mb-1 border-b border-edge2 pb-1 font-display text-xl text-teal">
            A
        </div>
        <ul class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
            <li v-for="c in preview.items" :key="c.id" class="text-ink">
                {{ c.title }}
            </li>
        </ul>
    </div>

    <!-- Reference (compendium embed) -->
    <div v-else-if="block.type === 'reference'" class="px-6 py-4">
        <div
            class="rounded-lg border border-[#9c9b6d] bg-[#f5ecd7] px-4 py-3 text-[#3a2c14]"
        >
            <div class="font-display text-[15px] text-[#7a200c]">
                {{ refItem ? refItem.name : "No reference chosen" }}
            </div>
            <div class="text-[10px] uppercase tracking-wide text-[#8a6d3b]">
                {{ refItem ? refItem.type : "Reference" }}
            </div>
        </div>
    </div>

    <!-- Reusable block -->
    <div v-else-if="block.type === 'reusable'" class="px-6 py-4">
        <div
            class="flex items-center gap-2 rounded-lg border border-dashed border-edge3 bg-[#191c22] px-4 py-3 text-[13px] text-muted"
        >
            <span>♻</span>
            <span>{{
                reusableItem
                    ? reusableItem.name
                    : "No reusable block chosen"
            }}</span>
        </div>
    </div>

    <!-- Portrait / avatar -->
    <div v-else-if="block.type === 'avatar'" class="flex justify-center px-6 py-4">
        <div
            class="h-24 w-24 border-2 border-edge2 bg-gradient-to-br from-[#243244] to-[#12161d]"
            :class="
                block.settings.shape === 'square'
                    ? ''
                    : block.settings.shape === 'rounded'
                      ? 'rounded-xl'
                      : 'rounded-full'
            "
            :style="
                preview.imageUrl
                    ? {
                          backgroundImage: `url(${preview.imageUrl})`,
                          backgroundSize: 'cover',
                          backgroundPosition: 'center',
                      }
                    : {}
            "
        ></div>
    </div>

    <!-- Reader notes -->
    <div v-else-if="block.type === 'notes'" class="px-6 py-4">
        <div class="rounded-lg border border-[#2f5457] bg-[#15252a] p-3">
            <div
                class="font-mono text-[10px] uppercase tracking-wider text-teal"
            >
                My notes
            </div>
            <div class="mt-1.5 h-8 rounded border border-[#2f5457] bg-[#101c20]"></div>
            <div class="mt-1.5 h-6 rounded bg-teal/70"></div>
        </div>
    </div>

    <!-- Map -->
    <div v-else-if="block.type === 'map'" class="px-6 py-4">
        <div
            class="flex items-center justify-center rounded-lg border border-edge2 bg-[#0b0d11] text-faint"
            :class="{ sm: 'h-24', md: 'h-36', lg: 'h-48' }[block.settings.height] || 'h-36'"
        >
            <span class="text-[13px]">🗺 {{ mapItem ? mapItem.name : "No map chosen" }}</span>
        </div>
    </div>

    <!-- Meter -->
    <div v-else-if="block.type === 'meter'" class="px-6 py-4">
        <div
            v-if="block.settings.label || block.settings.value"
            class="mb-1 flex items-baseline justify-between gap-3"
        >
            <span class="text-sm text-ink">{{
                resolve(block.settings.label)
            }}</span>
            <span class="font-mono text-[12px] text-faint"
                >{{ resolve(block.settings.value)
                }}{{ block.settings.suffix }}</span
            >
        </div>
        <div
            class="wb-meter-track h-2.5 w-full overflow-hidden rounded-full bg-edge2"
        >
            <div
                class="wb-meter-bar h-full rounded-full"
                :class="meterColour[block.settings.colour] || meterColour.teal"
                :style="{ width: meterPct + '%' }"
            ></div>
        </div>
    </div>

    <!-- FAQ -->
    <div v-else-if="block.type === 'faq'" class="space-y-1.5 px-6 py-4">
        <div
            v-for="(item, i) in block.settings.items || []"
            :key="i"
            class="rounded-lg border border-edge2 bg-card px-4 py-2.5"
        >
            <div class="font-display text-[15px] text-bright">
                ▸ {{ resolve(item.question) || "Question…" }}
            </div>
        </div>
    </div>

    <!-- Random entry -->
    <div v-else-if="block.type === 'random'" class="px-6 py-4">
        <span
            class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium"
            :class="
                block.settings.style === 'outline'
                    ? 'border border-teal text-teal'
                    : 'bg-teal text-night'
            "
            >🎲 {{ resolve(block.settings.label) || "Show me something" }}</span
        >
    </div>

    <!-- Connections -->
    <div v-else-if="block.type === 'connections'" class="px-6 py-4">
        <div
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 2}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="n in Number(block.settings.columns) || 2"
                :key="n"
                class="wb-card flex items-center gap-2.5 rounded-lg border border-edge2 bg-card p-2.5"
            >
                <div class="min-w-0">
                    <div
                        v-if="block.settings.showRelationship !== false"
                        class="wb-connection-rel font-mono text-[9px] uppercase tracking-wider text-teal"
                    >
                        related to
                    </div>
                    <div class="text-[14px] text-ink">A linked entry</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Comparison table -->
    <div v-else-if="block.type === 'comparison'" class="px-6 py-4">
        <table class="w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-edge2">
                    <th class="py-1.5 pr-3"></th>
                    <th
                        v-for="n in (block.settings.ids || []).length || 2"
                        :key="n"
                        class="py-1.5 pr-3 font-display text-[14px] text-bright"
                    >
                        Entry {{ n }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in ['Population', 'Ruler']"
                    :key="row"
                    class="border-b border-edge2/60"
                >
                    <td
                        class="py-1.5 pr-3 font-mono text-[10px] uppercase tracking-wider text-faint"
                    >
                        {{ row }}
                    </td>
                    <td
                        v-for="n in (block.settings.ids || []).length || 2"
                        :key="n"
                        class="py-1.5 pr-3 text-ink"
                    >
                        —
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Next session (home) -->
    <div v-else-if="block.type === 'nextsession'" class="px-6 py-4">
        <div class="rounded-lg border border-edge2 bg-card p-4">
            <div
                class="mb-1 font-mono text-[10px] uppercase tracking-[0.18em] text-teal"
            >
                {{ block.settings.title || "Next session" }}
            </div>
            <div class="flex gap-4">
                <div
                    v-for="u in ['Days', 'Hrs', 'Min']"
                    :key="u"
                    class="text-center"
                >
                    <div class="font-display text-[22px] text-bright">00</div>
                    <div
                        class="font-mono text-[9px] uppercase tracking-wider text-faint"
                    >
                        {{ u }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Callout -->
    <div v-else-if="block.type === 'callout'" class="px-6 py-4">
        <div
            class="rounded-lg border-l-4 bg-card p-3.5"
            :class="calloutClass[block.settings.style] || calloutClass.info"
        >
            <div
                v-if="block.settings.title"
                class="wb-callout-title mb-1 font-display text-[15px] text-bright"
            >
                {{ resolve(block.settings.title) }}
            </div>
            <RenderedContent
                v-if="block.settings.markdown"
                :content="resolve(block.settings.markdown)"
                :wiki-targets="[]"
                link-base="#"
                :single-column="true"
            />
        </div>
    </div>

    <!-- Read-aloud -->
    <div v-else-if="block.type === 'readaloud'" class="px-6 py-4">
        <div
            class="rounded-lg border border-edge2 bg-[#14181d] p-3.5 italic text-[#c8ccd3]"
        >
            <RenderedContent
                v-if="block.settings.markdown"
                :content="resolve(block.settings.markdown)"
                :wiki-targets="[]"
                link-base="#"
                :single-column="true"
            />
            <span v-else class="text-[13px] text-faint">Read-aloud text…</span>
        </div>
    </div>

    <!-- GM secret -->
    <div v-else-if="block.type === 'secret'" class="px-6 py-4">
        <div
            class="rounded-lg border border-dashed border-amber/50 bg-amber/5 p-3.5"
        >
            <div
                class="mb-1 font-mono text-[10px] uppercase tracking-[0.16em] text-amber"
            >
                GM only
            </div>
            <RenderedContent
                v-if="block.settings.markdown"
                :content="resolve(block.settings.markdown)"
                :wiki-targets="[]"
                link-base="#"
                :single-column="true"
            />
        </div>
    </div>

    <!-- Quote -->
    <div v-else-if="block.type === 'quote'" class="px-6 py-4">
        <blockquote class="border-l-2 border-teal pl-4">
            <p
                class="text-[19px] font-light italic leading-snug text-[#e6e2d8]"
            >
                {{ resolve(block.settings.text) || "A memorable line…" }}
            </p>
            <cite
                v-if="block.settings.attribution"
                class="wb-quote-cite mt-1 block font-mono text-[11px] uppercase not-italic tracking-wide text-faint"
                >— {{ resolve(block.settings.attribution) }}</cite
            >
        </blockquote>
    </div>

    <!-- Button -->
    <div v-else-if="block.type === 'button'" class="px-6 py-4">
        <span
            class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium"
            :class="
                block.settings.style === 'outline'
                    ? 'border border-teal text-teal'
                    : 'bg-teal text-night'
            "
            >{{ resolve(block.settings.label) || "Read more" }}</span
        >
    </div>

    <!-- Spacer -->
    <div v-else-if="block.type === 'spacer'" class="px-6">
        <div
            class="my-1 rounded border border-dashed border-edge3"
            :class="spacerClass[block.settings.size] || spacerClass.md"
        ></div>
    </div>

    <!-- Table of contents -->
    <div v-else-if="block.type === 'toc'" class="px-6 py-4">
        <div class="rounded-lg border border-edge2 bg-card p-3.5">
            <div
                class="mb-1 font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
            >
                Contents
            </div>
            <div class="text-[12px] text-faint">
                Built from this entry’s headings.
            </div>
        </div>
    </div>

    <!-- Stat highlights -->
    <div v-else-if="block.type === 'stats'" class="px-6 py-4">
        <div
            class="grid gap-3"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 3}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="(s, i) in block.settings.items || []"
                :key="i"
                class="wb-stat rounded-lg border border-edge2 bg-card p-3 text-center"
            >
                <div class="wb-stat-value font-display text-[22px] text-bright">
                    {{ s.value || "—" }}
                </div>
                <div
                    class="wb-stat-label font-mono text-[9px] uppercase tracking-wider text-faint"
                >
                    {{ s.label || "Label" }}
                </div>
            </div>
        </div>
    </div>

    <!-- Image -->
    <figure v-else-if="block.type === 'image'" class="px-6 py-4">
        <img
            v-if="block.settings.url"
            :src="block.settings.url"
            alt=""
            class="w-full rounded-lg"
        />
        <div
            v-else
            class="flex h-32 items-center justify-center rounded-lg border border-dashed border-edge3 text-[12px] text-faint"
        >
            Choose an image
        </div>
        <figcaption
            v-if="block.settings.caption"
            class="mt-1.5 text-center text-xs italic text-muted"
        >
            {{ resolve(block.settings.caption) }}
        </figcaption>
    </figure>

    <!-- Accordion -->
    <div v-else-if="block.type === 'accordion'" class="space-y-1.5 px-6 py-4">
        <div
            v-for="(p, i) in block.settings.panes || []"
            :key="i"
            class="rounded-lg border border-edge2 bg-card px-3.5 py-2.5"
        >
            <div class="font-display text-[14px] text-bright">
                ▸ {{ p.title || "Untitled" }}
            </div>
        </div>
    </div>

    <!-- Video -->
    <div v-else-if="block.type === 'video'" class="px-6 py-4">
        <div
            class="flex aspect-video items-center justify-center rounded-lg border border-edge2 bg-[#0b0d11] text-faint"
        >
            <span v-if="block.settings.url" class="text-[13px]">▶ Video</span>
            <span v-else class="text-[12px]">Paste a YouTube / Vimeo URL</span>
        </div>
    </div>

    <!-- Tabs -->
    <div v-else-if="block.type === 'tabs'" class="px-6 py-4">
        <div class="mb-2 flex flex-wrap gap-1 border-b border-edge2">
            <button
                v-for="(p, i) in block.settings.panes || []"
                :key="i"
                class="wb-tab -mb-px border-b-2 px-3 py-1.5 text-sm transition"
                :class="
                    activeTab === i
                        ? 'wb-tab--active border-teal text-bright'
                        : 'border-transparent text-muted'
                "
                @click.stop="activeTab = i"
            >
                {{ p.title || `Tab ${i + 1}` }}
            </button>
        </div>
        <RenderedContent
            v-if="(block.settings.panes || [])[activeTab]?.markdown"
            :key="activeTab"
            :content="block.settings.panes[activeTab].markdown"
            :wiki-targets="[]"
            link-base="#"
            :single-column="true"
        />
    </div>

    <!-- Gallery -->
    <div v-else-if="block.type === 'gallery'" class="px-6 py-4">
        <div
            v-if="(block.settings.images || []).length"
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 3}, minmax(0, 1fr))`,
            }"
        >
            <img
                v-for="(url, i) in block.settings.images"
                :key="i"
                :src="url"
                alt=""
                class="h-20 w-full rounded object-cover"
            />
        </div>
        <div
            v-else
            class="flex h-24 items-center justify-center rounded-lg border border-dashed border-edge3 text-[12px] text-faint"
        >
            Add images
        </div>
    </div>

    <!-- Mini-timeline -->
    <div v-else-if="block.type === 'events'" class="px-6 py-4">
        <div class="flex flex-col gap-2 border-l border-edge2 pl-4">
            <div
                v-for="(e, i) in block.settings.events || []"
                :key="i"
                class="flex items-start gap-3"
            >
                <div
                    class="wb-event-when w-14 shrink-0 pt-0.5 text-right font-mono text-[11px] text-teal"
                >
                    {{ e.when || "—" }}
                </div>
                <div
                    class="flex-1 rounded border border-edge2 bg-card px-2.5 py-1.5"
                >
                    <div class="font-display text-[14px] text-[#f3efe6]">
                        {{ e.title || "Event" }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Linked entries -->
    <div v-else-if="block.type === 'linked'" class="px-6 py-4">
        <div
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${block.settings.columns || 2}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="(id, i) in block.settings.ids || []"
                :key="i"
                class="wb-card rounded-lg border border-edge2 bg-card p-2.5 text-[13px] text-ink"
            >
                Linked entry
            </div>
            <div
                v-if="!(block.settings.ids || []).length"
                class="text-[12px] text-faint"
            >
                Pick entries in the settings panel.
            </div>
        </div>
    </div>

    <!-- Divider -->
    <div v-else-if="block.type === 'divider'" class="px-6 py-3">
        <div class="h-px w-full bg-edge2"></div>
    </div>
</template>
