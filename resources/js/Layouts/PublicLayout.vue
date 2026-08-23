<script setup>
import Dropdown from "@/Components/Dropdown.vue";
import DropdownLink from "@/Components/DropdownLink.vue";
import ReaderNavItem from "@/Components/ReaderNavItem.vue";
import ReaderNavMobileItem from "@/Components/ReaderNavMobileItem.vue";
import ReaderSearchModal from "@/Components/ReaderSearchModal.vue";
import { scopeReaderCss } from "@/lib/readerCss";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { computed, onMounted, ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    sections: { type: Array, default: () => [] },
    active: { type: String, default: "" },
    viewer: { type: Object, default: () => ({}) },
    // Full-bleed: fill the viewport (no footer, no page scroll) — used by the map view.
    fullBleed: { type: Boolean, default: false },
});

// Reader accent theme — remaps the reader's teal accent via a CSS variable (see app.css).
const THEME_ACCENTS = {
    teal: "#6fbfc4",
    amber: "#e0a33e",
    crimson: "#d9555f",
    violet: "#9b7fe0",
    emerald: "#4fd1a1",
    sky: "#5cc8e6",
    rose: "#e06c9f",
};
const accent = computed(
    () => THEME_ACCENTS[props.campaign?.theme] ?? THEME_ACCENTS.teal,
);

// Reading font — chosen from already-bundled families, applied inline so body text follows it
// while headings (font-display) and code (font-mono) keep their own classes.
const FONT_STACKS = {
    serif: "Spectral, Georgia, serif",
    sans: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    mono: '"JetBrains Mono", ui-monospace, monospace',
};
const fontStack = computed(
    () => FONT_STACKS[props.campaign?.font] ?? FONT_STACKS.serif,
);

// Reader colours — the GM's free-choice background/heading/body colours, exposed as CSS variables the
// reader's palette classes map onto (see app.css). Unset colours fall back to the built-in palette.
const themeVars = computed(() => {
    const vars = {
        "--reader-accent": accent.value,
        "--wb-accent": accent.value,
        fontFamily: fontStack.value,
    };
    const colours = props.campaign?.colours ?? {};
    if (colours.background) vars["--wb-bg"] = colours.background;
    if (colours.heading) vars["--wb-heading"] = colours.heading;
    if (colours.text) vars["--wb-text"] = colours.text;
    return vars;
});

// The GM's custom CSS, scoped to the reader root (.wb-reader) and stripped of unsafe constructs.
const customCss = computed(() => scopeReaderCss(props.campaign?.css ?? ""));

// Reader nav: resolve the GM's saved menu tree into real links. Each node's target is turned into an
// href/label/count here; items the viewer can't reach (a disabled feature, an empty section, a deleted
// entry) are dropped, and a parent with no reachable children collapses too. Fully data-driven — the
// menu decides the order, nesting and which built-in pages appear.
const page = usePage();
const currentPath = computed(() => (page.url ?? "").split("?")[0]);
const PAGE_LABELS = {
    overview: "Overview",
    compendium: "Compendium",
    web: "Web",
    campaigns: "Campaigns",
    maps: "Maps",
};

const resolveNavNode = (node) => {
    const slug = props.campaign?.slug;
    const flags = props.campaign ?? {};
    let href;
    let external = false;
    let count;
    let available = false;
    let activeKey;
    let label = node.label;

    if (node.type === "page") {
        const target = node.target;
        available = {
            overview: true,
            compendium: !!flags.hasCompendium,
            web: !!flags.hasWeb,
            campaigns: !!flags.hasCampaigns,
            maps: !!flags.hasMaps,
        }[target];
        href = target === "overview" ? `/w/${slug}` : `/w/${slug}/${target}`;
        label = node.label || PAGE_LABELS[target] || target;
        activeKey = target;
    } else if (node.type === "section") {
        const section = props.sections.find((s) => s.slug === node.target);
        available = !!section;
        if (section) {
            href = `/w/${slug}/s/${section.slug}`;
            count = section.count;
            label = node.label || section.label;
            activeKey = section.slug;
        }
    } else if (node.type === "campaign") {
        const found = (props.campaign?.navCampaigns ?? []).find(
            (c) => c.slug === node.target,
        );
        available = !!found;
        if (found) {
            href = `/w/${slug}/campaigns/${found.slug}`;
            label = node.label || found.name;
        }
    } else if (node.type === "entry") {
        const found = (props.campaign?.navEntries ?? {})[node.target];
        available = !!found;
        if (found) {
            const [type, entrySlug] = node.target.split(":");
            href = `/w/${slug}/${type}/${entrySlug}`;
            label = node.label || found.title;
        }
    } else if (node.type === "link") {
        available = !!node.target;
        href = node.target;
        external = true;
        label = node.label || node.target;
    }

    const children = (node.children ?? [])
        .map(resolveNavNode)
        .filter(Boolean);

    // Keep a node only if it's a working link or a container with something inside it.
    if (!available && !children.length) {
        return undefined;
    }

    const active = activeKey
        ? props.active === activeKey
        : !external && !!href && currentPath.value === href;

    return {
        key: node.id,
        label,
        href: available ? href : undefined,
        external,
        count,
        active,
        children,
    };
};

const resolvedMenu = computed(() =>
    (props.campaign?.navMenu ?? [])
        .map(resolveNavNode)
        .filter(Boolean),
);

// Mobile nav: a hamburger opens a tap-friendly accordion (the desktop bar uses hover fly-outs, which
// touch devices can't reach). Close it whenever navigation happens so the panel never lingers.
const mobileNavOpen = ref(false);
watch(currentPath, () => {
    mobileNavOpen.value = false;
});

// Live search overlay, opened from the header icon or Cmd/Ctrl+K.
const searchModal = ref(null);
const openSearch = () => searchModal.value?.open();

onMounted(() => {
    const onKeydown = (event) => {
        if (
            (event.metaKey || event.ctrlKey) &&
            event.key.toLowerCase() === "k"
        ) {
            event.preventDefault();
            openSearch();
        }
    };
    window.addEventListener("keydown", onKeydown);

    // Load the GM's privacy-analytics tag once, on the reader only. Both providers track
    // SPA navigations themselves (Plausible via the history API, GA4 via enhanced measurement).
    const analytics = props.campaign?.analytics;
    if (
        !analytics ||
        typeof document === "undefined" ||
        document.getElementById("reader-analytics")
    ) {
        return;
    }

    const script = document.createElement("script");
    script.id = "reader-analytics";
    script.defer = true;

    if (analytics.provider === "plausible") {
        script.dataset.domain = analytics.id;
        script.src = "https://plausible.io/js/script.js";
    } else {
        script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(analytics.id)}`;
        window.dataLayer = window.dataLayer || [];
        window.gtag = function gtag() {
            // eslint-disable-next-line prefer-rest-params
            window.dataLayer.push(arguments);
        };
        window.gtag("js", new Date());
        window.gtag("config", analytics.id);
    }

    document.head.appendChild(script);
});

const setView = (asPlayer) => {
    const path = window.location.pathname;
    router.get(path, asPlayer ? { as: "player" } : {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div
        class="wb-reader bg-night font-serif text-ink"
        data-reader-theme
        :style="themeVars"
        :class="
            fullBleed
                ? 'flex h-screen flex-col overflow-hidden'
                : 'min-h-screen'
        "
    >
        <!-- The world's custom reader CSS, already scoped to .wb-reader and sanitised. -->
        <component :is="'style'" v-if="customCss" v-text="customCss" />
        <Head>
            <meta
                v-if="campaign?.noindex"
                head-key="robots"
                name="robots"
                content="noindex, nofollow"
            />
            <link
                v-if="campaign?.favicon"
                head-key="favicon"
                rel="icon"
                :href="campaign.favicon"
            />
            <link
                v-if="campaign?.slug"
                head-key="feed"
                rel="alternate"
                type="application/atom+xml"
                :title="`${campaign.name} — feed`"
                :href="`/w/${campaign.slug}/feed`"
            />
            <meta
                head-key="og:title"
                property="og:title"
                :content="campaign?.name"
            />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta
                v-if="campaign?.description"
                head-key="og:description"
                property="og:description"
                :content="campaign.description"
            />
            <meta
                v-if="campaign?.ogImage"
                head-key="og:image"
                property="og:image"
                :content="campaign.ogImage"
            />
            <meta
                head-key="twitter:card"
                name="twitter:card"
                :content="campaign?.ogImage ? 'summary_large_image' : 'summary'"
            />
        </Head>

        <header
            class="z-10 border-b border-edge2 bg-surface"
            :class="fullBleed ? '' : 'sticky top-0'"
        >
            <div
                class="mx-auto flex flex-wrap items-center gap-x-5 gap-y-2 px-6 py-3"
            >
                <img
                    v-if="campaign.logo"
                    :src="campaign.logo"
                    :alt="campaign.name"
                    class="h-[24px] w-[24px] flex-shrink-0 rounded-[5px] object-cover"
                />
                <Link
                    :href="`/w/${campaign.slug}`"
                    class="font-display text-[16px] text-bright"
                    >{{ campaign.name }}</Link
                >

                <button
                    type="button"
                    class="flex-shrink-0 text-muted transition hover:text-teal"
                    title="Search (Ctrl/Cmd + K)"
                    aria-label="Search"
                    @click="openSearch"
                >
                    <svg
                        class="h-[18px] w-[18px]"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </button>

                <nav
                    class="hidden flex-wrap items-center gap-4 font-mono text-xs uppercase tracking-wider md:flex"
                >
                    <ReaderNavItem
                        v-for="item in resolvedMenu"
                        :key="item.key"
                        :node="item"
                        :depth="0"
                    />
                </nav>

                <div class="ml-auto flex items-center gap-3">
                    <!-- SECRETS badge (GM view) -->
                    <div
                        v-if="viewer.gmView && viewer.secretCount"
                        class="flex items-center gap-2 rounded-full border border-[#6b4c14] bg-[#241c0e] px-3 py-1 font-mono text-[10px] tracking-[0.12em] text-amber"
                    >
                        SECRETS<span class="text-[#8a6a28]">{{
                            viewer.secretCount
                        }}</span>
                    </div>
                    <!-- Manage (owner only): jump back to the workspace settings -->
                    <a
                        v-if="campaign.manageUrl"
                        :href="campaign.manageUrl"
                        class="flex items-center gap-1 font-mono text-[10px] uppercase tracking-[0.1em] text-muted hover:text-teal"
                        title="World settings"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="12" cy="12" r="3" />
                            <path
                                d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"
                            />
                        </svg>
                        Settings
                    </a>
                    <!-- GM / Player toggle (owner) -->
                    <div
                        v-if="viewer.canToggle"
                        class="flex items-center gap-0.5 rounded-full border border-edge3 bg-[#1a1d24] p-[3px]"
                    >
                        <button
                            class="rounded-full px-3 py-1 font-mono text-[10px] tracking-[0.1em]"
                            :class="
                                !viewer.asPlayer
                                    ? 'bg-[#20190d] text-amber'
                                    : 'text-muted'
                            "
                            @click="setView(false)"
                        >
                            GM
                        </button>
                        <button
                            class="rounded-full px-3 py-1 font-mono text-[10px] tracking-[0.1em]"
                            :class="
                                viewer.asPlayer
                                    ? 'bg-[#15252a] text-teal'
                                    : 'text-muted'
                            "
                            @click="setView(true)"
                        >
                            PLAYER
                        </button>
                    </div>
                    <!-- account menu -->
                    <Dropdown v-if="viewer.authed" align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex h-[30px] w-[30px] items-center justify-center overflow-hidden rounded-full border border-[#333844] bg-[#242832] font-mono text-[12px] text-[#c8ccd3] transition hover:border-teal hover:text-teal focus:outline-none"
                                title="Account"
                            >
                                <img
                                    v-if="viewer.avatar"
                                    :src="viewer.avatar"
                                    alt=""
                                    class="h-full w-full object-cover"
                                />
                                <span v-else>{{ viewer.initial }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-edge2 px-4 py-2">
                                <div class="truncate text-sm text-ink">
                                    {{ viewer.name }}
                                </div>
                                <div class="text-xs text-faint">Signed in</div>
                            </div>
                            <DropdownLink :href="route('dashboard')"
                                >Dashboard</DropdownLink
                            >
                            <DropdownLink :href="route('profile.edit')"
                                >Profile &amp; D&amp;D Beyond key</DropdownLink
                            >
                            <DropdownLink :href="route('billing.index')"
                                >Billing</DropdownLink
                            >
                            <DropdownLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                                >Log out</DropdownLink
                            >
                        </template>
                    </Dropdown>
                    <Link
                        v-else
                        :href="route('login')"
                        class="text-sm text-muted hover:text-teal"
                        >Sign in</Link
                    >

                    <!-- Mobile: hamburger toggles the accordion panel below -->
                    <button
                        v-if="resolvedMenu.length"
                        type="button"
                        class="flex-shrink-0 text-muted transition hover:text-teal md:hidden"
                        :aria-expanded="mobileNavOpen"
                        aria-label="Menu"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <template v-if="mobileNavOpen">
                                <path d="M18 6 6 18" />
                                <path d="m6 6 12 12" />
                            </template>
                            <template v-else>
                                <path d="M3 12h18" />
                                <path d="M3 6h18" />
                                <path d="M3 18h18" />
                            </template>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile nav panel: tap-to-expand accordion of the same resolved menu -->
            <nav
                v-if="mobileNavOpen"
                class="border-t border-edge2 px-6 py-2 font-mono text-xs uppercase tracking-wider md:hidden"
            >
                <ReaderNavMobileItem
                    v-for="item in resolvedMenu"
                    :key="item.key"
                    :node="item"
                    :depth="0"
                />
            </nav>
        </header>

        <ReaderSearchModal ref="searchModal" :world-slug="campaign.slug" />

        <!-- Player-preview banner -->
        <div
            v-if="viewer.canToggle && viewer.asPlayer"
            class="flex items-center gap-3 border-b border-[#2f5457] bg-[#15252a] px-10 py-2.5 text-[14.5px] text-[#a8ced0]"
            style="animation: fade 0.3s ease both"
        >
            <span class="font-mono text-[9.5px] tracking-[0.16em] text-teal"
                >PREVIEW</span
            >
            You are seeing exactly what your players see. Secrets are gone, not
            locked.
            <button class="ml-auto text-sm text-teal" @click="setView(false)">
                Back to GM view
            </button>
        </div>

        <main :class="fullBleed ? 'min-h-0 flex-1' : ''">
            <slot />
        </main>

        <footer
            v-if="!fullBleed"
            class="border-t border-edge2 px-6 py-6 text-center text-xs text-faint"
        >
            <p v-if="campaign?.support" class="mb-3">
                <a
                    :href="campaign.support.url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 rounded-full border border-teal/40 px-3 py-1 text-teal hover:bg-teal/10"
                >
                    ♥ {{ campaign.support.label }}
                </a>
            </p>
            <p v-if="campaign?.footer" class="text-muted">
                {{ campaign.footer }}
            </p>
            <p :class="campaign?.footer ? 'mt-1 text-[11px]' : ''">
                A world published with
                <Link href="/" class="text-teal">Worldbuilder</Link>
            </p>
        </footer>
    </div>
</template>
