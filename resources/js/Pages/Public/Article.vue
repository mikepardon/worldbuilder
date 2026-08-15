<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import BloodlineChart from "@/Components/BloodlineChart.vue";
import HomebrewView from "@/Components/HomebrewView.vue";
import MapViewer from "@/Components/MapViewer.vue";
import ReaderBlock from "@/Components/ReaderBlock.vue";
import ReaderNotes from "@/Components/ReaderNotes.vue";
import RenderedContent from "@/Components/RenderedContent.vue";
import { scopeBlockCss } from "@/lib/readerCss";
import { parseMonsterBlocks } from "@/lib/statblock";
import { buildVars, deviceClass, ruleAllows } from "@/lib/templateVars";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    sections: Array,
    section: Object,
    entry: Object,
    related: { type: Array, default: () => [] },
    siblings: Array,
    statblocks: { type: Array, default: () => [] },
    embeds: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
    gmSecrets: Boolean,
    wikiTargets: { type: Array, default: () => [] },
    editor: { type: String, default: "brew" },
    facts: { type: Array, default: () => [] },
    brewCss: { type: String, default: "" },
    brewTheme: { type: String, default: "classic" },
    myNotes: { type: Array, default: () => [] },
    sharedNotes: { type: Array, default: () => [] },
    locked: { type: Boolean, default: false },
    map: { type: Object, default: null },
    // For a timeline entry (one Age): its span + dated events, rendered as a vertical timeline.
    timeline: { type: Object, default: null },
    // For a bloodline entry: its family-tree members, rendered as a chart.
    bloodline: { type: Array, default: null },
    layout: {
        type: Object,
        default: () => ({
            facts: "sidebar",
            width: "normal",
            banner: "auto",
            fields: [],
        }),
    },
    // The entry's template as a block list (empty when it has no template); carries per-block settings.
    blocks: { type: Array, default: () => [] },
    // The template's custom right-hand sidebar blocks.
    sidebar: { type: Array, default: () => [] },
    // Cards for entries referenced by "linked entries" blocks, keyed by id.
    linkedCards: { type: Object, default: () => ({}) },
    // MapViewer payloads for "map" blocks, keyed by map id.
    maps: { type: Object, default: () => ({}) },
    // This entry's neighbours (Connections block) and the entries a Comparison block lines up.
    connections: { type: Array, default: () => [] },
    comparison: { type: Object, default: () => ({}) },
    // { id: [blocks] } — resolved block sets for "reusable" blocks.
    reusableBlocks: { type: Object, default: () => ({}) },
});

// Per-block settings the reader honours (header toggles, facts columns, banner height, related columns).
// An entry with no template falls back to sensible defaults, so it renders exactly as before.
const blockSettings = (type) =>
    props.blocks.find((b) => b.type === type)?.settings ?? null;

// The entry's variables, and a per-type conditional-visibility check, so the structural blocks (banner,
// header, facts, content, related) honour the same "show only if…" rules as the free-order blocks. A
// block with no rule — or an entry with no template — is always shown.
const entryVars = computed(() =>
    buildVars(props.entry, props.facts, {
        related: props.related.length,
        connections: props.connections.length,
    }),
);
const blockVisible = (type) => {
    const block = props.blocks.find((b) => b.type === type);
    return block ? ruleAllows(block.visibleIf, entryVars.value) : true;
};
const headerOpts = computed(() => {
    const s = blockSettings("header");
    return {
        eyebrow: s ? s.eyebrow !== false : true,
        summary: s ? s.summary !== false : true,
        readingTime: s ? s.readingTime !== false : true,
    };
});
const factsColumns = computed(() => Number(blockSettings("facts")?.columns) || 0);
const relatedColumns = computed(
    () => Number(blockSettings("related")?.columns) || 2,
);
const bannerHeightClass = computed(
    () =>
        ({ sm: "h-[160px]", md: "h-[250px]", lg: "h-[340px]" })[
            blockSettings("banner")?.height
        ] ?? "h-[250px]",
);

// Custom-text and divider blocks render around the main content, in their position relative to it:
// blocks before the content block become an intro, those after it an outro.
const contentBlockIndex = computed(() =>
    props.blocks.findIndex((b) => b.type === "content"),
);
const EXTRA_TYPES = new Set([
    "text",
    "divider",
    "columns",
    "reference",
    "callout",
    "readaloud",
    "secret",
    "quote",
    "button",
    "spacer",
    "toc",
    "stats",
    "image",
    "accordion",
    "video",
    "tabs",
    "gallery",
    "events",
    "linked",
    "map",
    "meter",
    "faq",
    "random",
    "connections",
    "comparison",
    "repeater",
    "reusable",
]);
const extraBlocks = (before) =>
    props.blocks.filter((b, i) => {
        if (!EXTRA_TYPES.has(b.type)) return false;
        const ci = contentBlockIndex.value;
        if (ci < 0) return before; // no content block → treat every extra as intro
        return before ? i < ci : i > ci;
    });
const introExtras = computed(() => extraBlocks(true));
const outroExtras = computed(() => extraBlocks(false));

// Per-block custom CSS: each block's CSS scoped to its own .wb-block-<id> element, combined into one
// style tag, with the matching class attached to each structural piece below.
// Every block's CSS — top-level and the children inside columns — each scoped to its own element.
const flattenedBlocks = computed(() => {
    const out = [];
    for (const b of props.blocks) {
        out.push(b);
        if (b.type === "columns")
            for (const col of b.settings.cols ?? []) out.push(...col);
        if (b.type === "repeater") out.push(...(b.settings.blocks ?? []));
        if (b.type === "reusable")
            out.push(...(props.reusableBlocks[b.settings.refId] ?? []));
    }
    out.push(...props.sidebar);
    return out;
});
const blockCssStyle = computed(() =>
    flattenedBlocks.value
        .map((b) => scopeBlockCss(b.css, b.id))
        .filter(Boolean)
        .join("\n"),
);
const blockClass = (type) => {
    const block = props.blocks.find((b) => b.type === type);
    return block ? `wb-block-${block.id} ${deviceClass(block)}`.trim() : "";
};

// Content vs Map view (only offered when this location has a map). Map view is full-bleed.
const mode = ref("content");
const sidebarOpen = ref(false);
const mapMode = computed(
    () => mode.value === "map" && !!props.map && !props.locked,
);

// Password gate for a protected entry: unlock it for this browsing session.
const lockForm = useForm({ password: "" });
const unlock = () =>
    lockForm.post(
        route("public.article.unlock", [
            props.campaign.slug,
            props.entry.type,
            props.entry.slug,
        ]),
        {
            preserveScroll: true,
        },
    );

// Right-rail stat block swiper: compendium embeds ({{monster=id}}) plus hand-authored {{monster}} blocks.
const allStatblocks = computed(() => [
    ...props.statblocks,
    ...parseMonsterBlocks(props.entry.content ?? ""),
]);
const activeStat = ref(0);
watch(allStatblocks, () => {
    activeStat.value = 0;
});
const current = computed(
    () =>
        allStatblocks.value[activeStat.value] ?? allStatblocks.value[0] ?? null,
);
const stepStat = (delta) => {
    const count = allStatblocks.value.length;
    if (count > 0)
        activeStat.value = (activeStat.value + delta + count) % count;
};

// The cover banner: the entry can force it on/off; then its template can force it; otherwise it's
// dropped for People, Session and Lore pages (where a compendium/embed banner tends to add nothing).
const showCover = computed(() => {
    if (props.entry.cover_mode === "show") {
        return true;
    }
    if (props.entry.cover_mode === "hide") {
        return false;
    }
    // The chosen template's banner preference, when the entry itself hasn't overridden it.
    if (props.layout.banner === "show") {
        return true;
    }
    if (props.layout.banner === "hide") {
        return false;
    }
    // People show a portrait avatar by the facts instead of a wide banner.
    if (props.entry.kind === "npc") {
        return false;
    }
    return !["people", "sessions", "lore", "timelines"].includes(
        props.section?.slug ?? "",
    );
});

// Lore reads as pure prose — no cover, and no eyebrow/title/summary/reading-time header either
// (the breadcrumb still names the entry).
const isLore = computed(() => (props.section?.slug ?? "") === "lore");
// A timeline (an Age) keeps its eyebrow/title/summary but drops the cover and the reading-time line —
// its body is a list of dated events, not prose to time.
const isTimeline = computed(() => (props.section?.slug ?? "") === "timelines");

const mod = (score) => {
    const m = Math.floor((Number(score || 10) - 10) / 2);
    return `${m >= 0 ? "+" : ""}${m}`;
};
// Split a stat like "15 (leather armor, shield)" into the number shown and the parenthetical tooltip.
const splitStat = (value) => {
    const match = String(value ?? "").match(/^\s*([^(]+?)\s*(?:\((.*)\))?\s*$/);
    return match
        ? { head: match[1], detail: match[2] ?? "" }
        : { head: String(value ?? ""), detail: "" };
};
const stripes =
    "repeating-linear-gradient(48deg,#1f232b 0 10px,#1b1f26 10px 20px)";
// Hero background: the entry's own banner wins, then the world banner, then the stripe pattern.
const heroStyle = computed(() => {
    const url = props.entry.banner_url || props.campaign.banner;
    return url ? { backgroundImage: `url(${url})` } : { background: stripes };
});
// Per-entry accent override — remaps the reader accent var for this page's content only.
const THEME_ACCENTS = {
    teal: "#6fbfc4",
    amber: "#e0a33e",
    crimson: "#d9555f",
    violet: "#9b7fe0",
    emerald: "#4fd1a1",
    sky: "#5cc8e6",
    rose: "#e06c9f",
};
const accentStyle = computed(() =>
    props.entry.accent
        ? { "--reader-accent": THEME_ACCENTS[props.entry.accent] }
        : {},
);

// Table of contents built from the entry's headings (## / ###).
const contentEl = ref(null);
const toc = computed(() => {
    if (!props.entry.showToc) {
        return [];
    }
    return [
        ...(props.entry.content ?? "").matchAll(/^(#{2,3})\s+(.+?)\s*$/gm),
    ].map((m, index) => ({ level: m[1].length, text: m[2].trim(), index }));
});
const scrollToHeading = (text) => {
    const root = contentEl.value;
    if (!root) {
        return;
    }
    const heading = [...root.querySelectorAll("h1,h2,h3,h4")].find(
        (h) => h.textContent.trim() === text,
    );
    heading?.scrollIntoView({ behavior: "smooth", block: "start" });
};

const back = () =>
    router.get(
        props.section
            ? `/w/${props.campaign.slug}/s/${props.section.slug}`
            : `/w/${props.campaign.slug}`,
    );

// Unwraps a {{secret}} block in the saved entry so players can see it — a permanent, one-way publish.
const reveal = (index) => {
    if (
        !window.confirm(
            "Reveal this hidden block to your players? It becomes a permanent part of the entry and can no longer be hidden from here.",
        )
    )
        return;
    router.post(
        route("documents.reveal", props.entry.id),
        { index },
        { preserveScroll: true },
    );
};

// The sidebar's blocks: the template's custom sidebar, or the default (portrait, quick facts, notes).
const sidebarItems = computed(() => {
    if (props.sidebar.length) return props.sidebar;
    const items = [];
    if (props.entry.image)
        items.push({ id: "avatar", type: "avatar", settings: {} });
    if (
        props.facts.length &&
        props.layout.facts === "sidebar" &&
        blockVisible("facts")
    ) {
        items.push({ id: "facts", type: "facts", settings: {} });
    }
    items.push({ id: "notes", type: "notes", settings: {} });
    return items;
});
</script>

<template>
    <Head :title="`${entry.title} — ${campaign.name}`">
        <meta
            v-if="entry.noindex"
            head-key="robots"
            name="robots"
            content="noindex, nofollow"
        />
    </Head>

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        :active="section?.slug ?? ''"
        :full-bleed="mapMode"
    >
        <div
            :class="mapMode ? 'flex h-full flex-col' : ''"
            :style="{ ...accentStyle, animation: 'fade 0.3s ease both' }"
        >
            <!-- Breadcrumb -->
            <div
                class="flex items-center gap-3 border-b border-edge bg-surface px-10 py-2.5"
            >
                <button
                    class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                    @click="back"
                >
                    ← Back
                </button>
                <div
                    class="flex items-center gap-2 font-mono text-[12px] text-faint"
                >
                    <Link
                        :href="`/w/${campaign.slug}`"
                        class="hover:text-teal"
                        >{{ campaign.name }}</Link
                    >
                    <span class="text-[#4a4f59]">/</span>
                    <Link
                        v-if="section"
                        :href="`/w/${campaign.slug}/s/${section.slug}`"
                        class="hover:text-teal"
                        >{{ section.label }}</Link
                    >
                    <span v-if="section" class="text-[#4a4f59]">/</span>
                    <span class="text-ink">{{ entry.title }}</span>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <!-- Jump to this entry's focused view in the connections web. -->
                    <Link
                        v-if="entry.hasConnections"
                        :href="`/w/${campaign.slug}/web?focus=${entry.id}`"
                        class="flex items-center gap-1.5 rounded-md border border-edge3 px-3 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                    >
                        <svg
                            class="h-3.5 w-3.5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="18" cy="5" r="3" />
                            <circle cx="6" cy="12" r="3" />
                            <circle cx="18" cy="19" r="3" />
                            <path d="m8.6 13.5 6.8 4M15.4 6.5 8.6 10.5" />
                        </svg>
                        My connections
                    </Link>
                    <!-- Content | Map toggle (only when this location has a map) -->
                    <div
                        v-if="map"
                        class="flex overflow-hidden rounded-md border border-edge3 text-sm"
                    >
                        <button
                            class="px-3 py-1 transition"
                            :class="
                                !mapMode
                                    ? 'bg-raised text-bright'
                                    : 'text-muted hover:text-ink'
                            "
                            @click="mode = 'content'"
                        >
                            Content
                        </button>
                        <button
                            class="border-l border-edge3 px-3 py-1 transition"
                            :class="
                                mapMode
                                    ? 'bg-raised text-bright'
                                    : 'text-muted hover:text-ink'
                            "
                            @click="mode = 'map'"
                        >
                            Map
                        </button>
                    </div>
                    <template v-if="viewer.gmView">
                        <Link
                            :href="route('documents.edit', entry.id)"
                            class="rounded-md border border-edge3 px-3 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                            >Edit</Link
                        >
                        <a
                            :href="`?as=player`"
                            class="rounded-md border border-edge3 px-3 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                            >See as player</a
                        >
                    </template>
                </div>
            </div>

            <!-- Map view (full-height) -->
            <div v-if="mapMode" class="relative min-h-0 flex-1">
                <MapViewer :map="map" :campaign-slug="campaign.slug" />

                <!-- Sidebar toggle -->
                <button
                    class="absolute left-3 top-3 z-30 flex h-9 w-9 items-center justify-center rounded-md border text-lg shadow"
                    :class="
                        sidebarOpen
                            ? 'border-teal bg-white text-night'
                            : 'border-edge3 bg-surface text-muted hover:text-ink'
                    "
                    title="Toggle article"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    ☰
                </button>

                <!-- Article content, popped open beside the map -->
                <div
                    v-if="sidebarOpen"
                    class="absolute inset-y-0 left-0 z-20 w-[min(440px,82%)] overflow-y-auto border-r border-edge2 bg-surface/95 px-6 py-5 pt-16 backdrop-blur"
                >
                    <div
                        class="font-display text-[28px] leading-tight text-bright"
                    >
                        {{ entry.title }}
                    </div>
                    <div
                        v-if="entry.summary"
                        class="mt-1 text-[15px] font-light italic text-[#b8bcc4]"
                    >
                        {{ entry.summary }}
                    </div>
                    <div class="my-3 h-px bg-edge2"></div>
                    <HomebrewView
                        v-if="editor === 'brew'"
                        :content="entry.content"
                        :css="brewCss"
                        :theme="brewTheme"
                        :entries="wikiTargets"
                        :embeds="embeds"
                        :spells="spells"
                        :gm="viewer.gmView"
                        page-mode="single"
                        :full-width="true"
                        :wiki-base="`/w/${campaign.slug}/`"
                        wiki-suffix=""
                    />
                    <RenderedContent
                        v-else
                        :content="entry.content"
                        :embeds="embeds"
                        :spells="spells"
                        :gm="gmSecrets"
                        :wiki-targets="wikiTargets"
                        :link-base="`/w/${campaign.slug}/`"
                        :single-column="editor === 'field'"
                        @reveal="reveal"
                    />
                </div>
            </div>

            <!-- Password gate for a protected entry -->
            <div
                v-if="!mapMode && locked"
                class="mx-auto flex w-full max-w-md flex-col items-center gap-4 px-6 py-24 text-center"
            >
                <div
                    class="font-mono text-[10px] uppercase tracking-[0.2em] text-teal"
                >
                    {{ entry.kindLabel }}
                </div>
                <div class="font-display text-[32px] leading-tight text-bright">
                    {{ entry.title }}
                </div>
                <p class="text-sm text-muted">
                    This entry is password-protected. Enter the password to read
                    it.
                </p>
                <form
                    class="flex w-full max-w-xs flex-col gap-2"
                    @submit.prevent="unlock"
                >
                    <input
                        v-model="lockForm.password"
                        type="password"
                        class="field text-center"
                        placeholder="Password"
                        autocomplete="off"
                    />
                    <p
                        v-if="lockForm.errors.password"
                        class="text-sm text-red-400"
                    >
                        {{ lockForm.errors.password }}
                    </p>
                    <button
                        type="submit"
                        class="btn-primary justify-center"
                        :disabled="lockForm.processing || !lockForm.password"
                    >
                        Unlock
                    </button>
                </form>
            </div>

            <div
                v-else-if="!mapMode"
                class="grid"
                :class="
                    layout.width === 'wide' || layout.hideSidebar
                        ? ''
                        : 'lg:grid-cols-[minmax(0,1fr)_340px]'
                "
            >
                <!-- Per-block custom CSS, scoped to each block's own element. -->
                <component
                    :is="'style'"
                    v-if="blockCssStyle"
                    v-text="blockCssStyle"
                />
                <!-- Center: article -->
                <div
                    class="min-w-0 pb-14"
                    :class="
                        layout.width === 'narrow'
                            ? 'mx-auto w-full max-w-[760px]'
                            : ''
                    "
                >
                    <div
                        v-if="showCover && blockVisible('banner')"
                        class="relative bg-cover bg-center"
                        :class="[bannerHeightClass, blockClass('banner')]"
                        :style="heroStyle"
                    >
                        <div
                            class="absolute inset-0"
                            style="
                                background: linear-gradient(
                                    180deg,
                                    rgba(20, 22, 27, 0.1),
                                    #14161b 92%
                                );
                            "
                        ></div>
                    </div>
                    <div
                        ref="contentEl"
                        class="wb-article relative flex flex-col gap-4 px-11"
                        :class="{ 'pt-8': !showCover && !isLore }"
                        :style="showCover ? { marginTop: '-52px' } : {}"
                    >
                        <div
                            v-if="!isLore && blockVisible('header')"
                            class="wb-header flex flex-col gap-2.5"
                            :class="blockClass('header')"
                        >
                            <div
                                v-if="headerOpts.eyebrow"
                                class="wb-eyebrow font-mono text-[10px] uppercase tracking-[0.2em] text-teal"
                            >
                                {{ entry.kindLabel }}
                            </div>
                            <div
                                class="wb-title font-display text-[44px] leading-[1.06] text-bright"
                            >
                                {{ entry.title }}
                            </div>
                            <div
                                v-if="entry.summary && headerOpts.summary"
                                class="wb-summary max-w-[640px] text-[17px] font-light italic leading-[1.5] text-[#b8bcc4]"
                            >
                                {{ entry.summary }}
                            </div>
                            <div
                                v-if="entry.word_count && !isTimeline && headerOpts.readingTime"
                                class="wb-readingtime flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                            >
                                <span
                                    >{{ entry.reading_minutes }} min read</span
                                >
                                <span>·</span>
                                <span
                                    >{{
                                        entry.word_count.toLocaleString()
                                    }}
                                    words</span
                                >
                            </div>
                        </div>
                        <!-- Facts as a top band (template layout) -->
                        <div
                            v-if="facts.length && layout.facts === 'top' && blockVisible('facts')"
                            class="wb-facts wb-facts--top gap-x-8 gap-y-2 rounded-lg border border-edge2 bg-card px-4 py-3"
                            :class="[
                                factsColumns ? 'grid' : 'flex flex-wrap',
                                blockClass('facts'),
                            ]"
                            :style="
                                factsColumns
                                    ? {
                                          gridTemplateColumns: `repeat(${factsColumns}, minmax(0, 1fr))`,
                                      }
                                    : {}
                            "
                        >
                            <div
                                v-for="f in facts"
                                :key="f.label"
                                class="wb-fact flex flex-col"
                            >
                                <span
                                    class="wb-fact-label font-mono text-[9px] uppercase tracking-[0.14em] text-faint"
                                    >{{ f.label }}</span
                                >
                                <span class="wb-fact-value text-[15px] text-[#e6e2d8]">
                                    <template
                                        v-for="(item, i) in f.items"
                                        :key="i"
                                    >
                                        <Link
                                            v-if="item.link"
                                            :href="`/w/${campaign.slug}/${item.link.type}/${item.link.slug}`"
                                            class="text-teal hover:underline"
                                            >{{ item.value }}</Link
                                        ><span v-else>{{ item.value }}</span
                                        ><span v-if="i < f.items.length - 1"
                                            >,
                                        </span>
                                    </template>
                                </span>
                            </div>
                        </div>

                        <div v-if="!isLore" class="my-1 h-px bg-edge2"></div>

                        <!-- Table of contents -->
                        <div
                            v-if="toc.length"
                            class="max-w-[420px] rounded-lg border border-edge2 bg-card p-4"
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
                                    :style="{
                                        paddingLeft: (h.level - 2) * 14 + 'px',
                                    }"
                                >
                                    <button
                                        class="text-left text-sm text-[#c8ccd3] hover:text-teal"
                                        @click="scrollToHeading(h.text)"
                                    >
                                        {{ h.text }}
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Template custom-text / divider blocks placed before the content. -->
                        <div
                            v-for="b in introExtras"
                            :key="b.id"
                            :class="[`wb-block-${b.id}`, deviceClass(b)]"
                        >
                            <div
                                v-if="b.type === 'columns'"
                                class="grid gap-5"
                                :style="{
                                    gridTemplateColumns: `repeat(${b.settings.count || 2}, minmax(0, 1fr))`,
                                }"
                            >
                                <div
                                    v-for="(col, ci) in b.settings.cols"
                                    :key="ci"
                                    class="wb-col space-y-3"
                                >
                                    <div
                                        v-for="child in col"
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
                                            :gm="gmSecrets"
                                            :linked-cards="linkedCards"
                                            :maps="maps"
                                            :connections="connections"
                                            :comparison="comparison"
                                            :reusable-blocks="reusableBlocks"
                                            :campaign-slug="campaign.slug"
                                            @reveal="reveal"
                                        />
                                    </div>
                                </div>
                            </div>
                            <ReaderBlock
                                v-else
                                :block="b"
                                :entry="entry"
                                :facts="facts"
                                :related="related"
                                :toc="toc"
                                :wiki-targets="wikiTargets"
                                :embeds="embeds"
                                :spells="spells"
                                :gm="gmSecrets"
                                :linked-cards="linkedCards"
                                :maps="maps"
                                :connections="connections"
                                :comparison="comparison"
                                :reusable-blocks="reusableBlocks"
                                :campaign-slug="campaign.slug"
                                @reveal="reveal"
                            />
                        </div>

                        <!-- Timeline entry (one Age): its dated events as a vertical timeline. -->
                        <div
                            v-if="
                                entry.kind === 'timeline' &&
                                timeline?.events?.length
                            "
                            class="flex flex-col gap-3 border-l border-edge2 pl-6"
                        >
                            <div
                                v-for="(event, j) in timeline.events"
                                :key="j"
                                class="flex items-start"
                                style="gap: 18px"
                            >
                                <div
                                    class="w-20 flex-shrink-0 pt-2 text-right font-mono text-[12px] text-teal"
                                >
                                    {{ event.when }}
                                </div>
                                <component
                                    :is="event.link ? Link : 'div'"
                                    :href="
                                        event.link
                                            ? `/w/${campaign.slug}/${event.link.type}/${event.link.slug}`
                                            : undefined
                                    "
                                    class="flex flex-1 flex-col gap-1 rounded-lg border border-edge2 bg-card p-3.5"
                                    :class="
                                        event.link
                                            ? 'transition hover:border-[#3d4250]'
                                            : ''
                                    "
                                >
                                    <div
                                        class="font-display text-[18px] text-[#f3efe6]"
                                    >
                                        {{ event.title }}
                                    </div>
                                    <div
                                        v-if="event.detail"
                                        class="text-[14.5px] font-light leading-[1.55] text-muted"
                                    >
                                        {{ event.detail }}
                                    </div>
                                    <div
                                        v-if="event.link"
                                        class="mt-0.5 font-mono text-[10px] tracking-[0.08em] text-faint"
                                    >
                                        {{ event.link.title }} →
                                    </div>
                                </component>
                            </div>
                        </div>

                        <!-- Bloodline entry: its family tree. -->
                        <BloodlineChart
                            v-if="
                                entry.kind === 'bloodline' && bloodline?.length
                            "
                            :members="bloodline"
                            :height="560"
                            :link-base="`/w/${campaign.slug}/`"
                        />

                        <HomebrewView
                            v-if="
                                editor === 'brew' &&
                                entry.kind !== 'timeline' &&
                                entry.kind !== 'bloodline' &&
                                blockVisible('content')
                            "
                            :content="entry.content"
                            :css="brewCss"
                            :theme="brewTheme"
                            :entries="wikiTargets"
                            :embeds="embeds"
                            :spells="spells"
                            :gm="viewer.gmView"
                            page-mode="single"
                            :full-width="true"
                            :wiki-base="`/w/${campaign.slug}/`"
                            wiki-suffix=""
                        />
                        <div
                            v-else-if="editor !== 'brew' && blockVisible('content')"
                            :class="blockClass('content')"
                        >
                            <RenderedContent
                                :content="entry.content"
                                :embeds="embeds"
                                :spells="spells"
                                :gm="gmSecrets"
                                :wiki-targets="wikiTargets"
                                :link-base="`/w/${campaign.slug}/`"
                                :single-column="editor === 'field'"
                                @reveal="reveal"
                            />
                        </div>

                        <!-- Template custom-text / divider blocks placed after the content. -->
                        <div
                            v-for="b in outroExtras"
                            :key="b.id"
                            :class="[`wb-block-${b.id}`, deviceClass(b)]"
                        >
                            <div
                                v-if="b.type === 'columns'"
                                class="grid gap-5"
                                :style="{
                                    gridTemplateColumns: `repeat(${b.settings.count || 2}, minmax(0, 1fr))`,
                                }"
                            >
                                <div
                                    v-for="(col, ci) in b.settings.cols"
                                    :key="ci"
                                    class="wb-col space-y-3"
                                >
                                    <div
                                        v-for="child in col"
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
                                            :gm="gmSecrets"
                                            :linked-cards="linkedCards"
                                            :maps="maps"
                                            :connections="connections"
                                            :comparison="comparison"
                                            :reusable-blocks="reusableBlocks"
                                            :campaign-slug="campaign.slug"
                                            @reveal="reveal"
                                        />
                                    </div>
                                </div>
                            </div>
                            <ReaderBlock
                                v-else
                                :block="b"
                                :entry="entry"
                                :facts="facts"
                                :related="related"
                                :toc="toc"
                                :wiki-targets="wikiTargets"
                                :embeds="embeds"
                                :spells="spells"
                                :gm="gmSecrets"
                                :linked-cards="linkedCards"
                                :maps="maps"
                                :connections="connections"
                                :comparison="comparison"
                                :reusable-blocks="reusableBlocks"
                                :campaign-slug="campaign.slug"
                                @reveal="reveal"
                            />
                        </div>
                    </div>

                    <!-- Related entries (GM-curated) -->
                    <div
                        v-if="related.length && blockVisible('related')"
                        class="wb-related mt-10 px-11"
                        :class="blockClass('related')"
                    >
                        <div
                            class="wb-related-title mb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
                        >
                            Related
                        </div>
                        <div
                            class="grid gap-3"
                            :class="relatedColumns === 2 ? 'sm:grid-cols-2' : ''"
                        >
                            <Link
                                v-for="r in related"
                                :key="r.id"
                                :href="`/w/${campaign.slug}/${r.type}/${r.slug}`"
                                class="wb-card flex items-center gap-3 rounded-lg border border-edge2 bg-card p-3 transition hover:border-teal"
                            >
                                <div
                                    class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-raised"
                                >
                                    <img
                                        v-if="r.card_url"
                                        :src="r.card_url"
                                        alt=""
                                        class="h-full w-full object-cover"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="truncate font-serif text-[15px] text-[#f3efe6]"
                                    >
                                        {{ r.title }}
                                    </div>
                                    <div
                                        class="font-mono text-[10px] uppercase tracking-wider text-faint"
                                    >
                                        {{ r.kindLabel }}
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Right rail -->
                <div
                    v-if="!layout.hideSidebar"
                    class="flex flex-col gap-4 border-l border-edge bg-surface px-5 pb-14 pt-7 lg:sticky lg:top-[49px] lg:self-start"
                >
                    <!-- The sidebar is composed of blocks (portrait, quick facts, reader notes and any
                         custom blocks). A template with no custom sidebar falls back to the default set. -->
                    <template v-for="item in sidebarItems" :key="item.id">
                        <ReaderNotes
                            v-if="item.type === 'notes'"
                            :class="[`wb-block-${item.id}`, deviceClass(item)]"
                            :entry-id="entry.id"
                            :my-notes="myNotes"
                            :shared-notes="sharedNotes"
                            :can-write="
                                viewer.authed &&
                                campaign.allowNotes &&
                                entry.commentsEnabled !== false
                            "
                        />
                        <div
                            v-else
                            :class="[`wb-block-${item.id}`, deviceClass(item)]"
                        >
                            <ReaderBlock
                                :block="item"
                                :entry="entry"
                                :facts="facts"
                                :related="related"
                                :toc="toc"
                                :wiki-targets="wikiTargets"
                                :embeds="embeds"
                                :spells="spells"
                                :gm="gmSecrets"
                                :campaign-slug="campaign.slug"
                                :linked-cards="linkedCards"
                                :maps="maps"
                                :connections="connections"
                                :comparison="comparison"
                                :reusable-blocks="reusableBlocks"
                                @reveal="reveal"
                            />
                        </div>
                    </template>

                    <!-- Stat block swiper: every monster referenced in this entry -->
                    <div
                        v-if="current"
                        class="flex flex-col gap-3 rounded-lg border border-edge2 bg-card p-3.5"
                    >
                        <!-- Swiper controls at the top (only when there's more than one monster) -->
                        <div
                            v-if="allStatblocks.length > 1"
                            class="flex items-center justify-between border-b border-edge2 pb-2.5"
                        >
                            <button
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-edge3 text-[15px] leading-none text-muted transition hover:border-teal hover:text-teal"
                                aria-label="Previous monster"
                                @click="stepStat(-1)"
                            >
                                ‹
                            </button>
                            <div class="flex items-center gap-1.5">
                                <button
                                    v-for="(s, i) in allStatblocks"
                                    :key="s.id"
                                    class="h-1.5 rounded-full transition-all"
                                    :class="
                                        i === activeStat
                                            ? 'w-4 bg-teal'
                                            : 'w-1.5 bg-edge3 hover:bg-muted'
                                    "
                                    :aria-label="`Show ${s.name}`"
                                    @click="activeStat = i"
                                ></button>
                            </div>
                            <button
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-edge3 text-[15px] leading-none text-muted transition hover:border-teal hover:text-teal"
                                aria-label="Next monster"
                                @click="stepStat(1)"
                            >
                                ›
                            </button>
                        </div>
                        <div class="flex items-center justify-between">
                            <div
                                class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
                            >
                                {{ current.name }}
                            </div>
                            <div class="font-mono text-[10px] text-teal">
                                5E
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <div
                                v-for="[k, v] in [
                                    ['AC', current.block.ac],
                                    ['HP', current.block.hp],
                                    ['CR', current.block.cr],
                                ]"
                                :key="k"
                                class="hb-tip flex-1 rounded-md border border-edge3 py-2 text-center"
                                :class="{ 'cursor-help': splitStat(v).detail }"
                                :data-tip="splitStat(v).detail || undefined"
                            >
                                <div
                                    class="truncate px-1 font-display text-[15px] text-[#f3efe6]"
                                >
                                    {{ splitStat(v).head }}
                                </div>
                                <div
                                    class="mt-0.5 font-mono text-[9px] tracking-[0.1em] text-faint"
                                >
                                    {{ k }}
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-1.5">
                            <div
                                v-for="a in [
                                    'str',
                                    'dex',
                                    'con',
                                    'int',
                                    'wis',
                                    'cha',
                                ]"
                                :key="a"
                                class="hb-tip cursor-help rounded-[5px] bg-raised py-1.5 text-center"
                                :data-tip="`Modifier ${mod(current.block.abilities?.[a])}`"
                            >
                                <div
                                    class="font-mono text-[9px] uppercase tracking-[0.1em] text-faint"
                                >
                                    {{ a }}
                                </div>
                                <div
                                    class="mt-0.5 font-display text-[15px] text-ink"
                                >
                                    {{ current.block.abilities?.[a] }}
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </PublicLayout>
</template>

<style scoped>
/* Hover tooltip (instant, styled) for the stat-block cells. */
.hb-tip {
    position: relative;
}
.hb-tip[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 6px);
    transform: translateX(-50%);
    z-index: 50;
    width: max-content;
    max-width: 190px;
    white-space: normal;
    text-align: center;
    background: #14161b;
    color: #e6e8ec;
    border: 1px solid #333844;
    border-radius: 6px;
    padding: 4px 8px;
    font-family: ui-sans-serif, system-ui, sans-serif;
    font-size: 11px;
    line-height: 1.35;
    pointer-events: none;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
}

/* Slim, self-contained scrollbar for the sticky left rail. */
.rail-scroll {
    scrollbar-width: thin;
    scrollbar-color: #333844 transparent;
}
.rail-scroll::-webkit-scrollbar {
    width: 7px;
}
.rail-scroll::-webkit-scrollbar-thumb {
    background: #333844;
    border-radius: 4px;
}
.rail-scroll::-webkit-scrollbar-thumb:hover {
    background: #454b57;
}
.rail-scroll::-webkit-scrollbar-track {
    background: transparent;
}
</style>
