<script setup>
import EntrySettings from "@/Components/EntrySettings.vue";
import { useTextareaAutocomplete } from "@/composables/useTextareaAutocomplete";
import { highlightBrewSource } from "@/lib/highlight";
import HomebrewView from "@/Components/HomebrewView.vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { marked } from "marked";
import { computed, nextTick, reactive, ref, watch } from "vue";

const aiName = computed(() => usePage().props.ai?.name ?? "Muse");

const props = defineProps({
    campaign: Object,
    document: Object,
    // Other entries in this world, for [[wiki-link]] autocomplete ({id, title, kind, kindLabel}).
    entries: { type: Array, default: () => [] },
    // Compendium entries available to embed, for autocomplete ({id, name, type, typeLabel, token}).
    compendium: { type: Array, default: () => [] },
    // Party characters, for @-mentions ({id, name, slug}).
    characters: { type: Array, default: () => [] },
    // The same entries with render payloads ({id, name, item_type, typeLabel, block?, document?}) so the preview draws full cards.
    embeds: { type: Array, default: () => [] },
    // Spells ({id, name, summary}) so a monster's spellcasting entries can hover-preview their spell.
    spells: { type: Array, default: () => [] },
    allTags: { type: Array, default: () => [] },
    // Route names so the same editor can drive documents or sessions.
    updateRoute: { type: String, default: "documents.update" },
    aiRoute: { type: String, default: "documents.ai" },
    backRoute: { type: String, default: "worlds.show" },
    // Route params for the back link. Defaults to the campaign id; nested routes pass [world, campaign].
    backParams: { type: [Array, Number, String], default: null },
    // Public reader URL for this entry, when the world is published (documents only; sessions leave this null).
    viewUrl: { type: String, default: null },
    templates: { type: Array, default: () => [] },
    access: {
        type: Object,
        default: () => ({ has_password: false, share_url: null }),
    },
    // The rough-notes → recap write-up flow only applies to document-kind sessions, not the sessions table.
    writeUpTools: { type: Boolean, default: true },
});

const isSession = computed(() => props.document.kind === "session");

const form = reactive({
    title: props.document.title,
    content: props.document.content ?? "",
    summary: props.document.summary ?? "",
    is_private: props.document.is_private,
    is_featured: props.document.is_featured,
    hide_from_search: props.document.hide_from_search,
    cover_mode: props.document.cover_mode ?? "default",
    template_id: props.document.template_id ?? null,
    accent: props.document.accent ?? "",
    publish_at: (props.document.publish_at ?? "").slice(0, 16),
    comments_enabled: props.document.comments_enabled ?? true,
    show_toc: props.document.show_toc ?? false,
    related_ids: [...(props.document.related_ids ?? [])],
    data: props.document.data ?? {},
    tags: [...(props.document.tags ?? [])],
});

const mode = ref("split"); // split | preview
const tab = ref("entry"); // entry | settings
const showNotes = ref(false); // session rough-notes modal
const showClaude = ref(true); // the Claude panel can be collapsed to the side
const saved = ref(true);
let timer = null;

const save = () => {
    router.put(
        route(props.updateRoute, props.document.id),
        {
            title: form.title,
            content: form.content,
            summary: form.summary,
            is_private: form.is_private,
            is_featured: form.is_featured,
            hide_from_search: form.hide_from_search,
            cover_mode: form.cover_mode,
            template_id: form.template_id,
            accent: form.accent || null,
            publish_at: form.publish_at || null,
            comments_enabled: form.comments_enabled,
            show_toc: form.show_toc,
            related_ids: form.related_ids,
            data: form.data,
            tags: form.tags,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (saved.value = true),
        },
    );
};
watch(
    form,
    () => {
        saved.value = false;
        clearTimeout(timer);
        timer = setTimeout(save, 1200);
    },
    { deep: true },
);

/* ---- source pane helpers ---- */
const textarea = ref(null);
const gutter = ref(null);
const highlightLayer = ref(null);
const lines = computed(() => (form.content ?? "").split("\n"));
const stats = computed(() => {
    const trimmed = (form.content ?? "").trim();
    return {
        lines: lines.value.length,
        words: trimmed ? trimmed.split(/\s+/).length : 0,
    };
});
// Coloured highlight layer rendered behind the (transparent) textarea. Trailing newline keeps the
// final line's height in step with the textarea.
const highlighted = computed(() => `${highlightBrewSource(form.content ?? "")}\n`);

// Keep the line-number gutter and highlight layer aligned with the textarea as it scrolls.
const syncGutter = () => {
    if (textarea.value && gutter.value)
        gutter.value.scrollTop = textarea.value.scrollTop;
    if (textarea.value && highlightLayer.value) {
        highlightLayer.value.scrollTop = textarea.value.scrollTop;
        highlightLayer.value.scrollLeft = textarea.value.scrollLeft;
    }
};

const insertSnippet = (snippet) => {
    openMenu.value = null;
    const el = textarea.value;
    const caret = el?.selectionStart ?? form.content.length;
    form.content =
        form.content.slice(0, caret) + snippet + form.content.slice(caret);
    nextTick(() => {
        const pos = caret + snippet.length;
        // preventScroll stops the browser jumping the view to the textarea/caret (was scrolling to the bottom).
        el?.focus({ preventScroll: true });
        el?.setSelectionRange(pos, pos);
        syncGutter();
    });
};

/* ---- Insert menus (Homebrewery-style) ---- */
const openMenu = ref(null); // 'text' | 'blocks' | 'world' | null
const toggleMenu = (name) => {
    openMenu.value = openMenu.value === name ? null : name;
};

const TEXT_SNIPPETS = [
    ["New page", "\n\\page\n"],
    ["Column break", "\n\\column\n"],
    ["Wide block", "\n{{wide\nContent that spans both columns.\n}}\n"],
    ["Horizontal rule", "\n---\n"],
    ["Vertical spacing", "\n::\n"],
    [
        "Definition list",
        "\nDifficult Terrain\n: Costs 2 feet of movement for every 1 foot travelled.\n",
    ],
    [
        "Highlight / super / sub",
        "\nRoll ==with advantage==. Deal 1d6^2^ or H~2~O.\n",
    ],
    ["Table", "\n| Ability | Score |\n| --- | --- |\n| STR | 10 |\n"],
    [
        "Table with merged cells",
        "\n| Damage | Type | Save |\n| :-: | :-: | :-: |\n| 8d6 | Fire | DEX |\n| ^ | Cold | STR |\n| Heavy blow || Prone |\n",
    ],
];
const BLOCK_SNIPPETS = [
    ["Note", "\n{{note\nSomething worth noticing.\n}}\n"],
    ["Framed note", "\n{{note,frame\nAn ornate, framed aside.\n}}\n"],
    [
        "Descriptive box",
        "\n{{descriptive\nRead-aloud text for the players.\n}}\n",
    ],
    ["Quote", "\n> A memorable line, attributed to someone.\n"],
    [
        "Secret (GM only)",
        "\n{{secret}}Hidden from players until you reveal it.{{/}}\n",
    ],
    [
        "Monster stat block",
        "\n{{monster\n## Creature Name\n*Medium beast, unaligned*\n\n**Armor Class** 12\n**Hit Points** 22 (4d8 + 4)\n**Speed** 30 ft.\n}}\n",
    ],
    [
        "Wide stat block",
        "\n{{monster,wide\n## Creature Name\n*Large beast, unaligned*\n}}\n",
    ],
    [
        "Spell",
        "\n{{spell\n#### Spell Name\n*1st-level evocation*\n\n**Casting Time** 1 action\n**Range** 60 feet\n**Components** V, S\n**Duration** Instantaneous\n}}\n",
    ],
    [
        "Magic item",
        "\n{{item\n#### Item Name\n*Wondrous item, rare*\n\nWhat it does.\n}}\n",
    ],
];
const IMAGE_SNIPPETS = [
    [
        "Image",
        "\n<img src='https://i.imgur.com/YOUR_IMAGE.png' style='width:280px' />\n",
    ],
    [
        "Positioned image",
        "\n<img src='https://i.imgur.com/YOUR_IMAGE.png' style='position:absolute;top:60px;left:10px;width:100%' />\n",
    ],
    [
        "Background image",
        "\n<img src='https://i.imgur.com/YOUR_IMAGE.png' style='position:absolute;top:0;left:0;width:100%' />\n",
    ],
    [
        "Blended image (darken)",
        "\n<img src='https://i.imgur.com/YOUR_IMAGE.png' style='position:absolute;bottom:10px;right:0;width:60%;mix-blend-mode:darken' />\n",
    ],
];
const PAGE_SNIPPETS = [
    [
        "Cover page",
        "\n\\page\n<div class='titlePage'>\n\n# Book Title\n\n## An Adventure for Levels 1–5\n\n___\n\nby Your Name\n\n</div>\n",
    ],
    [
        "Part / chapter page",
        "\n\\page\n<div class='partPage'>\n\n# Part One\n\n## The Journey Begins\n\n</div>\n",
    ],
    ["Page number", "\n<div class='pageNumber auto'></div>\n"],
    ["Footnote", "\n<div class='footnote'>PART 1 | SECTION</div>\n"],
    [
        "Centered block",
        "\n<div style='text-align:center'>\n\n# Title\n\n</div>\n",
    ],
    ["Table of contents", "\n## Contents\n\n{{toc}}\n"],
];

/* ---- WorldBuilder Info: search the compendium and drop in an embed ---- */
const worldSearch = ref("");
const worldResults = computed(() => {
    const query = worldSearch.value.trim().toLowerCase();
    const items = query
        ? props.compendium.filter((c) => c.name.toLowerCase().includes(query))
        : props.compendium;
    return items.slice(0, 40);
});
const insertEmbed = (item) => insertSnippet(`\n${item.token}\n`);

/* ---- inline autocomplete: [[ entries · [statblock= compendium · @ both ---- */
const entrySuggestions = (query) => {
    const q = query.split("|")[0].trim().toLowerCase();
    return props.entries
        .filter((e) => !q || e.title.toLowerCase().includes(q))
        .map((e) => ({
            key: `e${e.id}`,
            label: e.title,
            hint: e.kindLabel,
            insert: `[[${e.title}]]`,
        }));
};
const compendiumSuggestions = (query, type) => {
    const q = query.trim().toLowerCase();
    return props.compendium
        .filter(
            (c) =>
                (!type || c.type === type) &&
                (!q || c.name.toLowerCase().includes(q)),
        )
        .map((c) => ({
            key: `c${c.id}`,
            label: c.name,
            hint: c.typeLabel,
            insert: c.token,
        }));
};
// @-mentions resolve to a party character and render as a chip: @[Name](char:id).
const characterSuggestions = (query) => {
    const q = query.trim().toLowerCase();
    return props.characters
        .filter((c) => !q || c.name.toLowerCase().includes(q))
        .map((c) => ({
            key: `ch${c.id}`,
            label: c.name,
            hint: "Character",
            insert: `@[${c.name}](char:${c.id})`,
        }));
};
const precededByBoundary = (charBefore) =>
    charBefore === "" || /\s/.test(charBefore);

// Compendium embeds are authored as {{monster=id}}, {{armor=id}}, … — each type opens its own autocomplete.
const EMBED_TYPES = [
    "monster",
    "spell",
    "item",
    "magicitem",
    "equipment",
    "condition",
    "race",
    "feat",
];

const {
    state: ac,
    items: acItems,
    accept: acceptAutocomplete,
    onInput,
    onKeydown,
    onKeyup,
    onClick: onTextareaClick,
    onBlur,
} = useTextareaAutocomplete({
    textarea,
    content: {
        get: () => form.content,
        set: (value) => {
            form.content = value ?? "";
        },
    },
    triggers: [
        { prefix: "[[", closers: ["]]", "\n"], items: entrySuggestions },
        ...EMBED_TYPES.map((type) => ({
            prefix: `{{${type}=`,
            closers: ["}}", ",", "\n"],
            items: (query) => compendiumSuggestions(query, type),
        })),
        {
            prefix: "@",
            closers: ["\n"],
            guard: precededByBoundary,
            items: (q) => [
                ...characterSuggestions(q),
                ...entrySuggestions(q),
                ...compendiumSuggestions(q),
            ],
        },
    ],
});

// After an edit the highlight layer's innerHTML re-renders (resetting its scroll to the top) while
// the textarea keeps its scroll position — re-sync so the coloured text stays under the caret.
const onSourceInput = (event) => {
    onInput(event);
    nextTick(syncGutter);
};

/* ---- Homebrewery preview is rendered by <HomebrewView> ---- */
const renderMd = (text) => marked.parse(text ?? "", { breaks: true });

/* ---- Style editor (per-brew CSS, scoped to the page) ---- */
const showCss = ref(false);
const cssField = computed({
    get: () => form.data.css ?? "",
    set: (value) => {
        form.data = { ...form.data, css: value };
    },
});
const themeField = computed({
    get: () => form.data.theme ?? "classic",
    set: (value) => {
        form.data = { ...form.data, theme: value };
    },
});

const cols = computed(() => {
    const claude = showClaude.value ? " 340px" : " 2.5rem";
    return mode.value === "split"
        ? `minmax(0,1fr) minmax(0,1fr)${claude}`
        : `minmax(0,1fr)${claude}`;
});

/* ---- Claude chat ---- */
const messages = ref([]);
const input = ref("");
const asking = ref(false);
const chatError = ref("");
const chatBottom = ref(null);

const send = async (promptText) => {
    const text = (promptText ?? input.value).trim();
    if (!text || asking.value) return;
    input.value = "";
    chatError.value = "";
    const history = messages.value.map((m) => ({
        role: m.role,
        content: m.content,
    }));
    messages.value.push({ role: "user", content: text });
    asking.value = true;
    await nextTick(() => chatBottom.value?.scrollIntoView());
    try {
        const res = await window.axios.post(
            route(props.aiRoute, props.document.id),
            {
                prompt: text,
                content: form.content,
                history,
            },
        );
        messages.value.push({ role: "assistant", content: res.data.reply });
    } catch (e) {
        chatError.value = e.response?.data?.message ?? "The AI request failed.";
    } finally {
        asking.value = false;
        await nextTick(() => chatBottom.value?.scrollIntoView());
    }
};
const applyReply = (content) => {
    form.content = content ?? "";
};
const clearChat = () => {
    messages.value = [];
};

/* ---- session write-up ---- */
const notes = computed({
    get: () => form.data.raw_notes ?? "",
    set: (v) => (form.data = { ...form.data, raw_notes: v }),
});
const writingUp = ref(false);
const writeUp = async () => {
    if (!notes.value.trim() || writingUp.value) return;
    writingUp.value = true;
    try {
        // Creates a new, linked recap article (the session itself is left untouched) and opens it.
        const res = await window.axios.post(
            route("documents.writeup", props.document.id),
            { notes: notes.value },
        );
        router.visit(res.data.redirect);
    } catch (e) {
        chatError.value = e.response?.data?.message ?? "The AI request failed.";
        writingUp.value = false;
    }
};
</script>

<template>
    <div class="flex h-full flex-col bg-night">
        <!-- Top bar -->
        <div
            class="flex flex-wrap items-center gap-3 border-b border-edge bg-surface px-4 py-2.5"
        >
            <!-- Breadcrumb: back + title + open-in-new-tab, grouped -->
            <div class="flex items-center gap-3">
                <Link
                    :href="route(backRoute, backParams ?? campaign.id)"
                    class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                    >← {{ campaign.name }}</Link
                >
                <div class="flex items-center gap-2">
                    <input
                        v-model="form.title"
                        :size="
                            Math.min(
                                Math.max((form.title || '').length + 1, 6),
                                40,
                            )
                        "
                        class="border-0 bg-transparent px-0 font-display text-lg text-bright focus:ring-0"
                    />
                    <a
                        v-if="viewUrl"
                        :href="viewUrl"
                        target="_blank"
                        rel="noopener"
                        class="flex-shrink-0 text-muted hover:text-amber"
                        title="Open in new tab"
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
                            <path
                                d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"
                            />
                            <polyline points="15 3 21 3 21 9" />
                            <line x1="10" y1="14" x2="21" y2="3" />
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Center: Entry / Settings -->
            <div
                class="mx-auto flex items-center gap-1 rounded-md border border-edge3 p-0.5"
            >
                <button
                    v-for="t in ['entry', 'settings']"
                    :key="t"
                    class="rounded px-3 py-1 text-xs capitalize"
                    :class="
                        tab === t
                            ? 'bg-raised text-bright'
                            : 'text-muted hover:text-ink'
                    "
                    @click="tab = t"
                >
                    {{ t }}
                </button>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <button
                    v-if="isSession && writeUpTools"
                    class="flex items-center gap-1.5 rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                    title="Session notes"
                    @click="showNotes = true"
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
                        <path
                            d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
                        />
                        <path d="M14 2v6h6" />
                        <line x1="8" y1="13" x2="16" y2="13" />
                        <line x1="8" y1="17" x2="13" y2="17" />
                    </svg>
                    <span>Notes</span>
                </button>
                <span
                    class="text-xs"
                    :class="saved ? 'text-faint' : 'text-amber'"
                    >{{ saved ? "✓ Saved" : "Saving…" }}</span
                >
            </div>
        </div>

        <!-- Session rough-notes modal -->
        <div
            v-if="showNotes && isSession && writeUpTools"
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 pt-24"
            @click.self="showNotes = false"
        >
            <div
                class="w-full max-w-lg rounded-lg border border-edge2 bg-surface p-5 shadow-2xl"
            >
                <div class="mb-3 flex items-center justify-between">
                    <div class="font-display text-lg text-bright">
                        What happened in this session?
                    </div>
                    <button
                        class="text-muted hover:text-ink"
                        aria-label="Close"
                        @click="showNotes = false"
                    >
                        ✕
                    </button>
                </div>
                <textarea
                    v-model="notes"
                    rows="6"
                    class="field"
                    placeholder="Rough notes — bullet points are fine."
                />
                <div class="mt-1 text-xs text-faint">
                    These notes stay on this session. {{ aiName }} writes them
                    up as a new, linked recap article and opens it.
                </div>
                <div class="mt-3 flex justify-end">
                    <button
                        class="btn-primary"
                        :disabled="writingUp || !notes.trim()"
                        @click="writeUp"
                    >
                        ✻ {{ writingUp ? "Writing…" : "Write it up" }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Settings tab -->
        <EntrySettings
            v-if="tab === 'settings'"
            v-model:title="form.title"
            v-model:summary="form.summary"
            v-model:is-private="form.is_private"
            v-model:tags="form.tags"
            v-model:is-featured="form.is_featured"
            v-model:hide-from-search="form.hide_from_search"
            v-model:cover-mode="form.cover_mode"
            v-model:accent="form.accent"
            v-model:publish-at="form.publish_at"
            v-model:comments-enabled="form.comments_enabled"
            v-model:show-toc="form.show_toc"
            v-model:related-ids="form.related_ids"
            v-model:template-id="form.template_id"
            class="min-h-0 flex-1"
            :document="document"
            :all-tags="allTags"
            :entries="entries"
            :templates="templates"
            :view-url="viewUrl"
            :access="access"
        />

        <!-- Insert toolbar -->
        <div
            v-show="tab === 'entry'"
            class="relative z-20 flex items-center gap-1 border-b border-edge bg-surface px-4 py-1.5 text-[13px]"
        >
            <!-- Text -->
            <div class="relative">
                <button
                    class="rounded px-2.5 py-1 text-muted hover:bg-raised hover:text-ink"
                    :class="openMenu === 'text' && 'bg-raised text-ink'"
                    @click.stop="toggleMenu('text')"
                >
                    ✎ Text
                </button>
                <div
                    v-if="openMenu === 'text'"
                    class="absolute left-0 top-full z-30 mt-1 w-56 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                >
                    <button
                        v-for="[label, snip] in TEXT_SNIPPETS"
                        :key="label"
                        class="block w-full px-3 py-1.5 text-left text-muted hover:bg-raised hover:text-ink"
                        @click="insertSnippet(snip)"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>
            <!-- Blocks -->
            <div class="relative">
                <button
                    class="rounded px-2.5 py-1 text-muted hover:bg-raised hover:text-ink"
                    :class="openMenu === 'blocks' && 'bg-raised text-ink'"
                    @click.stop="toggleMenu('blocks')"
                >
                    ▤ Blocks
                </button>
                <div
                    v-if="openMenu === 'blocks'"
                    class="absolute left-0 top-full z-30 mt-1 w-56 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                >
                    <button
                        v-for="[label, snip] in BLOCK_SNIPPETS"
                        :key="label"
                        class="block w-full px-3 py-1.5 text-left text-muted hover:bg-raised hover:text-ink"
                        @click="insertSnippet(snip)"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>
            <!-- Images -->
            <div class="relative">
                <button
                    class="rounded px-2.5 py-1 text-muted hover:bg-raised hover:text-ink"
                    :class="openMenu === 'images' && 'bg-raised text-ink'"
                    @click.stop="toggleMenu('images')"
                >
                    ▦ Images
                </button>
                <div
                    v-if="openMenu === 'images'"
                    class="absolute left-0 top-full z-30 mt-1 w-56 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                >
                    <button
                        v-for="[label, snip] in IMAGE_SNIPPETS"
                        :key="label"
                        class="block w-full px-3 py-1.5 text-left text-muted hover:bg-raised hover:text-ink"
                        @click="insertSnippet(snip)"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>
            <!-- Page -->
            <div class="relative">
                <button
                    class="rounded px-2.5 py-1 text-muted hover:bg-raised hover:text-ink"
                    :class="openMenu === 'page' && 'bg-raised text-ink'"
                    @click.stop="toggleMenu('page')"
                >
                    ❏ Page
                </button>
                <div
                    v-if="openMenu === 'page'"
                    class="absolute left-0 top-full z-30 mt-1 w-56 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                >
                    <button
                        v-for="[label, snip] in PAGE_SNIPPETS"
                        :key="label"
                        class="block w-full px-3 py-1.5 text-left text-muted hover:bg-raised hover:text-ink"
                        @click="insertSnippet(snip)"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>
            <!-- WorldBuilder Info (searchable compendium embeds) -->
            <div class="relative">
                <button
                    class="rounded px-2.5 py-1 text-muted hover:bg-raised hover:text-ink"
                    :class="openMenu === 'world' && 'bg-raised text-ink'"
                    @click.stop="toggleMenu('world')"
                >
                    ✦ WorldBuilder Info
                </button>
                <div
                    v-if="openMenu === 'world'"
                    class="absolute left-0 top-full z-30 mt-1 w-72 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                    @click.stop
                >
                    <div class="px-2 pb-1 pt-0.5">
                        <input
                            v-model="worldSearch"
                            class="field !py-1.5 !text-[13px]"
                            placeholder="Search the compendium…"
                            autofocus
                        />
                    </div>
                    <div class="max-h-72 overflow-auto">
                        <button
                            v-for="c in worldResults"
                            :key="c.id"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-raised"
                            @click="insertEmbed(c)"
                        >
                            <span class="min-w-0 flex-1 truncate text-ink">{{
                                c.name
                            }}</span>
                            <span
                                class="font-mono text-[10px] uppercase tracking-[0.06em] text-faint"
                                >{{ c.typeLabel }}</span
                            >
                        </button>
                        <p
                            v-if="!worldResults.length"
                            class="px-3 py-3 text-[13px] text-faint"
                        >
                            {{
                                compendium.length
                                    ? "No matches."
                                    : "No compendium entries yet — add some in the Compendium."
                            }}
                        </p>
                    </div>
                </div>
            </div>
            <span class="ml-auto font-mono text-[11px] text-faint"
                >{{ stats.lines }} lines · {{ stats.words }} words</span
            >
        </div>

        <!-- Click-away backdrop for the insert menus -->
        <div
            v-if="openMenu"
            class="fixed inset-0 z-10"
            @click="openMenu = null"
        />

        <!-- Panes -->
        <div
            v-show="tab === 'entry'"
            class="grid min-h-0 flex-1 grid-rows-1 overflow-hidden"
            :style="{ gridTemplateColumns: cols }"
        >
            <!-- Source (Markdown, or the brew's scoped CSS) -->
            <div
                v-if="mode !== 'preview'"
                class="flex min-h-0 flex-col overflow-hidden border-r border-edge2 bg-night"
            >
                <div
                    class="flex items-center gap-1 border-b border-edge2 bg-surface px-3 py-1.5"
                >
                    <button
                        class="rounded px-2.5 py-1 text-xs"
                        :class="
                            !showCss
                                ? 'bg-raised text-bright'
                                : 'text-muted hover:text-ink'
                        "
                        @click="showCss = false"
                    >
                        Markdown
                    </button>
                    <button
                        class="rounded px-2.5 py-1 text-xs"
                        :class="
                            showCss
                                ? 'bg-raised text-bright'
                                : 'text-muted hover:text-ink'
                        "
                        @click="showCss = true"
                    >
                        CSS
                    </button>
                    <span
                        v-if="showCss"
                        class="ml-auto font-mono text-[10px] text-faint"
                        >scoped to this brew</span
                    >
                </div>

                <!-- Markdown editor -->
                <div
                    v-show="!showCss"
                    class="flex min-h-0 flex-1 overflow-hidden"
                >
                    <div
                        ref="gutter"
                        class="select-none overflow-hidden whitespace-pre py-3.5 pl-2 pr-2 text-right font-mono text-[13px] leading-relaxed text-faint"
                    >
                        {{ lines.map((_, i) => i + 1).join("\n") }}
                    </div>
                    <div
                        class="relative min-h-0 flex-1 overflow-hidden bg-night"
                    >
                        <!-- Coloured highlight layer, mirrored behind the transparent textarea. -->
                        <pre
                            ref="highlightLayer"
                            aria-hidden="true"
                            class="hl-layer pointer-events-none absolute inset-0 m-0 overflow-hidden whitespace-pre-wrap break-words px-3 py-3.5 font-mono text-[13px] leading-relaxed"
                            v-html="highlighted"
                        />
                        <textarea
                            ref="textarea"
                            :value="form.content"
                            spellcheck="false"
                            class="hl-input absolute inset-0 h-full w-full resize-none overflow-auto border-0 bg-transparent px-3 py-3.5 font-mono text-[13px] leading-relaxed text-transparent caret-white focus:ring-0"
                            @scroll="syncGutter"
                            @input="onSourceInput"
                            @keydown="onKeydown"
                            @keyup="onKeyup"
                            @click="onTextareaClick"
                            @blur="onBlur"
                        />
                        <!-- inline autocomplete ([[ · [statblock= · @) -->
                        <div
                            v-if="ac.active"
                            class="absolute z-40 w-64 overflow-hidden rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                            :style="{
                                top: ac.top + 'px',
                                left: ac.left + 'px',
                            }"
                        >
                            <button
                                v-for="(item, i) in acItems"
                                :key="item.key"
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left"
                                :class="
                                    i === ac.index
                                        ? 'bg-raised text-ink'
                                        : 'text-muted'
                                "
                                @mousedown.prevent="acceptAutocomplete(item)"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate text-[13px]"
                                    >{{ item.label }}</span
                                >
                                <span
                                    class="font-mono text-[10px] uppercase tracking-[0.06em] text-faint"
                                    >{{ item.hint }}</span
                                >
                            </button>
                            <div
                                v-if="!acItems.length"
                                class="px-3 py-2 text-[12.5px] text-faint"
                            >
                                No matches — keep typing.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Brew CSS editor (scoped to this brew) -->
                <textarea
                    v-show="showCss"
                    v-model="cssField"
                    spellcheck="false"
                    class="min-h-0 flex-1 resize-none overflow-auto border-0 bg-night px-3 py-3.5 font-mono text-[12.5px] leading-relaxed text-ink focus:ring-0"
                    placeholder=".phb h2 { color: #7a1f14; }  /* styles only affect this brew */"
                />
            </div>

            <!-- Preview (always single-page) -->
            <div class="flex min-h-0 flex-col bg-[#0b0d10]">
                <div
                    class="flex items-center gap-2 border-b border-edge2 bg-surface px-4 py-1.5"
                >
                    <div
                        class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
                    >
                        <button
                            v-for="m in ['split', 'preview']"
                            :key="m"
                            class="rounded px-3 py-1 text-xs capitalize"
                            :class="
                                mode === m
                                    ? 'bg-raised text-bright'
                                    : 'text-muted hover:text-ink'
                            "
                            @click="mode = m"
                        >
                            {{ m }}
                        </button>
                    </div>
                    <label
                        class="ml-auto flex items-center gap-1.5 text-xs text-muted"
                    >
                        Theme
                        <select
                            v-model="themeField"
                            class="rounded border border-edge3 bg-night px-2 py-1 text-xs text-ink focus:ring-0"
                        >
                            <option value="classic">Classic</option>
                            <option value="ivory">Ivory</option>
                            <option value="journal">Journal</option>
                        </select>
                    </label>
                </div>
                <div class="flex-1 overflow-auto p-6">
                    <HomebrewView
                        :content="form.content"
                        :css="cssField"
                        :theme="themeField"
                        :entries="entries"
                        :embeds="embeds"
                        :spells="spells"
                        :gm="true"
                        page-mode="single"
                        :full-width="true"
                    />
                </div>
            </div>

            <!-- Claude -->
            <div class="flex min-h-0 flex-col border-l border-edge2 bg-surface">
                <!-- Collapsed: a thin strip that reopens the panel -->
                <button
                    v-if="!showClaude"
                    class="flex h-full w-full flex-col items-center gap-2 py-3 text-faint transition hover:text-ink"
                    :title="`Open ${aiName}`"
                    @click="showClaude = true"
                >
                    <span class="text-sm">✻</span>
                    <span
                        class="text-[10px] uppercase tracking-[0.16em]"
                        style="writing-mode: vertical-rl"
                        >{{ aiName }}</span
                    >
                </button>
                <template v-else>
                    <div
                        class="flex items-center justify-between border-b border-edge2 px-4 py-2.5"
                    >
                        <span class="eyebrow">✻ {{ aiName }}</span>
                        <div class="flex items-center gap-3">
                            <button
                                v-if="messages.length"
                                class="text-xs text-faint hover:text-ink"
                                @click="clearChat"
                            >
                                clear
                            </button>
                            <button
                                class="text-faint hover:text-ink"
                                :title="`Collapse ${aiName}`"
                                @click="showClaude = false"
                            >
                                »
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 space-y-3 overflow-auto p-4">
                        <p v-if="!messages.length" class="text-sm text-faint">
                            Ask {{ aiName }} to expand a scene, invent an NPC,
                            or build a stat block — it can see this page.
                        </p>
                        <div v-for="(m, i) in messages" :key="i">
                            <div
                                v-if="m.role === 'user'"
                                class="ml-6 rounded-lg bg-amber/90 px-3 py-2 text-sm text-night"
                            >
                                {{ m.content }}
                            </div>
                            <div v-else class="mr-2">
                                <div
                                    class="prose prose-sm prose-invert max-w-none text-sm text-ink"
                                    v-html="renderMd(m.content)"
                                />
                                <button
                                    class="mt-1 text-xs text-teal hover:underline"
                                    @click="applyReply(m.content)"
                                >
                                    Replace page with this ↧
                                </button>
                            </div>
                        </div>
                        <p v-if="asking" class="text-sm text-faint">
                            Thinking…
                        </p>
                        <p v-if="chatError" class="text-sm text-red-400">
                            {{ chatError }}
                        </p>
                        <div ref="chatBottom" />
                    </div>
                    <form
                        class="border-t border-edge2 p-3"
                        @submit.prevent="send()"
                    >
                        <textarea
                            v-model="input"
                            rows="2"
                            class="field"
                            :placeholder="`Ask ${aiName} about this page…`"
                            @keydown.enter.exact.prevent="send()"
                        />
                        <button
                            type="submit"
                            :disabled="asking || !input.trim()"
                            class="btn-primary mt-2 w-full justify-center"
                        >
                            Send
                        </button>
                    </form>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* The highlight layer and the textarea MUST share identical metrics so the coloured text sits
   exactly under the (transparent) characters the user types. */
.hl-layer,
.hl-input {
    tab-size: 4;
    -moz-tab-size: 4;
    overflow-wrap: break-word;
    word-break: break-word;
    /* Reserve the scrollbar gutter on both so they wrap at the same width (keeps the caret aligned). */
    scrollbar-gutter: stable;
}
.hl-layer {
    color: #d7dbe2;
}
.hl-layer :deep(.tok-heading) {
    color: #d9a441;
    font-weight: 700;
}
.hl-layer :deep(.tok-curly) {
    color: #c58af0;
}
.hl-layer :deep(.tok-wiki) {
    color: #4ec9a0;
}
.hl-layer :deep(.tok-embed) {
    color: #e0a33e;
}
.hl-layer :deep(.tok-link) {
    color: #6ea8fe;
}
.hl-layer :deep(.tok-code) {
    color: #7bd88f;
}
.hl-layer :deep(.tok-bold) {
    color: #f2f4f8;
    font-weight: 700;
}
.hl-layer :deep(.tok-tag) {
    color: #7f8794;
}
.hl-layer :deep(.tok-key) {
    color: #e08a5b;
}
.hl-layer :deep(.tok-quote) {
    color: #8a93a3;
}
</style>
