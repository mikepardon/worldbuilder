<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import HomeBlock from "@/Components/HomeBlock.vue";
import { scopeBlockCss } from "@/lib/readerCss";
import { deviceClass } from "@/lib/templateVars";
import { isRecent } from "@/lib/readerDate";
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    campaign: Object,
    sections: Array,
    recent: Array,
    // The pool an Entries block filters/sorts — only populated for a block-driven home.
    entries: { type: Array, default: () => [] },
    featured: { type: Array, default: () => [] },
    // Playable campaigns and their latest recaps — only populated for a block-driven home.
    campaigns: { type: Array, default: () => [] },
    recaps: { type: Array, default: () => [] },
    nextSession: { type: Object, default: null },
    viewer: { type: Object, default: () => ({}) },
    // The GM's home-page template as a block list; empty falls back to the built-in home layout.
    blocks: { type: Array, default: () => [] },
    // { id: [blocks] } — resolved block sets for "reusable" blocks.
    reusableBlocks: { type: Object, default: () => ({}) },
});

const entryCount = computed(() =>
    props.sections.reduce((n, s) => n + s.count, 0),
);
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
const doors = computed(() => props.sections.slice(0, 3));
const stripesHero =
    "repeating-linear-gradient(48deg,#1c1f27 0 10px,#191c23 10px 20px)";
// A GM-uploaded banner replaces the default stripes behind the hero; the overlay keeps text legible.
const heroStyle = computed(() =>
    props.campaign.banner
        ? {
              backgroundImage: `url(${props.campaign.banner})`,
              backgroundSize: "cover",
              backgroundPosition: "center",
          }
        : { background: stripesHero },
);
const stripesCard =
    "repeating-linear-gradient(48deg,#22262e 0 9px,#1e222a 9px 18px)";
const stripesThumb =
    "repeating-linear-gradient(48deg,#252932 0 8px,#20242c 8px 16px)";
</script>

<template>
    <Head :title="campaign.name" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="overview"
    >
        <div style="animation: fade 0.3s ease both">
            <!-- Block-driven home page (a home template is set) -->
            <template v-if="blocks.length">
                <component :is="'style'" v-if="blockCss" v-text="blockCss" />
                <div
                    v-for="b in blocks"
                    :key="b.id"
                    :class="[`wb-block-${b.id}`, deviceClass(b)]"
                >
                    <HomeBlock
                        :block="b"
                        :campaign="campaign"
                        :sections="sections"
                        :recent="recent"
                        :entries="entries"
                        :featured="featured"
                        :campaigns="campaigns"
                        :recaps="recaps"
                        :next-session="nextSession"
                        :reusable-blocks="reusableBlocks"
                        :stats="{
                            entries: entryCount,
                            sections: sections.length,
                        }"
                    />
                </div>
            </template>

            <!-- Built-in home layout (default) -->
            <template v-else>
            <!-- Hero cover -->
            <div
                class="relative h-[300px] border-b border-edge2"
                :style="heroStyle"
            >
                <div
                    class="absolute inset-0"
                    style="
                        background: linear-gradient(
                            180deg,
                            rgba(20, 22, 27, 0.15) 0%,
                            rgba(20, 22, 27, 0.55) 45%,
                            #14161b 100%
                        );
                    "
                ></div>
                <div
                    class="absolute inset-x-10 bottom-8 flex items-end justify-between gap-8"
                >
                    <div class="flex max-w-[640px] flex-col gap-2.5">
                        <div
                            class="font-mono text-[10px] uppercase tracking-[0.2em] text-amber"
                        >
                            A shared world
                        </div>
                        <div
                            class="font-display text-[56px] leading-none text-bright"
                        >
                            {{ campaign.name }}
                        </div>
                        <div
                            v-if="campaign.description"
                            class="text-[18px] font-light italic leading-[1.5] text-[#b8bcc4]"
                        >
                            {{ campaign.description }}
                        </div>
                    </div>
                    <div
                        v-if="campaign.homeLayout !== 'minimal'"
                        class="hidden gap-6 pb-1.5 sm:flex"
                    >
                        <div class="flex flex-col gap-1">
                            <div class="font-display text-[26px] text-bright">
                                {{ entryCount }}
                            </div>
                            <div
                                class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                            >
                                entries
                            </div>
                        </div>
                        <div class="flex flex-col gap-1">
                            <div class="font-display text-[26px] text-bright">
                                {{ sections.length }}
                            </div>
                            <div
                                class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                            >
                                sections
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div
                class="grid max-w-[1500px] gap-8 px-10 pb-14 pt-8 lg:grid-cols-[1fr_320px]"
            >
                <div class="flex flex-col gap-8">
                    <!-- Featured (GM-pinned) -->
                    <div v-if="featured.length" class="flex flex-col gap-3.5">
                        <div class="flex items-baseline gap-3">
                            <div
                                class="font-display text-[20px] text-[#f3efe6]"
                            >
                                Featured
                            </div>
                            <div
                                class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                            >
                                pinned by the GM
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Link
                                v-for="f in featured"
                                :key="f.id"
                                :href="`/w/${campaign.slug}/${f.type}/${f.slug}`"
                                class="overflow-hidden rounded-lg border border-edge2 bg-card transition hover:border-[#3d4250]"
                            >
                                <img
                                    v-if="f.card_url"
                                    :src="f.card_url"
                                    alt=""
                                    class="h-28 w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="h-28 w-full"
                                    :style="{ background: stripesCard }"
                                ></div>
                                <div class="p-4">
                                    <div
                                        class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-[#6fbfc4]"
                                    >
                                        {{ f.kindLabel }}
                                    </div>
                                    <div
                                        class="mt-1 font-display text-[16px] text-[#f3efe6]"
                                    >
                                        {{ f.title }}
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </div>

                    <!-- Start here -->
                    <div
                        v-if="doors.length && campaign.homeLayout !== 'minimal'"
                        class="flex flex-col gap-3.5"
                    >
                        <div class="flex items-baseline gap-3">
                            <div
                                class="font-display text-[20px] text-[#f3efe6]"
                            >
                                Start here
                            </div>
                            <div
                                class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                            >
                                doors into the world
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Link
                                v-for="d in doors"
                                :key="d.slug"
                                :href="`/w/${campaign.slug}/s/${d.slug}`"
                                class="overflow-hidden rounded-lg border border-edge2 bg-card transition hover:border-[#3d4250]"
                            >
                                <div
                                    class="h-28 bg-cover bg-center"
                                    :style="
                                        d.image
                                            ? {
                                                  backgroundImage: `url(${d.image})`,
                                              }
                                            : { background: stripesCard }
                                    "
                                ></div>
                                <div class="flex flex-col gap-1.5 px-4 py-3.5">
                                    <div
                                        class="font-mono text-[10px] uppercase tracking-[0.14em] text-teal"
                                    >
                                        Section
                                    </div>
                                    <div
                                        class="font-display text-[18px] text-[#f3efe6]"
                                    >
                                        {{ d.label }}
                                    </div>
                                    <div
                                        class="text-[14px] font-light leading-[1.5] text-muted"
                                    >
                                        {{ d.count }}
                                        {{
                                            d.count === 1 ? "entry" : "entries"
                                        }}
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </div>

                    <!-- Latest -->
                    <div v-if="recent.length" class="flex flex-col gap-3.5">
                        <div class="flex items-baseline gap-3">
                            <div
                                class="font-display text-[20px] text-[#f3efe6]"
                            >
                                Latest from the table
                            </div>
                            <div
                                class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                            >
                                recently updated
                            </div>
                        </div>
                        <div
                            class="flex flex-col rounded-lg border border-edge2 bg-card"
                        >
                            <Link
                                v-for="r in recent"
                                :key="r.id"
                                :href="`/w/${campaign.slug}/${r.type}/${r.slug}`"
                                class="flex items-center gap-3.5 border-b border-[#22262e] px-4 py-3.5 transition last:border-0 hover:bg-[#1c2027]"
                            >
                                <div
                                    class="h-11 w-11 flex-shrink-0 overflow-hidden rounded-[5px]"
                                    :style="
                                        r.card_url
                                            ? {}
                                            : { background: stripesThumb }
                                    "
                                >
                                    <img
                                        v-if="r.card_url"
                                        :src="r.card_url"
                                        alt=""
                                        class="h-full w-full object-cover"
                                    />
                                </div>
                                <div class="flex flex-1 flex-col gap-0.5">
                                    <div
                                        class="font-display text-[16px] text-[#f3efe6]"
                                    >
                                        {{ r.title }}
                                    </div>
                                    <div
                                        class="text-[13.5px] font-light leading-[1.4] text-muted"
                                    >
                                        {{ r.summary || r.kindLabel }}
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.12em] text-faint"
                                >
                                    <span
                                        v-if="isRecent(r.updated_at)"
                                        class="rounded-full bg-teal/15 px-1.5 py-0.5 text-[9px] text-teal"
                                        >New</span
                                    >
                                    {{ r.kindLabel }}
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Right rail -->
                <div class="flex flex-col gap-[18px]">
                    <div
                        class="flex flex-col gap-3 rounded-lg border border-edge2 bg-card p-4"
                    >
                        <div
                            class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
                        >
                            Browse the archive
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="s in sections"
                                :key="s.slug"
                                :href="`/w/${campaign.slug}/s/${s.slug}`"
                                class="rounded-full border border-edge3 px-3 py-1.5 text-[13.5px] text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                                >{{ s.label }}</Link
                            >
                        </div>
                    </div>
                </div>
            </div>
            </template>
        </div>
    </PublicLayout>
</template>
