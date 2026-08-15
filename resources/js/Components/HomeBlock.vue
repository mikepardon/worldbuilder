<script setup>
// Renders one home-page template block on the reader, from the world's home data.
import HomeBlock from "@/Components/HomeBlock.vue";
import ReaderBlock from "@/Components/ReaderBlock.vue";
import { deviceClass } from "@/lib/templateVars";
import { Link } from "@inertiajs/vue3";
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    block: { type: Object, required: true },
    campaign: { type: Object, required: true }, // head() payload (name, description, banner, slug)
    sections: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    // The full pool an Entries block filters/sorts from.
    entries: { type: Array, default: () => [] },
    featured: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
    recaps: { type: Array, default: () => [] },
    nextSession: { type: Object, default: null },
    reusableBlocks: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) }, // { entries, sections }
});

// Live countdown for the "Next session" block — ticks every second while that block is on screen.
const now = ref(Date.now());
let clock;
onMounted(() => {
    if (props.block.type === "nextsession") {
        clock = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    }
});
onBeforeUnmount(() => clearInterval(clock));
const countdown = computed(() => {
    const start = props.nextSession?.starts_at;
    if (!start) return undefined;
    const diff = new Date(start).getTime() - now.value;
    if (diff <= 0) return { live: true };
    return {
        live: false,
        days: Math.floor(diff / 86400000),
        hours: Math.floor((diff % 86400000) / 3600000),
        minutes: Math.floor((diff % 3600000) / 60000),
        seconds: Math.floor((diff % 60000) / 1000),
    };
});

const settings = computed(() => props.block.settings ?? {});

// The Entries block: filter the pool by kind, sort by the chosen order, then cap to the count.
const entryCards = computed(() => {
    const s = settings.value;
    const kinds = s.kinds ?? [];
    const list = kinds.length
        ? props.entries.filter((e) => kinds.includes(e.kind))
        : props.entries;
    const compare =
        {
            created: (a, b) =>
                (b.created_at ?? "").localeCompare(a.created_at ?? ""),
            title: (a, b) => a.title.localeCompare(b.title),
            updated: (a, b) =>
                (b.updated_at ?? "").localeCompare(a.updated_at ?? ""),
        }[s.sort] ??
        ((a, b) => (b.updated_at ?? "").localeCompare(a.updated_at ?? ""));
    return [...list].sort(compare).slice(0, Number(s.count) || 6);
});
const entryDate = (card) =>
    (settings.value.sort === "created" ? card.created_at : card.updated_at)?.slice(
        0,
        10,
    );

// Live search block: debounced query against the world's JSON search endpoint, grouped by section.
const searchTerm = ref("");
const searchGroups = ref([]);
const searching = ref(false);
let searchTimer;
const runSearch = () => {
    clearTimeout(searchTimer);
    const term = searchTerm.value.trim();
    if (term.length < 2) {
        searchGroups.value = [];
        searching.value = false;
        return;
    }
    searching.value = true;
    searchTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get(
                `/w/${props.campaign.slug}/search/query`,
                { params: { q: term } },
            );
            searchGroups.value = data.groups ?? [];
        } finally {
            searching.value = false;
        }
    }, 220);
};
const heroHeight = computed(
    () =>
        ({ sm: "h-[200px]", md: "h-[300px]", lg: "h-[420px]" })[
            settings.value.height
        ] ?? "h-[300px]",
);
const heroStyle = computed(() =>
    props.campaign.banner
        ? {
              backgroundImage: `linear-gradient(180deg, rgba(14,16,20,0.2), var(--wb-bg, #0e1014) 96%), url(${props.campaign.banner})`,
          }
        : {},
);
const entryHref = (card) =>
    `/w/${props.campaign.slug}/${card.type}/${card.slug}`;
const cols = (n) => `repeat(${n || 3}, minmax(0, 1fr))`;
</script>

<template>
    <!-- Hero -->
    <div
        v-if="block.type === 'hero'"
        class="wb-hero relative flex flex-col justify-end overflow-hidden bg-cover bg-center px-8 pb-8"
        :class="heroHeight"
        :style="heroStyle"
    >
        <div
            v-if="!campaign.banner"
            class="absolute inset-0 opacity-30"
            style="
                background-image: linear-gradient(
                    135deg,
                    #1c2230 0,
                    #0e1014 60%
                );
            "
        ></div>
        <div class="relative">
            <div
                class="wb-title font-display text-[44px] leading-tight text-bright"
            >
                {{ campaign.name }}
            </div>
            <p
                v-if="campaign.description"
                class="wb-summary mt-2 max-w-[640px] text-[16px] font-light italic text-[#c8ccd3]"
            >
                {{ campaign.description }}
            </p>
            <div
                v-if="settings.stats !== false"
                class="mt-3 flex gap-5 font-mono text-[11px] uppercase tracking-[0.14em] text-faint"
            >
                <span>{{ stats.entries ?? 0 }} entries</span>
                <span>{{ stats.sections ?? sections.length }} sections</span>
            </div>
        </div>
    </div>

    <!-- Featured -->
    <div
        v-else-if="block.type === 'featured' && featured.length"
        class="wb-featured px-8 py-6"
    >
        <div
            class="grid gap-4"
            :style="{ gridTemplateColumns: cols(settings.columns) }"
        >
            <Link
                v-for="card in featured"
                :key="card.id"
                :href="entryHref(card)"
                class="wb-card overflow-hidden rounded-lg border border-edge2 bg-card transition hover:border-teal"
            >
                <div
                    v-if="settings.showImage !== false && card.card_url"
                    class="h-32 bg-cover bg-center"
                    :style="{ backgroundImage: `url(${card.card_url})` }"
                ></div>
                <div class="p-3">
                    <div
                        v-if="settings.showKind !== false"
                        class="font-mono text-[9px] uppercase tracking-wider text-teal"
                    >
                        {{ card.kindLabel }}
                    </div>
                    <div class="mt-0.5 font-display text-[16px] text-bright">
                        {{ card.title }}
                    </div>
                    <p
                        v-if="settings.showSummary && card.summary"
                        class="mt-1 line-clamp-2 text-sm text-muted"
                    >
                        {{ card.summary }}
                    </p>
                </div>
            </Link>
        </div>
    </div>

    <!-- Section doors -->
    <div
        v-else-if="block.type === 'sections' && sections.length"
        class="wb-sections px-8 py-6"
    >
        <div
            class="grid gap-4"
            :style="{ gridTemplateColumns: cols(settings.columns) }"
        >
            <Link
                v-for="s in sections"
                :key="s.slug"
                :href="`/w/${campaign.slug}/s/${s.slug}`"
                class="wb-card overflow-hidden rounded-lg border border-edge2 bg-card transition hover:border-teal"
            >
                <div
                    v-if="s.image"
                    class="h-28 bg-cover bg-center"
                    :style="{ backgroundImage: `url(${s.image})` }"
                ></div>
                <div class="p-4">
                    <div class="font-display text-[18px] text-bright">
                        {{ s.label }}
                    </div>
                    <div class="mt-1 font-mono text-[10px] text-faint">
                        {{ s.count }} {{ s.count === 1 ? "entry" : "entries" }}
                    </div>
                </div>
            </Link>
        </div>
    </div>

    <!-- Entries -->
    <div
        v-else-if="block.type === 'recent' && entryCards.length"
        class="wb-recent px-8 py-6"
    >
        <div
            v-if="settings.title"
            class="mb-3 font-mono text-[10px] uppercase tracking-[0.18em] text-teal"
        >
            {{ settings.title }}
        </div>
        <div
            class="grid gap-3"
            :style="{ gridTemplateColumns: cols(settings.columns || 1) }"
        >
            <Link
                v-for="card in entryCards"
                :key="card.id"
                :href="entryHref(card)"
                class="wb-card overflow-hidden rounded-lg border border-edge2 bg-card transition hover:border-teal"
            >
                <div
                    v-if="settings.showImage !== false && card.card_url"
                    class="h-32 bg-cover bg-center"
                    :style="{ backgroundImage: `url(${card.card_url})` }"
                ></div>
                <div class="p-3">
                    <div
                        v-if="settings.showKind !== false"
                        class="font-mono text-[9px] uppercase tracking-wider text-teal"
                    >
                        {{ card.kindLabel }}
                    </div>
                    <div class="mt-0.5 font-display text-[16px] text-bright">
                        {{ card.title }}
                    </div>
                    <p
                        v-if="settings.showSummary && card.summary"
                        class="mt-1 line-clamp-2 text-sm text-muted"
                    >
                        {{ card.summary }}
                    </p>
                    <div
                        v-if="settings.showDate && entryDate(card)"
                        class="mt-1 font-mono text-[10px] text-faint"
                    >
                        {{ entryDate(card) }}
                    </div>
                </div>
            </Link>
        </div>
    </div>

    <!-- Search bar -->
    <div v-else-if="block.type === 'search'" class="wb-search px-8 py-6">
        <div class="relative mx-auto max-w-2xl">
            <input
                v-model="searchTerm"
                type="search"
                :placeholder="settings.placeholder || 'Search the world…'"
                class="wb-search-input w-full rounded-lg border border-edge2 bg-card px-4 py-3 text-[15px] text-ink placeholder:text-faint focus:border-teal focus:outline-none"
                @input="runSearch"
            />
            <div
                v-if="searchTerm.trim().length >= 2"
                class="absolute left-0 right-0 top-full z-20 mt-2 max-h-[60vh] overflow-y-auto rounded-lg border border-edge2 bg-card shadow-xl"
            >
                <div
                    v-if="searching && !searchGroups.length"
                    class="px-4 py-3 text-sm text-faint"
                >
                    Searching…
                </div>
                <div
                    v-else-if="!searchGroups.length"
                    class="px-4 py-3 text-sm text-faint"
                >
                    No matches.
                </div>
                <div v-for="g in searchGroups" :key="g.label" class="py-1">
                    <div
                        class="px-4 py-1 font-mono text-[10px] uppercase tracking-wider text-teal"
                    >
                        {{ g.label }}
                    </div>
                    <a
                        v-for="item in g.items"
                        :key="item.url"
                        :href="item.url"
                        class="block px-4 py-2 text-sm text-ink transition hover:bg-edge2/40"
                    >
                        {{ item.title }}
                        <span class="ml-1 text-faint">· {{ item.sub }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Campaign spotlight -->
    <div
        v-else-if="block.type === 'spotlight' && campaigns.length"
        class="wb-spotlight px-8 py-6"
    >
        <div
            class="mb-3 font-mono text-[10px] uppercase tracking-[0.18em] text-teal"
        >
            Campaigns
        </div>
        <div
            class="grid gap-4"
            :style="{ gridTemplateColumns: cols(settings.columns || 2) }"
        >
            <Link
                v-for="c in campaigns"
                :key="c.slug"
                :href="`/w/${campaign.slug}/campaigns/${c.slug}`"
                class="wb-card rounded-lg border border-edge2 bg-card p-4 transition hover:border-teal"
            >
                <div class="font-display text-[18px] text-bright">
                    {{ c.name }}
                </div>
                <p
                    v-if="c.description"
                    class="mt-1 line-clamp-2 text-sm text-muted"
                >
                    {{ c.description }}
                </p>
                <div class="mt-2 font-mono text-[10px] uppercase tracking-wider text-faint">
                    {{ c.session_count }}
                    {{ c.session_count === 1 ? "session" : "sessions" }}
                </div>
            </Link>
        </div>
    </div>

    <!-- Session recaps -->
    <div
        v-else-if="block.type === 'recaps' && recaps.length"
        class="wb-recaps px-8 py-6"
    >
        <div
            class="mb-3 font-mono text-[10px] uppercase tracking-[0.18em] text-teal"
        >
            Latest recaps
        </div>
        <div class="flex flex-col gap-2">
            <a
                v-for="(r, i) in recaps.slice(0, Number(settings.count) || 3)"
                :key="i"
                :href="r.url"
                class="wb-card rounded-lg border border-edge2 bg-card p-3 transition hover:border-teal"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <div class="font-serif text-[15px] text-[#f3efe6]">
                        {{ r.title }}
                    </div>
                    <div
                        v-if="r.held_on"
                        class="shrink-0 font-mono text-[11px] text-faint"
                    >
                        {{ r.held_on }}
                    </div>
                </div>
                <div
                    class="mt-0.5 font-mono text-[10px] uppercase tracking-wider text-teal"
                >
                    {{ r.campaign }}
                </div>
                <p v-if="r.summary" class="mt-1 line-clamp-2 text-sm text-muted">
                    {{ r.summary }}
                </p>
            </a>
        </div>
    </div>

    <!-- Next session countdown -->
    <div
        v-else-if="block.type === 'nextsession' && nextSession"
        class="wb-nextsession px-8 py-6"
    >
        <div class="rounded-lg border border-edge2 bg-card p-5">
            <div
                class="mb-1 font-mono text-[10px] uppercase tracking-[0.18em] text-teal"
            >
                {{ settings.title || "Next session" }}
            </div>
            <div class="font-display text-[20px] text-bright">
                {{ nextSession.title }}
            </div>
            <div
                class="font-mono text-[10px] uppercase tracking-wider text-faint"
            >
                {{ nextSession.campaign }}
            </div>
            <div v-if="countdown" class="wb-countdown mt-3">
                <a
                    v-if="countdown.live"
                    :href="nextSession.url"
                    class="font-display text-[18px] text-teal hover:underline"
                    >Happening now →</a
                >
                <div v-else class="flex gap-4">
                    <div
                        v-for="unit in [
                            { label: 'Days', value: countdown.days },
                            { label: 'Hrs', value: countdown.hours },
                            { label: 'Min', value: countdown.minutes },
                            { label: 'Sec', value: countdown.seconds },
                        ]"
                        :key="unit.label"
                        class="text-center"
                    >
                        <div class="font-display text-[24px] text-bright">
                            {{ unit.value }}
                        </div>
                        <div
                            class="font-mono text-[9px] uppercase tracking-wider text-faint"
                        >
                            {{ unit.label }}
                        </div>
                    </div>
                </div>
            </div>
            <a
                :href="nextSession.url"
                class="mt-3 inline-block font-mono text-[11px] uppercase tracking-wider text-teal hover:underline"
                >View schedule →</a
            >
        </div>
    </div>

    <!-- Columns: split the row, then render each child block (recursing for home specials). -->
    <div
        v-else-if="block.type === 'columns'"
        class="grid gap-5 px-8 py-4"
        :style="{ gridTemplateColumns: cols(settings.count || 2) }"
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
                <HomeBlock
                    :block="child"
                    :campaign="campaign"
                    :sections="sections"
                    :recent="recent"
                    :entries="entries"
                    :featured="featured"
                    :campaigns="campaigns"
                    :recaps="recaps"
                    :next-session="nextSession"
                    :reusable-blocks="reusableBlocks"
                    :stats="stats"
                />
            </div>
        </div>
    </div>

    <!-- Any other block is a self-contained "common" block, rendered by the shared reader block. -->
    <div v-else class="px-8 py-4">
        <ReaderBlock
            :block="block"
            :campaign-slug="campaign.slug"
            :reusable-blocks="reusableBlocks"
        />
    </div>
</template>
