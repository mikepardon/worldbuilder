<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    world: Object,
    settings: Object,
    isOwner: Boolean,
    links: Object,
    domain: Object,
    sectionCatalogue: { type: Array, default: () => [] },
});

const tab = ref("general");
const tabs = [
    { key: "general", label: "General" },
    { key: "reader", label: "Reader" },
    { key: "play", label: "Play & recaps" },
    { key: "integrations", label: "Integrations" },
    { key: "advanced", label: "Advanced" },
];
const formTabs = ["general", "reader", "play"];

const form = useForm({
    name: props.settings.name,
    description: props.settings.description ?? "",
    setting: props.settings.setting ?? "",
    visibility: props.settings.visibility,
    default_campaign_visibility: props.settings.default_campaign_visibility,
    default_session_private: props.settings.default_session_private,
    reader_indexable: props.settings.reader_indexable,
    reader_show_compendium: props.settings.reader_show_compendium,
    reader_allow_notes: props.settings.reader_allow_notes,
    default_game_system: props.settings.default_game_system ?? "",
    default_recap_detail: props.settings.default_recap_detail,
    recap_auto_publish: props.settings.recap_auto_publish,
    reader_theme: props.settings.reader_theme,
    reader_bg: props.settings.reader_bg ?? "",
    reader_heading: props.settings.reader_heading ?? "",
    reader_text: props.settings.reader_text ?? "",
    reader_css: props.settings.reader_css ?? "",
    reader_analytics: props.settings.reader_analytics ?? "",
    reader_footer: props.settings.reader_footer ?? "",
    reader_font: props.settings.reader_font,
    reader_home_layout: props.settings.reader_home_layout,
    reader_date_format: props.settings.reader_date_format,
    reader_number_sessions: props.settings.reader_number_sessions,
    default_join_role: props.settings.default_join_role,
    default_entry_private: props.settings.default_entry_private,
    support_url: props.settings.support_url ?? "",
    support_label: props.settings.support_label ?? "",
});
const save = () =>
    form.put(route("worlds.update", props.world.id), { preserveScroll: true });

// The Discord webhook is a secret, so it never round-trips to the browser: it has its own
// form and the page only knows whether one is connected, not what it is.
const discordForm = useForm({ discord_webhook: "" });
const saveDiscord = () =>
    discordForm.put(route("worlds.update", props.world.id), {
        preserveScroll: true,
        onSuccess: () => discordForm.reset("discord_webhook"),
    });
const disconnectDiscord = () => {
    discordForm.discord_webhook = "";
    discordForm.put(route("worlds.update", props.world.id), {
        preserveScroll: true,
    });
};

// Reader password — its own form so the main save never clears the gate; the hash never reaches the browser.
const passwordForm = useForm({ reader_password: "" });
const saveReaderPassword = () =>
    passwordForm.put(route("worlds.update", props.world.id), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset("reader_password"),
    });
const clearReaderPassword = () => {
    passwordForm.reader_password = "";
    passwordForm.put(route("worlds.update", props.world.id), {
        preserveScroll: true,
    });
};

// Reader nav editor — its own form so reordering/hiding sections and external links save independently.
const catalogueLabel = (slug) =>
    props.sectionCatalogue.find((s) => s.slug === slug)?.label ?? slug;
const savedOrder = (props.settings.nav?.order ?? []).filter((slug) =>
    props.sectionCatalogue.some((s) => s.slug === slug),
);
const orderedSlugs = [
    ...savedOrder,
    ...props.sectionCatalogue
        .map((s) => s.slug)
        .filter((slug) => !savedOrder.includes(slug)),
];
const hiddenInit = new Set(props.settings.nav?.hidden ?? []);
const navRows = ref(
    orderedSlugs.map((slug) => ({
        slug,
        label: catalogueLabel(slug),
        hidden: hiddenInit.has(slug),
    })),
);
const navLinks = ref((props.settings.nav?.links ?? []).map((l) => ({ ...l })));
const moveNav = (index, delta) => {
    const target = index + delta;
    if (target < 0 || target >= navRows.value.length) {
        return;
    }
    const rows = navRows.value;
    [rows[index], rows[target]] = [rows[target], rows[index]];
};
const addNavLink = () => navLinks.value.push({ label: "", url: "" });
const removeNavLink = (index) => navLinks.value.splice(index, 1);
const navForm = useForm({ nav_order: [], nav_hidden: [], nav_links: [] });
const saveNav = () => {
    navForm.nav_order = navRows.value.map((r) => r.slug);
    navForm.nav_hidden = navRows.value
        .filter((r) => r.hidden)
        .map((r) => r.slug);
    navForm.nav_links = navLinks.value.filter((l) => l.label && l.url);
    navForm.put(route("worlds.update", props.world.id), {
        preserveScroll: true,
    });
};

// Reader branding — logo, hero banner and favicon, each uploaded as its own multipart request.
const logoForm = useForm({ type: "logo", file: undefined });
const bannerForm = useForm({ type: "banner", file: undefined });
const faviconForm = useForm({ type: "favicon", file: undefined });
const ogForm = useForm({ type: "og", file: undefined });
const uploadBranding = (brandingForm, event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }
    brandingForm.file = file;
    brandingForm.post(route("worlds.branding", props.world.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => brandingForm.reset("file"),
    });
    event.target.value = "";
};
const removeBranding = (type) =>
    router.delete(route("worlds.branding.clear", props.world.id), {
        data: { type },
        preserveScroll: true,
    });

// Reader section-door images — one upload per section, shown on the reader's "Start here" cards. Reuses
// the branding upload flow (multipart, adds to the media library); a shared form is fine as uploads run
// one at a time.
const sectionImageUrl = (slug) => props.settings.section_images?.[slug];
const sectionImageForm = useForm({ section: "", file: undefined });
const uploadSectionImage = (slug, event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }
    sectionImageForm.section = slug;
    sectionImageForm.file = file;
    sectionImageForm.post(route("worlds.section-image", props.world.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => sectionImageForm.reset("file"),
    });
    event.target.value = "";
};
const removeSectionImage = (slug) =>
    router.delete(route("worlds.section-image.clear", props.world.id), {
        data: { section: slug },
        preserveScroll: true,
    });

const readerThemes = [
    { value: "teal", hex: "#6fbfc4" },
    { value: "amber", hex: "#e0a33e" },
    { value: "crimson", hex: "#d9555f" },
    { value: "violet", hex: "#9b7fe0" },
    { value: "emerald", hex: "#4fd1a1" },
    { value: "sky", hex: "#5cc8e6" },
    { value: "rose", hex: "#e06c9f" },
];

// Free-choice reader colours. The fallback is the built-in palette value shown when the field is blank
// (blank means "use the default"), so an unbranded reader looks exactly as before.
const readerColourFields = [
    { key: "reader_bg", label: "Background", fallback: "#0e1014" },
    { key: "reader_heading", label: "Headings", fallback: "#f5f1e8" },
    { key: "reader_text", label: "Body text", fallback: "#e8e3d9" },
];
const isHex = (value) => /^#[0-9a-fA-F]{6}$/.test(value ?? "");
const effectiveColour = (key, fallback) =>
    isHex(form[key]) ? form[key] : fallback;

// A WCAG contrast ratio so we can warn (not block) on hard-to-read combinations.
const relativeLuminance = (hex) => {
    const channels = [1, 3, 5].map((i) => {
        const value = parseInt(hex.slice(i, i + 2), 16) / 255;
        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};
const contrastRatio = (a, b) => {
    const [high, low] = [relativeLuminance(a), relativeLuminance(b)].sort(
        (x, y) => y - x,
    );
    return (high + 0.05) / (low + 0.05);
};
const contrastWarning = computed(() => {
    const bg = effectiveColour("reader_bg", "#0e1014");
    const text = effectiveColour("reader_text", "#e8e3d9");
    const heading = effectiveColour("reader_heading", "#f5f1e8");
    const weak = [];
    if (contrastRatio(bg, text) < 4.5) weak.push("body text");
    if (contrastRatio(bg, heading) < 3) weak.push("headings");
    return weak.length
        ? `Low contrast on ${weak.join(" and ")} against the background — readers may struggle to read it.`
        : "";
});

const readerToggles = [
    {
        key: "reader_indexable",
        label: "Listed in search engines",
        hint: "Let Google and others index this world (public worlds only).",
    },
    { key: "reader_show_compendium", label: "Show the Compendium to players" },
    { key: "reader_allow_notes", label: "Allow players to keep private notes" },
];

const copyPublicLink = () =>
    navigator.clipboard?.writeText(props.settings.public_url);

const destroy = () => {
    if (
        confirm(
            "Delete this world and everything inside it? This cannot be undone.",
        )
    ) {
        router.delete(route("worlds.destroy", props.world.id));
    }
};
</script>

<template>
    <Head :title="`Settings — ${world.name}`" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <h1 class="font-display text-[28px] text-bright">World settings</h1>

            <!-- Tab nav -->
            <div class="flex flex-wrap gap-1 border-b border-edge2">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm transition"
                    :class="
                        tab === t.key
                            ? 'border-amber text-bright'
                            : 'border-transparent text-muted hover:text-bright'
                    "
                    @click="tab = t.key"
                >
                    {{ t.label }}
                </button>
            </div>

            <form class="space-y-6" @submit.prevent="save">
                <!-- ===== General ===== -->
                <section v-show="tab === 'general'" class="panel space-y-3 p-4">
                    <h2 class="font-display text-lg text-bright">Identity</h2>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint">Name</span>
                        <input v-model="form.name" class="field" />
                        <span
                            v-if="form.errors.name"
                            class="mt-1 block text-xs text-red-400"
                            >{{ form.errors.name }}</span
                        >
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Tagline / description</span
                        >
                        <textarea
                            v-model="form.description"
                            rows="2"
                            class="field"
                        />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Setting / genre blurb</span
                        >
                        <textarea
                            v-model="form.setting"
                            rows="2"
                            class="field"
                        />
                    </label>
                </section>

                <section v-show="tab === 'general'" class="panel space-y-3 p-4">
                    <h2 class="font-display text-lg text-bright">
                        Visibility rules
                    </h2>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Who can see this world's public reader</span
                        >
                        <select v-model="form.visibility" class="field !w-auto">
                            <option value="private">Private — only you</option>
                            <option value="hidden">
                                Unlisted — anyone with the link
                            </option>
                            <option value="public">
                                Public — listed &amp; indexable
                            </option>
                        </select>
                    </label>
                    <div
                        v-if="form.visibility !== 'private'"
                        class="flex items-center gap-2"
                    >
                        <code
                            class="truncate rounded bg-raised px-2 py-1 font-mono text-[11px] text-muted"
                            >{{ settings.public_url }}</code
                        >
                        <button
                            type="button"
                            class="rounded border border-edge3 px-2 py-1 text-[11px] text-muted hover:border-teal hover:text-teal"
                            @click="copyPublicLink"
                        >
                            Copy
                        </button>
                    </div>

                    <div
                        v-if="form.visibility === 'hidden'"
                        class="border-t border-edge3 pt-3"
                    >
                        <span class="mb-1 block text-xs text-faint"
                            >Reader password (optional)</span
                        >
                        <p
                            v-if="settings.reader_password_set"
                            class="mb-2 flex items-center gap-2 text-xs text-teal"
                        >
                            <span
                                class="inline-block h-1.5 w-1.5 rounded-full bg-teal"
                            ></span>
                            A password is set. Enter a new one to change it.
                        </p>
                        <div class="flex flex-wrap items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <input
                                    v-model="passwordForm.reader_password"
                                    type="password"
                                    class="field"
                                    placeholder="At least 4 characters"
                                    @keydown.enter.prevent="saveReaderPassword"
                                />
                                <span
                                    v-if="passwordForm.errors.reader_password"
                                    class="mt-1 block text-xs text-red-400"
                                    >{{
                                        passwordForm.errors.reader_password
                                    }}</span
                                >
                            </div>
                            <button
                                type="button"
                                class="btn-primary shrink-0"
                                :disabled="
                                    passwordForm.processing ||
                                    !passwordForm.reader_password
                                "
                                @click="saveReaderPassword"
                            >
                                Set
                            </button>
                            <button
                                v-if="settings.reader_password_set"
                                type="button"
                                class="shrink-0 rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                                @click="clearReaderPassword"
                            >
                                Remove
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] text-faint">
                            Anyone with the link must enter this before reading.
                            You and your players bypass it.
                        </p>
                    </div>
                </section>

                <section v-show="tab === 'general'" class="panel space-y-3 p-4">
                    <div>
                        <h2 class="font-display text-lg text-bright">
                            Default campaign settings
                        </h2>
                        <p class="text-xs text-faint">
                            Applied to new campaigns and sessions in this world.
                            Each campaign can override its own.
                        </p>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >New campaigns are</span
                        >
                        <select
                            v-model="form.default_campaign_visibility"
                            class="field !w-auto"
                        >
                            <option value="private">Private</option>
                            <option value="hidden">Unlisted</option>
                            <option value="public">Public</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Players who join by link become</span
                        >
                        <select
                            v-model="form.default_join_role"
                            class="field !w-auto"
                        >
                            <option value="player">Player</option>
                            <option value="co-gm">Co-GM</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input
                            v-model="form.default_session_private"
                            type="checkbox"
                        />
                        New sessions are GM-only (private) by default
                    </label>
                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input
                            v-model="form.default_entry_private"
                            type="checkbox"
                        />
                        New entries start GM-only (private) by default
                    </label>
                </section>

                <!-- ===== Reader ===== -->
                <section v-show="tab === 'reader'" class="panel space-y-3 p-4">
                    <div>
                        <h2 class="font-display text-lg text-bright">
                            Reader &amp; player experience
                        </h2>
                        <p class="text-xs text-faint">
                            What players see on this world's public reader.
                        </p>
                    </div>
                    <label
                        v-for="t in readerToggles"
                        :key="t.key"
                        class="flex items-start gap-2 text-sm text-muted"
                    >
                        <input
                            v-model="form[t.key]"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        <span>
                            {{ t.label }}
                            <span
                                v-if="t.hint"
                                class="block text-xs text-faint"
                                >{{ t.hint }}</span
                            >
                        </span>
                    </label>

                    <div>
                        <span class="mb-1 block text-xs text-faint"
                            >Reader accent</span
                        >
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="t in readerThemes"
                                :key="t.value"
                                type="button"
                                class="h-7 w-7 rounded-full border-2 capitalize transition"
                                :style="{ backgroundColor: t.hex }"
                                :class="
                                    form.reader_theme === t.value
                                        ? 'border-white'
                                        : 'border-transparent hover:border-edge3'
                                "
                                :title="t.value"
                                @click="form.reader_theme = t.value"
                            ></button>
                        </div>
                    </div>

                    <div>
                        <span class="mb-1 block text-xs text-faint"
                            >Reader colours</span
                        >
                        <p class="mb-2 text-xs text-faint">
                            These style the published reader only — the builder
                            keeps its own look. Leave a colour blank to use the
                            default palette.
                        </p>
                        <div class="flex flex-wrap gap-5">
                            <div
                                v-for="c in readerColourFields"
                                :key="c.key"
                                class="flex flex-col gap-1"
                            >
                                <span class="text-xs text-faint">{{
                                    c.label
                                }}</span>
                                <div class="flex items-center gap-1.5">
                                    <input
                                        type="color"
                                        class="h-8 w-8 shrink-0 cursor-pointer rounded border border-edge3 bg-transparent p-0"
                                        :value="
                                            /^#[0-9a-fA-F]{6}$/.test(
                                                form[c.key],
                                            )
                                                ? form[c.key]
                                                : c.fallback
                                        "
                                        @input="
                                            form[c.key] = $event.target.value
                                        "
                                    />
                                    <input
                                        v-model="form[c.key]"
                                        type="text"
                                        maxlength="7"
                                        :placeholder="c.fallback"
                                        class="field !w-24 font-mono text-xs"
                                    />
                                    <button
                                        v-if="form[c.key]"
                                        type="button"
                                        class="text-xs text-faint hover:text-ink"
                                        title="Reset to default"
                                        @click="form[c.key] = ''"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="contrastWarning"
                            class="mt-2 text-xs text-amber"
                        >
                            {{ contrastWarning }}
                        </p>
                    </div>

                    <div>
                        <span class="mb-1 block text-xs text-faint"
                            >Custom CSS (advanced)</span
                        >
                        <p class="mb-2 text-xs text-faint">
                            Fine-tune the reader with your own CSS. It's scoped
                            to the reader and sanitised, so it can only restyle
                            these pages. Target the theme variables and semantic
                            classes:
                            <code class="text-muted"
                                >:root &#123; --wb-bg / --wb-heading / --wb-text
                                / --wb-accent &#125;</code
                            >, and
                            <code class="text-muted"
                                >.wb-title .wb-body .wb-muted .wb-accent
                                .wb-card</code
                            >.
                        </p>
                        <textarea
                            v-model="form.reader_css"
                            rows="6"
                            maxlength="20000"
                            spellcheck="false"
                            class="field font-mono text-xs"
                            placeholder=".wb-title { letter-spacing: 0.02em; }
.wb-card { border-radius: 14px; }"
                        ></textarea>
                        <span
                            v-if="form.errors.reader_css"
                            class="mt-1 block text-xs text-red-400"
                            >{{ form.errors.reader_css }}</span
                        >
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Reading font</span
                            >
                            <select
                                v-model="form.reader_font"
                                class="field !w-auto"
                            >
                                <option value="serif">Serif (Spectral)</option>
                                <option value="sans">Sans</option>
                                <option value="mono">Monospace</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Home layout</span
                            >
                            <select
                                v-model="form.reader_home_layout"
                                class="field !w-auto"
                            >
                                <option value="full">
                                    Full — hero &amp; "start here" cards
                                </option>
                                <option value="minimal">
                                    Minimal — hero &amp; recent only
                                </option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Date format</span
                            >
                            <select
                                v-model="form.reader_date_format"
                                class="field !w-auto"
                            >
                                <option value="medium">12 Aug 2026</option>
                                <option value="long">12 August 2026</option>
                                <option value="short">12/08/2026</option>
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Footer line</span
                        >
                        <input
                            v-model="form.reader_footer"
                            class="field"
                            placeholder="© Your name · Homebrew for the Sundered Coast"
                        />
                        <span class="mt-1 block text-xs text-faint"
                            >Replaces the default credit line. The "published
                            with Worldbuilder" note stays below it.</span
                        >
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Analytics</span
                        >
                        <input
                            v-model="form.reader_analytics"
                            class="field"
                            placeholder="Plausible domain (myworld.com) or GA4 ID (G-XXXXXXX)"
                        />
                        <span class="mt-1 block text-xs text-faint"
                            >Loads on the public reader only — never on your own
                            preview. Leave blank for none.</span
                        >
                        <span
                            v-if="form.errors.reader_analytics"
                            class="mt-1 block text-xs text-red-400"
                            >{{ form.errors.reader_analytics }}</span
                        >
                    </label>
                </section>

                <!-- Reader branding (uploads apply immediately) -->
                <section v-show="tab === 'reader'" class="panel space-y-4 p-4">
                    <div>
                        <h2 class="font-display text-lg text-bright">
                            Reader branding
                        </h2>
                        <p class="text-xs text-faint">
                            Shown on your world's public reader. Uploads apply
                            straight away and are added to your media library.
                        </p>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <!-- Logo -->
                        <div>
                            <span class="mb-1.5 block text-xs text-faint"
                                >Header logo</span
                            >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-edge3 bg-raised"
                                >
                                    <img
                                        v-if="settings.logo_url"
                                        :src="settings.logo_url"
                                        class="h-full w-full object-cover"
                                        alt=""
                                    />
                                    <span v-else class="text-[10px] text-faint"
                                        >None</span
                                    >
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <label
                                        class="cursor-pointer rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                                    >
                                        {{
                                            settings.logo_url
                                                ? "Replace"
                                                : "Upload"
                                        }}
                                        <input
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            @change="
                                                uploadBranding(logoForm, $event)
                                            "
                                        />
                                    </label>
                                    <button
                                        v-if="settings.logo_url"
                                        type="button"
                                        class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                                        @click="removeBranding('logo')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                            <span
                                v-if="logoForm.errors.file"
                                class="mt-1 block text-xs text-red-400"
                                >{{ logoForm.errors.file }}</span
                            >
                        </div>

                        <!-- Favicon -->
                        <div>
                            <span class="mb-1.5 block text-xs text-faint"
                                >Favicon</span
                            >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-edge3 bg-raised"
                                >
                                    <img
                                        v-if="settings.favicon_url"
                                        :src="settings.favicon_url"
                                        class="h-full w-full object-cover"
                                        alt=""
                                    />
                                    <span v-else class="text-[10px] text-faint"
                                        >None</span
                                    >
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <label
                                        class="cursor-pointer rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                                    >
                                        {{
                                            settings.favicon_url
                                                ? "Replace"
                                                : "Upload"
                                        }}
                                        <input
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            @change="
                                                uploadBranding(
                                                    faviconForm,
                                                    $event,
                                                )
                                            "
                                        />
                                    </label>
                                    <button
                                        v-if="settings.favicon_url"
                                        type="button"
                                        class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                                        @click="removeBranding('favicon')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                            <span class="mt-1 block text-[11px] text-faint"
                                >Shown in the browser tab. A small square PNG
                                works best.</span
                            >
                            <span
                                v-if="faviconForm.errors.file"
                                class="mt-1 block text-xs text-red-400"
                                >{{ faviconForm.errors.file }}</span
                            >
                        </div>
                    </div>

                    <!-- Banner -->
                    <div>
                        <span class="mb-1.5 block text-xs text-faint"
                            >Hero banner</span
                        >
                        <div
                            class="overflow-hidden rounded-md border border-edge3 bg-raised"
                            style="aspect-ratio: 16 / 5"
                        >
                            <img
                                v-if="settings.banner_url"
                                :src="settings.banner_url"
                                class="h-full w-full object-cover"
                                alt=""
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center text-[11px] text-faint"
                            >
                                No banner
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label
                                class="cursor-pointer rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                            >
                                {{ settings.banner_url ? "Replace" : "Upload" }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadBranding(bannerForm, $event)"
                                />
                            </label>
                            <button
                                v-if="settings.banner_url"
                                type="button"
                                class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                                @click="removeBranding('banner')"
                            >
                                Remove
                            </button>
                        </div>
                        <span
                            v-if="bannerForm.errors.file"
                            class="mt-1 block text-xs text-red-400"
                            >{{ bannerForm.errors.file }}</span
                        >
                    </div>

                    <!-- Social share image -->
                    <div>
                        <span class="mb-1.5 block text-xs text-faint"
                            >Social share image</span
                        >
                        <div
                            class="max-w-md overflow-hidden rounded-md border border-edge3 bg-raised"
                            style="aspect-ratio: 1.91 / 1"
                        >
                            <img
                                v-if="settings.og_url"
                                :src="settings.og_url"
                                class="h-full w-full object-cover"
                                alt=""
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center text-[11px] text-faint"
                            >
                                Falls back to the banner
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label
                                class="cursor-pointer rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                            >
                                {{ settings.og_url ? "Replace" : "Upload" }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadBranding(ogForm, $event)"
                                />
                            </label>
                            <button
                                v-if="settings.og_url"
                                type="button"
                                class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                                @click="removeBranding('og')"
                            >
                                Remove
                            </button>
                        </div>
                        <span class="mt-1 block text-[11px] text-faint"
                            >Used when the reader is shared on social media. Aim
                            for 1200×630.</span
                        >
                        <span
                            v-if="ogForm.errors.file"
                            class="mt-1 block text-xs text-red-400"
                            >{{ ogForm.errors.file }}</span
                        >
                    </div>
                </section>

                <!-- ===== Play & recaps ===== -->
                <section v-show="tab === 'play'" class="panel space-y-3 p-4">
                    <div>
                        <h2 class="font-display text-lg text-bright">
                            Play &amp; recaps
                        </h2>
                        <p class="text-xs text-faint">
                            Defaults for new campaigns and their session recaps.
                        </p>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Game system</span
                        >
                        <input
                            v-model="form.default_game_system"
                            class="field !w-auto"
                            placeholder="e.g. D&amp;D 5e, PF2e"
                        />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Recap detail</span
                        >
                        <select
                            v-model="form.default_recap_detail"
                            class="field !w-auto"
                        >
                            <option value="comprehensive">Comprehensive</option>
                            <option value="brief">Brief</option>
                        </select>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-muted">
                        <input
                            v-model="form.recap_auto_publish"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        <span>
                            Publish recaps to players automatically
                            <span class="block text-xs text-faint"
                                >Off means a finished recap waits for you to
                                publish it.</span
                            >
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-muted">
                        <input
                            v-model="form.reader_number_sessions"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        <span>
                            Number sessions on the timeline
                            <span class="block text-xs text-faint"
                                >Shows "Session 1, 2, 3…" on the reader's
                                sessions list.</span
                            >
                        </span>
                    </label>
                </section>

                <div
                    v-show="formTabs.includes(tab)"
                    class="flex items-center gap-3"
                >
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? "Saving…" : "Save settings" }}
                    </button>
                    <span
                        v-if="form.recentlySuccessful"
                        class="text-xs text-teal"
                        >Saved.</span
                    >
                </div>
            </form>

            <!-- Reader navigation (own save) -->
            <section v-show="tab === 'reader'" class="panel space-y-4 p-4">
                <div>
                    <h2 class="font-display text-lg text-bright">Navigation</h2>
                    <p class="text-xs text-faint">
                        Reorder or hide the reader's sections, give each a cover
                        image for its "Start here" card, and add your own links to
                        the nav.
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <div
                        v-for="(row, i) in navRows"
                        :key="row.slug"
                        class="flex items-center gap-3 rounded-md border border-edge3 px-3 py-2"
                    >
                        <div class="flex flex-col leading-none">
                            <button
                                type="button"
                                class="text-faint hover:text-teal disabled:opacity-30"
                                :disabled="i === 0"
                                title="Move up"
                                @click="moveNav(i, -1)"
                            >
                                ▲
                            </button>
                            <button
                                type="button"
                                class="text-faint hover:text-teal disabled:opacity-30"
                                :disabled="i === navRows.length - 1"
                                title="Move down"
                                @click="moveNav(i, 1)"
                            >
                                ▼
                            </button>
                        </div>
                        <span
                            class="flex-1 text-sm"
                            :class="
                                row.hidden
                                    ? 'text-faint line-through'
                                    : 'text-ink'
                            "
                            >{{ row.label }}</span
                        >
                        <div class="flex items-center gap-2">
                            <div
                                class="h-8 w-12 shrink-0 overflow-hidden rounded border border-edge3 bg-raised bg-cover bg-center"
                                :style="
                                    sectionImageUrl(row.slug)
                                        ? {
                                              backgroundImage: `url(${sectionImageUrl(
                                                  row.slug,
                                              )})`,
                                          }
                                        : {}
                                "
                            ></div>
                            <label
                                class="cursor-pointer text-xs text-muted hover:text-teal"
                                :title="
                                    sectionImageUrl(row.slug)
                                        ? 'Replace image'
                                        : 'Upload image'
                                "
                            >
                                {{ sectionImageUrl(row.slug) ? "Replace" : "Image" }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadSectionImage(row.slug, $event)"
                                />
                            </label>
                            <button
                                v-if="sectionImageUrl(row.slug)"
                                type="button"
                                class="text-xs text-faint hover:text-red-400"
                                title="Remove image"
                                @click="removeSectionImage(row.slug)"
                            >
                                ✕
                            </button>
                        </div>
                        <label
                            class="flex items-center gap-1.5 text-xs text-muted"
                        >
                            <input v-model="row.hidden" type="checkbox" />
                            Hidden
                        </label>
                    </div>
                    <span
                        v-if="sectionImageForm.errors.file"
                        class="text-xs text-red-400"
                        >{{ sectionImageForm.errors.file }}</span
                    >
                </div>

                <div>
                    <span class="mb-1 block text-xs text-faint"
                        >Custom links</span
                    >
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="(l, i) in navLinks"
                            :key="i"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <input
                                v-model="l.label"
                                class="field !w-40"
                                placeholder="Label"
                            />
                            <input
                                v-model="l.url"
                                type="url"
                                class="field min-w-0 flex-1"
                                placeholder="https://…"
                            />
                            <button
                                type="button"
                                class="shrink-0 text-faint hover:text-red-400"
                                title="Remove"
                                @click="removeNavLink(i)"
                            >
                                ✕
                            </button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="mt-2 text-sm text-teal hover:underline"
                        @click="addNavLink"
                    >
                        + Add link
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="navForm.processing"
                        @click="saveNav"
                    >
                        Save navigation
                    </button>
                    <span
                        v-if="navForm.recentlySuccessful"
                        class="text-xs text-teal"
                        >Saved.</span
                    >
                </div>
            </section>

            <!-- ===== Integrations ===== -->
            <section
                v-show="tab === 'integrations'"
                class="panel space-y-3 p-4"
            >
                <div>
                    <h2 class="font-display text-lg text-bright">Discord</h2>
                    <p class="text-xs text-faint">
                        Post to a channel whenever a session recap is published.
                        Paste a channel's webhook URL (Server Settings →
                        Integrations → Webhooks).
                    </p>
                </div>
                <p
                    v-if="settings.discord_connected"
                    class="flex items-center gap-2 text-sm text-teal"
                >
                    <span
                        class="inline-block h-2 w-2 rounded-full bg-teal"
                    ></span>
                    Connected. Paste a new URL below to replace it.
                </p>
                <form
                    class="flex flex-wrap items-start gap-2"
                    @submit.prevent="saveDiscord"
                >
                    <div class="min-w-0 flex-1">
                        <input
                            v-model="discordForm.discord_webhook"
                            type="url"
                            class="field"
                            placeholder="https://discord.com/api/webhooks/…"
                        />
                        <span
                            v-if="discordForm.errors.discord_webhook"
                            class="mt-1 block text-xs text-red-400"
                            >{{ discordForm.errors.discord_webhook }}</span
                        >
                    </div>
                    <button
                        type="submit"
                        class="btn-primary shrink-0"
                        :disabled="
                            discordForm.processing ||
                            !discordForm.discord_webhook
                        "
                    >
                        {{ settings.discord_connected ? "Replace" : "Connect" }}
                    </button>
                    <button
                        v-if="settings.discord_connected"
                        type="button"
                        class="shrink-0 rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                        @click="disconnectDiscord"
                    >
                        Disconnect
                    </button>
                </form>
            </section>

            <Link
                v-show="tab === 'integrations'"
                :href="links.webhooks"
                class="panel flex items-center justify-between gap-3 p-4 transition hover:border-amber"
            >
                <div>
                    <div class="text-sm text-bright">Webhooks</div>
                    <p class="mt-1 text-xs text-muted">
                        Send signed events (recap published, session scheduled,
                        RSVPs) to your own endpoints.
                    </p>
                </div>
                <span class="shrink-0 text-muted">→</span>
            </Link>

            <section
                v-show="tab === 'integrations'"
                class="panel space-y-3 p-4"
            >
                <div>
                    <h2 class="font-display text-lg text-bright">
                        Support link
                    </h2>
                    <p class="text-xs text-faint">
                        Add a Patreon, Ko-fi or similar link — shown as a button
                        in the reader footer.
                    </p>
                </div>
                <form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="save"
                >
                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-xs text-faint">Link</span>
                        <input
                            v-model="form.support_url"
                            type="url"
                            class="field"
                            placeholder="https://patreon.com/yourpage"
                        />
                        <span
                            v-if="form.errors.support_url"
                            class="mt-1 block text-xs text-red-400"
                            >{{ form.errors.support_url }}</span
                        >
                    </label>
                    <label>
                        <span class="mb-1 block text-xs text-faint"
                            >Button label</span
                        >
                        <input
                            v-model="form.support_label"
                            class="field !w-auto"
                            placeholder="Support this world"
                        />
                    </label>
                    <button
                        type="submit"
                        class="btn-primary shrink-0"
                        :disabled="form.processing"
                    >
                        Save
                    </button>
                </form>
            </section>

            <!-- ===== Advanced ===== -->
            <template v-if="tab === 'advanced'">
                <section class="grid gap-3 sm:grid-cols-3">
                    <Link
                        v-if="isOwner"
                        :href="links.collaborators"
                        class="panel p-4 transition hover:border-amber"
                    >
                        <div class="text-sm text-bright">Collaborators</div>
                        <p class="mt-1 text-xs text-muted">
                            Invite co-authors to edit this world.
                        </p>
                    </Link>
                    <Link
                        v-if="isOwner"
                        :href="links.domain"
                        class="panel p-4 transition hover:border-amber"
                    >
                        <div class="text-sm text-bright">Custom domain</div>
                        <p class="mt-1 text-xs text-muted">
                            <span v-if="domain.custom_domain">
                                {{ domain.custom_domain }} ·
                                {{
                                    domain.verified ? "verified" : "unverified"
                                }}
                            </span>
                            <span v-else
                                >Serve the reader on your own domain.</span
                            >
                        </p>
                    </Link>
                    <Link
                        :href="links.media"
                        class="panel p-4 transition hover:border-amber"
                    >
                        <div class="text-sm text-bright">Media library</div>
                        <p class="mt-1 text-xs text-muted">
                            Images and files used across the world.
                        </p>
                    </Link>
                    <Link
                        :href="links.attributes"
                        class="panel p-4 transition hover:border-amber"
                    >
                        <div class="text-sm text-bright">Fields</div>
                        <p class="mt-1 text-xs text-muted">
                            Customise the quick-facts fields for your entries.
                        </p>
                    </Link>
                    <Link
                        :href="links.templates"
                        class="panel p-4 transition hover:border-amber"
                    >
                        <div class="text-sm text-bright">Templates</div>
                        <p class="mt-1 text-xs text-muted">
                            Reader layouts to apply to individual entries.
                        </p>
                    </Link>
                </section>

                <section
                    v-if="isOwner"
                    class="rounded-lg border border-red-500/30 bg-red-500/5 p-4"
                >
                    <h2 class="font-display text-lg text-red-300">
                        Danger zone
                    </h2>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <p class="text-xs text-muted">
                            Permanently delete this world, its campaigns,
                            entries and media.
                        </p>
                        <button
                            class="shrink-0 rounded border border-red-500/40 px-3 py-1.5 text-sm text-red-300 hover:bg-red-500/10"
                            @click="destroy"
                        >
                            Delete world
                        </button>
                    </div>
                </section>
            </template>
        </div>
    </WorldLayout>
</template>
