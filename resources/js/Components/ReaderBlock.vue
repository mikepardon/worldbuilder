<script setup>
// Renders one template block on the reader, using the entry's context. Used for the child blocks inside
// a Columns block (header, quick facts, content, related, custom text, divider — no banner/columns).
import ReaderBlock from "@/Components/ReaderBlock.vue";
import RenderedContent from "@/Components/RenderedContent.vue";
import MapViewer from "@/Components/MapViewer.vue";
import {
    buildVars,
    deviceClass,
    resolveVars,
    ruleAllows,
} from "@/lib/templateVars";
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    block: { type: Object, required: true },
    // The entry being rendered. Null when this block is on a home/archive page (those have no single entry),
    // in which case only the self-contained "common" blocks are used and variables resolve to nothing.
    entry: { type: Object, default: null },
    // The entry's resolved quick facts ([{ key, label, value, link, items }]).
    facts: { type: Array, default: () => [] },
    related: { type: Array, default: () => [] },
    // The entry's heading outline ([{ text, level }]) for the Table-of-contents block.
    toc: { type: Array, default: () => [] },
    wikiTargets: { type: Array, default: () => [] },
    embeds: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    gm: { type: Boolean, default: false },
    campaignSlug: { type: String, default: "" },
    // { id: card } for the "linked entries" block.
    linkedCards: { type: Object, default: () => ({}) },
    // { id: MapViewer payload } for the "map" block.
    maps: { type: Object, default: () => ({}) },
    // This entry's neighbours for the "connections" block.
    connections: { type: Array, default: () => [] },
    // { id: {title, facts, …} } for the "comparison" block.
    comparison: { type: Object, default: () => ({}) },
    // Extra {{ item.* }} variables injected when this block is a child of a repeater iteration.
    itemScope: { type: Object, default: () => ({}) },
    // { id: [blocks] } — resolved block sets for "reusable" blocks.
    reusableBlocks: { type: Object, default: () => ({}) },
});

const mapHeight = { sm: "h-[280px]", md: "h-[440px]", lg: "h-[640px]" };

const calloutClass = {
    info: "border-teal/60 bg-teal/5",
    tip: "border-emerald-400/60 bg-emerald-400/5",
    warning: "border-amber/60 bg-amber/5",
    lore: "border-violet-400/60 bg-violet-400/5",
};
const spacerClass = { sm: "h-4", md: "h-8", lg: "h-16" };

// Active tab index for a Tabs block (per component instance).
const activeTab = ref(0);

// Turn a YouTube/Vimeo URL into a safe embed URL, or null (only these two hosts are allowed).
const videoEmbed = computed(() => {
    const url = props.block.settings?.url ?? "";
    const yt = url.match(
        /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w-]{11})/,
    );
    if (yt) return `https://www.youtube.com/embed/${yt[1]}`;
    const vimeo = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (vimeo) return `https://player.vimeo.com/video/${vimeo[1]}`;
    return null;
});

// The cards for a "linked entries" block's chosen ids, in order, that this viewer may see.
const linkedItems = computed(() =>
    (props.block.settings?.ids ?? [])
        .map((id) => props.linkedCards[id])
        .filter(Boolean),
);

// The resolved MapViewer payload for a "map" block, or undefined if the map is missing/hidden.
const mapPayload = computed(() => props.maps[props.block.settings?.mapId]);

// Meter: resolve {{ variables }} in the value/max, then clamp to a 0–100% fill.
const meterColour = {
    teal: "bg-teal",
    amber: "bg-amber",
    red: "bg-red-500",
    green: "bg-emerald-500",
    violet: "bg-violet-500",
};
const meterValue = computed(() => Number(resolve(props.block.settings?.value)) || 0);
const meterMax = computed(() => Number(resolve(props.block.settings?.max)) || 100);
const meterPct = computed(() =>
    Math.max(0, Math.min(100, (meterValue.value / (meterMax.value || 1)) * 100)),
);

// Random-entry button href, carrying any chosen kind filter.
const randomHref = computed(() => {
    const kinds = props.block.settings?.kinds ?? [];
    const query = kinds.length ? `?kinds=${kinds.join(",")}` : "";
    return `/w/${props.campaignSlug}/random${query}`;
});

// Comparison: the chosen entries (in order) with the union of their fact labels as rows.
const comparisonEntries = computed(() =>
    (props.block.settings?.ids ?? [])
        .map((id) => props.comparison[id])
        .filter(Boolean),
);
const comparisonRows = computed(() => {
    const labels = [];
    for (const entry of comparisonEntries.value) {
        for (const fact of entry.facts ?? []) {
            if (!labels.includes(fact.label)) labels.push(fact.label);
        }
    }
    return labels;
});
const factValue = (entry, label) =>
    (entry.facts ?? []).find((f) => f.label === label)?.value ?? "—";

// FAQ: only rows with an actual question.
const faqItems = computed(() =>
    (props.block.settings?.items ?? []).filter((i) => (i.question ?? "").trim()),
);

// Conditional visibility: a block can carry a `visibleIf` rule evaluated against its variables (entry +
// any repeater item scope).
const blockVisible = computed(() =>
    ruleAllows(props.block.visibleIf, scopedVars.value),
);

// Repeater: the source items (this entry's related entries or its connections), each turned into a
// variable scope ({{ item.title }}, {{ item.url }}, …) that the repeater's child blocks render against.
const repeaterItems = computed(() => {
    const source =
        props.block.settings?.source === "connections"
            ? props.connections
            : props.related;
    return (source ?? []).map((item) => ({
        "item.title": item.title ?? "",
        "item.summary": item.summary ?? "",
        "item.kind": item.kindLabel ?? "",
        "item.label": item.label ?? "",
        "item.url": `/w/${props.campaignSlug}/${item.type}/${item.slug}`,
    }));
});
const repeaterBlocks = computed(() => props.block.settings?.blocks ?? []);
// The resolved blocks of a "reusable" block, in order.
const reusableChildren = computed(
    () => props.reusableBlocks[props.block.settings?.refId] ?? [],
);
const emit = defineEmits(["reveal"]);

const settings = computed(() => props.block.settings ?? {});
// A facts child can restrict/order the entry's facts by its own chosen fields.
const shownFacts = computed(() => {
    const fields = settings.value.fields ?? [];
    if (!fields.length) return props.facts;
    return fields
        .map((key) => props.facts.find((f) => f.key === key))
        .filter(Boolean);
});
const href = (link) =>
    `/w/${props.campaignSlug}/${link.type}/${link.slug}`;

// Per-entry variables a template block can pull in: the entry's quick-facts fields by key, plus a few
// entry basics. So one template can carry a quote/button/callout that reads differently on each entry
// (e.g. a quote block set to "{{ epigraph }}" shows each location's own epigraph field).
const vars = computed(() =>
    buildVars(props.entry, props.facts, {
        related: props.related.length,
        connections: props.connections.length,
    }),
);
// The entry's variables plus any per-item ({{ item.* }}) variables from an enclosing repeater.
const scopedVars = computed(() => ({ ...vars.value, ...props.itemScope }));
// Resolve {{ variables }} (with `| fallback`) in a string; extra vars merge on top.
const resolve = (text, extra) => resolveVars(text, scopedVars.value, extra);
// Only allow safe protocols in an (interpolated) button URL; block javascript:, data:, etc.
const safeUrl = (url) => {
    const value = resolve(url);
    if (typeof value !== "string") return undefined;
    return /^(https?:|mailto:|\/|#|\.)/i.test(value.trim()) ? value : undefined;
};
</script>

<template>
  <template v-if="blockVisible">
    <!-- Title & summary -->
    <div v-if="block.type === 'header'" class="wb-header flex flex-col gap-2">
        <div
            v-if="settings.eyebrow !== false"
            class="wb-eyebrow font-mono text-[10px] uppercase tracking-[0.2em] text-teal"
        >
            {{ entry.kindLabel }}
        </div>
        <div
            class="wb-title font-display text-[28px] leading-tight text-bright"
        >
            {{ entry.title }}
        </div>
        <div
            v-if="settings.summary !== false && entry.summary"
            class="wb-summary text-[15px] font-light italic text-[#b8bcc4]"
        >
            {{ entry.summary }}
        </div>
        <div
            v-if="settings.readingTime !== false && entry.word_count"
            class="wb-readingtime font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
        >
            {{ entry.reading_minutes }} min read
        </div>
    </div>

    <!-- Quick facts -->
    <div
        v-else-if="block.type === 'facts' && shownFacts.length"
        class="wb-facts flex flex-col gap-2.5 rounded-lg border border-edge2 bg-card p-3.5"
    >
        <div
            class="wb-facts-title font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
        >
            Quick facts
        </div>
        <div
            v-for="f in shownFacts"
            :key="f.label"
            class="wb-fact flex justify-between gap-3 text-[14px]"
        >
            <span class="wb-fact-label text-[#8a909b]">{{ f.label }}</span>
            <span class="wb-fact-value text-right text-[#c8ccd3]">
                <template v-for="(item, i) in f.items" :key="i">
                    <Link
                        v-if="item.link"
                        :href="href(item.link)"
                        class="text-teal hover:underline"
                        >{{ item.value }}</Link
                    ><span v-else>{{ item.value }}</span
                    ><span v-if="i < f.items.length - 1">, </span>
                </template>
            </span>
        </div>
    </div>

    <!-- Content -->
    <RenderedContent
        v-else-if="block.type === 'content'"
        :content="entry.content"
        :embeds="embeds"
        :spells="spells"
        :gm="gm"
        :wiki-targets="wikiTargets"
        :link-base="`/w/${campaignSlug}/`"
        :single-column="true"
        @reveal="emit('reveal', $event)"
    />

    <!-- Related -->
    <div v-else-if="block.type === 'related' && related.length" class="wb-related">
        <div
            class="wb-related-title mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
        >
            Related
        </div>
        <div
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${Number(settings.columns) || 2}, minmax(0, 1fr))`,
            }"
        >
            <Link
                v-for="r in related"
                :key="r.id"
                :href="`/w/${campaignSlug}/${r.type}/${r.slug}`"
                class="wb-card flex items-center gap-2.5 rounded-lg border border-edge2 bg-card p-2.5 transition hover:border-teal"
            >
                <div class="min-w-0">
                    <div class="truncate text-[14px] text-[#f3efe6]">
                        {{ r.title }}
                    </div>
                    <div
                        class="font-mono text-[9px] uppercase tracking-wider text-faint"
                    >
                        {{ r.kindLabel }}
                    </div>
                </div>
            </Link>
        </div>
    </div>

    <!-- Custom text -->
    <RenderedContent
        v-else-if="block.type === 'text' && settings.markdown"
        :content="resolve(settings.markdown)"
        :embeds="embeds"
        :spells="spells"
        :gm="gm"
        :wiki-targets="wikiTargets"
        :link-base="`/w/${campaignSlug}/`"
        :single-column="true"
        @reveal="emit('reveal', $event)"
    />

    <!-- Reference: embed a compendium entry via its embed token -->
    <RenderedContent
        v-else-if="block.type === 'reference' && settings.refId"
        :content="`{{item=${settings.refId}}}`"
        :embeds="embeds"
        :spells="spells"
        :gm="gm"
        :wiki-targets="wikiTargets"
        :link-base="`/w/${campaignSlug}/`"
        :single-column="true"
        @reveal="emit('reveal', $event)"
    />

    <!-- Callout -->
    <div
        v-else-if="block.type === 'callout'"
        class="rounded-lg border-l-4 bg-card p-4"
        :class="calloutClass[settings.style] || calloutClass.info"
    >
        <div
            v-if="settings.title"
            class="wb-callout-title mb-1 font-display text-[15px] text-bright"
        >
            {{ resolve(settings.title) }}
        </div>
        <RenderedContent
            v-if="settings.markdown"
            :content="resolve(settings.markdown)"
            :embeds="embeds"
            :spells="spells"
            :gm="gm"
            :wiki-targets="wikiTargets"
            :link-base="`/w/${campaignSlug}/`"
            :single-column="true"
            @reveal="emit('reveal', $event)"
        />
    </div>

    <!-- Read-aloud -->
    <div
        v-else-if="block.type === 'readaloud' && settings.markdown"
        class="rounded-lg border border-edge2 bg-[#14181d] p-4 italic"
    >
        <RenderedContent
            :content="resolve(settings.markdown)"
            :wiki-targets="wikiTargets"
            :link-base="`/w/${campaignSlug}/`"
            :single-column="true"
        />
    </div>

    <!-- GM secret (only to GMs) -->
    <div
        v-else-if="block.type === 'secret' && gm && settings.markdown"
        class="rounded-lg border border-dashed border-amber/50 bg-amber/5 p-4"
    >
        <div
            class="mb-1 font-mono text-[10px] uppercase tracking-[0.16em] text-amber"
        >
            GM only
        </div>
        <RenderedContent
            :content="resolve(settings.markdown)"
            :embeds="embeds"
            :spells="spells"
            :gm="gm"
            :wiki-targets="wikiTargets"
            :link-base="`/w/${campaignSlug}/`"
            :single-column="true"
            @reveal="emit('reveal', $event)"
        />
    </div>

    <!-- Quote -->
    <blockquote
        v-else-if="block.type === 'quote' && resolve(settings.text)"
        class="border-l-2 border-teal pl-5"
    >
        <p class="text-[20px] font-light italic leading-snug text-[#e6e2d8]">
            {{ resolve(settings.text) }}
        </p>
        <cite
            v-if="settings.attribution"
            class="wb-quote-cite mt-1 block font-mono text-[11px] uppercase tracking-wide not-italic text-faint"
            >— {{ resolve(settings.attribution) }}</cite
        >
    </blockquote>

    <!-- Button -->
    <div v-else-if="block.type === 'button' && safeUrl(settings.url)">
        <a
            :href="safeUrl(settings.url)"
            class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition"
            :class="
                settings.style === 'outline'
                    ? 'border border-teal text-teal hover:bg-teal/10'
                    : 'bg-teal text-night hover:bg-teal/90'
            "
        >
            {{ resolve(settings.label) || "Read more" }}
        </a>
    </div>

    <!-- Spacer -->
    <div
        v-else-if="block.type === 'spacer'"
        :class="spacerClass[settings.size] || spacerClass.md"
    ></div>

    <!-- Table of contents -->
    <div
        v-else-if="block.type === 'toc' && toc.length"
        class="rounded-lg border border-edge2 bg-card p-4"
    >
        <div
            class="mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
        >
            Contents
        </div>
        <ul class="flex flex-col gap-1">
            <li
                v-for="h in toc"
                :key="h.index"
                :style="{ paddingLeft: `${(h.level - 2) * 14}px` }"
                class="text-sm text-[#c8ccd3]"
            >
                {{ h.text }}
            </li>
        </ul>
    </div>

    <!-- Stat highlights -->
    <div
        v-else-if="block.type === 'stats'"
        class="grid gap-3"
        :style="{
            gridTemplateColumns: `repeat(${settings.columns || 3}, minmax(0, 1fr))`,
        }"
    >
        <div
            v-for="(s, i) in settings.items || []"
            :key="i"
            class="wb-stat rounded-lg border border-edge2 bg-card p-3 text-center"
        >
            <div class="wb-stat-value font-display text-[24px] text-bright">
                {{ s.value }}
            </div>
            <div
                class="wb-stat-label font-mono text-[9px] uppercase tracking-wider text-faint"
            >
                {{ s.label }}
            </div>
        </div>
    </div>

    <!-- Image -->
    <figure v-else-if="block.type === 'image' && settings.url">
        <img
            :src="settings.url"
            :alt="settings.caption || ''"
            class="w-full rounded-lg"
        />
        <figcaption
            v-if="settings.caption"
            class="mt-1.5 text-center text-xs italic text-muted"
        >
            {{ resolve(settings.caption) }}
        </figcaption>
    </figure>

    <!-- Accordion (native collapsibles) -->
    <div v-else-if="block.type === 'accordion'" class="flex flex-col gap-2">
        <details
            v-for="(p, i) in settings.panes || []"
            :key="i"
            class="rounded-lg border border-edge2 bg-card"
        >
            <summary
                class="cursor-pointer px-4 py-2.5 font-display text-[15px] text-bright"
            >
                {{ p.title || "Untitled" }}
            </summary>
            <div class="px-4 pb-3">
                <RenderedContent
                    v-if="p.markdown"
                    :content="p.markdown"
                    :embeds="embeds"
                    :spells="spells"
                    :gm="gm"
                    :wiki-targets="wikiTargets"
                    :link-base="`/w/${campaignSlug}/`"
                    :single-column="true"
                    @reveal="emit('reveal', $event)"
                />
            </div>
        </details>
    </div>

    <!-- Video (YouTube / Vimeo) -->
    <div
        v-else-if="block.type === 'video' && videoEmbed"
        class="aspect-video w-full overflow-hidden rounded-lg border border-edge2"
    >
        <iframe
            :src="videoEmbed"
            class="h-full w-full"
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
        ></iframe>
    </div>

    <!-- Tabs -->
    <div
        v-else-if="block.type === 'tabs' && (settings.panes || []).length"
        class="wb-tabs"
    >
        <div class="mb-2 flex flex-wrap gap-1 border-b border-edge2">
            <button
                v-for="(p, i) in settings.panes"
                :key="i"
                class="wb-tab -mb-px border-b-2 px-3 py-1.5 text-sm transition"
                :class="
                    activeTab === i
                        ? 'wb-tab--active border-teal text-bright'
                        : 'border-transparent text-muted hover:text-ink'
                "
                @click="activeTab = i"
            >
                {{ p.title || `Tab ${i + 1}` }}
            </button>
        </div>
        <RenderedContent
            v-if="settings.panes[activeTab]?.markdown"
            :key="activeTab"
            :content="settings.panes[activeTab].markdown"
            :embeds="embeds"
            :spells="spells"
            :gm="gm"
            :wiki-targets="wikiTargets"
            :link-base="`/w/${campaignSlug}/`"
            :single-column="true"
            @reveal="emit('reveal', $event)"
        />
    </div>

    <!-- Gallery -->
    <div
        v-else-if="block.type === 'gallery' && (settings.images || []).length"
        class="grid gap-2"
        :style="{
            gridTemplateColumns: `repeat(${settings.columns || 3}, minmax(0, 1fr))`,
        }"
    >
        <img
            v-for="(url, i) in settings.images"
            :key="i"
            :src="url"
            alt=""
            class="h-full w-full rounded-lg object-cover"
        />
    </div>

    <!-- Mini-timeline -->
    <div
        v-else-if="block.type === 'events' && (settings.events || []).length"
        class="flex flex-col gap-3 border-l border-edge2 pl-5"
    >
        <div
            v-for="(e, i) in settings.events"
            :key="i"
            class="flex items-start gap-4"
        >
            <div
                class="wb-event-when w-20 shrink-0 pt-1 text-right font-mono text-[12px] text-teal"
            >
                {{ e.when }}
            </div>
            <div class="flex-1 rounded-lg border border-edge2 bg-card p-3">
                <div class="font-display text-[16px] text-[#f3efe6]">
                    {{ e.title }}
                </div>
                <div v-if="e.detail" class="mt-0.5 text-sm text-muted">
                    {{ e.detail }}
                </div>
            </div>
        </div>
    </div>

    <!-- Linked entries -->
    <div
        v-else-if="block.type === 'linked' && linkedItems.length"
        class="wb-linked grid gap-3"
        :style="{
            gridTemplateColumns: `repeat(${settings.columns || 2}, minmax(0, 1fr))`,
        }"
    >
        <Link
            v-for="c in linkedItems"
            :key="c.id"
            :href="`/w/${campaignSlug}/${c.type}/${c.slug}`"
            class="wb-card flex items-center gap-3 rounded-lg border border-edge2 bg-card p-3 transition hover:border-teal"
        >
            <div
                v-if="c.card_url"
                class="h-11 w-11 shrink-0 rounded-md bg-cover bg-center"
                :style="{ backgroundImage: `url(${c.card_url})` }"
            ></div>
            <div class="min-w-0">
                <div class="truncate text-[15px] text-ink">{{ c.title }}</div>
                <div
                    class="font-mono text-[9px] uppercase tracking-wider text-faint"
                >
                    {{ c.kindLabel }}
                </div>
            </div>
        </Link>
    </div>

    <!-- Meter -->
    <div v-else-if="block.type === 'meter'" class="wb-meter">
        <div
            v-if="settings.label || settings.value"
            class="mb-1 flex items-baseline justify-between gap-3"
        >
            <span class="text-sm text-ink">{{ resolve(settings.label) }}</span>
            <span class="font-mono text-[12px] text-faint"
                >{{ resolve(settings.value) }}{{ settings.suffix
                }}<template v-if="settings.max">
                    / {{ resolve(settings.max) }}{{ settings.suffix }}</template
                ></span
            >
        </div>
        <div
            class="wb-meter-track h-2.5 w-full overflow-hidden rounded-full bg-edge2"
        >
            <div
                class="wb-meter-bar h-full rounded-full transition-all"
                :class="meterColour[settings.colour] || meterColour.teal"
                :style="{ width: meterPct + '%' }"
            ></div>
        </div>
    </div>

    <!-- FAQ -->
    <div
        v-else-if="block.type === 'faq' && faqItems.length"
        class="wb-faq flex flex-col gap-1.5"
    >
        <details
            v-for="(item, i) in faqItems"
            :key="i"
            class="rounded-lg border border-edge2 bg-card"
        >
            <summary
                class="cursor-pointer px-4 py-2.5 font-display text-[15px] text-bright"
            >
                {{ resolve(item.question) }}
            </summary>
            <div class="border-t border-edge2 px-4 py-2.5">
                <RenderedContent
                    :content="resolve(item.answer)"
                    :wiki-targets="wikiTargets"
                    :link-base="`/w/${campaignSlug}/`"
                    :single-column="true"
                />
            </div>
        </details>
    </div>

    <!-- Random entry -->
    <div v-else-if="block.type === 'random'">
        <a
            :href="randomHref"
            class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition"
            :class="
                settings.style === 'outline'
                    ? 'border border-teal text-teal hover:bg-teal/10'
                    : 'bg-teal text-night hover:bg-teal/90'
            "
        >
            🎲 {{ resolve(settings.label) || "Show me something" }}
        </a>
    </div>

    <!-- Connections -->
    <div
        v-else-if="block.type === 'connections' && connections.length"
        class="wb-connections"
    >
        <div
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${Number(settings.columns) || 2}, minmax(0, 1fr))`,
            }"
        >
            <Link
                v-for="(c, i) in connections"
                :key="i"
                :href="`/w/${campaignSlug}/${c.type}/${c.slug}`"
                class="wb-card flex items-center gap-2.5 rounded-lg border border-edge2 bg-card p-2.5 transition hover:border-teal"
            >
                <div
                    v-if="settings.showImage !== false && c.image"
                    class="h-9 w-9 shrink-0 rounded-md bg-cover bg-center"
                    :style="{ backgroundImage: `url(${c.image})` }"
                ></div>
                <div class="min-w-0">
                    <div
                        v-if="settings.showRelationship !== false && c.label"
                        class="wb-connection-rel font-mono text-[9px] uppercase tracking-wider text-teal"
                    >
                        {{ c.label }}
                    </div>
                    <div class="truncate text-[14px] text-ink">
                        {{ c.title }}
                    </div>
                    <div
                        class="truncate font-mono text-[9px] uppercase tracking-wider text-faint"
                    >
                        {{ c.kindLabel }}
                    </div>
                </div>
            </Link>
        </div>
    </div>

    <!-- Comparison table -->
    <div
        v-else-if="block.type === 'comparison' && comparisonEntries.length"
        class="wb-comparison overflow-x-auto"
    >
        <table class="w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-edge2">
                    <th class="py-2 pr-3"></th>
                    <th
                        v-for="e in comparisonEntries"
                        :key="e.id"
                        class="py-2 pr-3 font-display text-[15px] text-bright"
                    >
                        <Link
                            :href="`/w/${campaignSlug}/${e.type}/${e.slug}`"
                            class="hover:text-teal"
                            >{{ e.title }}</Link
                        >
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="label in comparisonRows"
                    :key="label"
                    class="border-b border-edge2/60"
                >
                    <td
                        class="py-2 pr-3 font-mono text-[11px] uppercase tracking-wider text-faint"
                    >
                        {{ label }}
                    </td>
                    <td
                        v-for="e in comparisonEntries"
                        :key="e.id"
                        class="py-2 pr-3 text-ink"
                    >
                        {{ factValue(e, label) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Portrait / avatar -->
    <div
        v-else-if="block.type === 'avatar' && entry?.image"
        class="wb-avatar flex justify-center"
    >
        <img
            :src="entry.image"
            :alt="entry.title"
            class="h-44 w-44 border-2 border-edge2 object-cover shadow-lg"
            :class="
                settings.shape === 'square'
                    ? ''
                    : settings.shape === 'rounded'
                      ? 'rounded-xl'
                      : 'rounded-full'
            "
        />
    </div>

    <!-- Map -->
    <div v-else-if="block.type === 'map' && mapPayload" class="wb-map">
        <div
            class="overflow-hidden rounded-lg border border-edge2"
            :class="mapHeight[settings.height] || mapHeight.md"
        >
            <MapViewer :map="mapPayload" :campaign-slug="campaignSlug" />
        </div>
        <div class="mt-1.5 flex items-baseline justify-between">
            <div class="font-display text-[15px] text-bright">
                {{ mapPayload.name }}
            </div>
            <a
                :href="`/w/${campaignSlug}/maps/${mapPayload.slug}`"
                class="font-mono text-[11px] uppercase tracking-wider text-teal hover:underline"
                >Open full map →</a
            >
        </div>
    </div>

    <!-- Repeater: render the child blocks once per related entry / connection, with {{ item.* }} vars. -->
    <div
        v-else-if="block.type === 'repeater' && repeaterItems.length"
        class="wb-repeater flex flex-col gap-4"
    >
        <div
            v-for="(scope, i) in repeaterItems"
            :key="i"
            class="wb-repeater-item flex flex-col gap-2"
        >
            <div
                v-for="child in repeaterBlocks"
                :key="child.id"
                :class="[`wb-block-${child.id}`, deviceClass(child)]"
            >
                <ReaderBlock
                    :block="child"
                    :entry="entry"
                    :facts="facts"
                    :related="related"
                    :toc="toc"
                    :wiki-targets="wikiTargets"
                    :embeds="embeds"
                    :spells="spells"
                    :gm="gm"
                    :campaign-slug="campaignSlug"
                    :linked-cards="linkedCards"
                    :maps="maps"
                    :connections="connections"
                    :comparison="comparison"
                    :item-scope="scope"
                    @reveal="emit('reveal', $event)"
                />
            </div>
        </div>
    </div>

    <!-- Reusable block: render the referenced block set in place. -->
    <div
        v-else-if="block.type === 'reusable' && reusableChildren.length"
        class="wb-reusable flex flex-col gap-3"
    >
        <div
            v-for="child in reusableChildren"
            :key="child.id"
            :class="[`wb-block-${child.id}`, deviceClass(child)]"
        >
            <ReaderBlock
                :block="child"
                :entry="entry"
                :facts="facts"
                :related="related"
                :toc="toc"
                :wiki-targets="wikiTargets"
                :embeds="embeds"
                :spells="spells"
                :gm="gm"
                :campaign-slug="campaignSlug"
                :linked-cards="linkedCards"
                :maps="maps"
                :connections="connections"
                :comparison="comparison"
                :reusable-blocks="reusableBlocks"
                :item-scope="itemScope"
                @reveal="emit('reveal', $event)"
            />
        </div>
    </div>

    <!-- Divider -->
    <div v-else-if="block.type === 'divider'" class="my-1 h-px bg-edge2"></div>
  </template>
</template>
