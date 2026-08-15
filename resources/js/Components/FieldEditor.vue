<script setup>
import CreditCost from "@/Components/CreditCost.vue";
import EntryPicker from "@/Components/EntryPicker.vue";
import EntrySettings from "@/Components/EntrySettings.vue";
import LocationMapEditor from "@/Components/LocationMapEditor.vue";
import RenderedContent from "@/Components/RenderedContent.vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { marked } from "marked";
import { computed, nextTick, reactive, ref, watch } from "vue";

const aiName = computed(() => usePage().props.ai?.name ?? "Muse");

const md = (t) => marked.parse(t || "", { breaks: true });

const props = defineProps({
    campaign: Object,
    document: Object,
    fields: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] }, // linkable entries in this world
    compendium: { type: Array, default: () => [] }, // compendium entries embeddable as {{monster=id}} etc.
    embeds: { type: Array, default: () => [] }, // render payloads so the preview draws full compendium cards
    allTags: { type: Array, default: () => [] },
    map: { type: Object, default: null }, // this location's map (Map tab); null until created
    viewUrl: { type: String, default: null }, // public reader URL for this entry, when the world is published
    templates: { type: Array, default: () => [] },
    access: {
        type: Object,
        default: () => ({ has_password: false, share_url: null }),
    },
});

// Only locations get a Map tab; every kind gets Entry + Settings.
const isLocation = computed(() => props.document.kind === "location");
const tab = ref("entry"); // entry | map | settings
const tabs = computed(() =>
    isLocation.value ? ["entry", "map", "settings"] : ["entry", "settings"],
);

/* ---- entry references ---- */
const entriesById = computed(() =>
    Object.fromEntries(props.entries.map((e) => [e.id, e])),
);
const entryTitle = (id) => entriesById.value[id]?.title ?? "";
// Candidate entries for a reference field, narrowed to its allowed kinds.
const entriesFor = (f) => {
    const kinds = f.ref_kinds ?? [];
    return kinds.length
        ? props.entries.filter((e) => kinds.includes(e.kind))
        : props.entries;
};
// Toggle one value in a multiple-select field (stored as an array on form.data).
const toggleMultiValue = (key, value) => {
    const current = Array.isArray(form.data[key]) ? form.data[key] : [];
    form.data[key] = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];
};

/* ---- secret helpers ---- */
const SECRET_RE = /^\s*\{\{secret\}\}([\s\S]*)\{\{\/\}\}\s*$/;
const isSecret = (body) => SECRET_RE.test(body);
const stripSecret = (body) => (body.match(SECRET_RE)?.[1] ?? body).trim();
const wrapSecret = (body) => `{{secret}}${body}{{/}}`;

/* ---- section split / join ---- */
// Each section carries a stable id so template refs and reordering stay bound to the right textarea.
let seq = 0;
const uid = () => (seq += 1);

// A homebrew block opens with `{{tag` on its own line and closes with `}}` on its own line
// (see resources/js/lib/homebrew.js). Inline spans like {{monster=id}} open and close on one line.
const HEADING_RE = /^##\s+(.+)$/;
const BLOCK_OPENER_RE = /^\{\{([^\s{}][^{}]*)?$/;

function splitSections(content) {
    const lines = (content ?? "").split("\n");
    const out = [];
    let heading = "";
    let buffer = [];
    let depth = 0; // how deep we are inside {{ }} blocks
    const flush = () => {
        const body = buffer.join("\n").trim();
        if (out.length > 0 || heading || body) out.push({ heading, body });
        buffer = [];
    };
    for (const line of lines) {
        // Only a top-level "## " starts a new section; a heading inside a {{monster … }} (or any other
        // homebrew block) belongs to that block and must not be split into its own section.
        const headingMatch = depth === 0 ? line.match(HEADING_RE) : null;
        if (headingMatch) {
            flush();
            heading = headingMatch[1].trim();
            continue;
        }
        buffer.push(line);
        const trimmed = line.trim();
        if (trimmed === "}}") depth = Math.max(0, depth - 1);
        else if (BLOCK_OPENER_RE.test(trimmed)) depth += 1;
    }
    flush();
    return out.map((s) =>
        isSecret(s.body)
            ? {
                  id: uid(),
                  heading: s.heading,
                  body: stripSecret(s.body),
                  secret: true,
              }
            : { id: uid(), heading: s.heading, body: s.body, secret: false },
    );
}
function joinSections(list) {
    return list
        .map((s) => {
            const body = s.secret ? wrapSecret(s.body) : s.body;
            return s.heading ? `## ${s.heading}\n${body}` : body;
        })
        .join("\n\n")
        .replace(/\n{3,}/g, "\n\n")
        .trim();
}

const form = reactive({
    title: props.document.title,
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
    data: { ...(props.document.data ?? {}) },
    content: props.document.content ?? "",
    tags: [...(props.document.tags ?? [])],
});
const sections = ref(splitSections(props.document.content ?? ""));

// Sections are the source of truth for the body; rebuild content when they change.
watch(sections, () => (form.content = joinSections(sections.value)), {
    deep: true,
});

const saved = ref(true);
let timer = null;
const save = () => {
    router.put(
        route("documents.update", props.document.id),
        {
            title: form.title,
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
            content: form.content,
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

const addSection = () =>
    sections.value.push({
        id: uid(),
        heading: "New section",
        body: "",
        secret: false,
    });
const removeSection = (i) => sections.value.splice(i, 1);
const moveSection = (i, dir) => {
    const j = i + dir;
    if (j < 0 || j >= sections.value.length) return;
    const list = sections.value;
    [list[i], list[j]] = [list[j], list[i]];
};

/* ---- per-section insert toolbar (icons open a snippet menu that inserts at that section's caret) ---- */
const sectionEls = new Map(); // section id -> <textarea>
const setSectionEl = (id, el) => {
    if (el) {
        sectionEls.set(id, el);
    } else {
        sectionEls.delete(id);
    }
};

const openMenu = ref(); // { id, name } | undefined — only one section menu open at a time
const isMenuOpen = (id, name) =>
    !!openMenu.value &&
    openMenu.value.id === id &&
    openMenu.value.name === name;
const toggleMenu = (id, name) => {
    openMenu.value = isMenuOpen(id, name) ? undefined : { id, name };
    if (name === "world") worldSearch.value = "";
};

const insertIntoSection = (id, snippet) => {
    openMenu.value = undefined;
    const el = sectionEls.get(id);
    const section = sections.value.find((s) => s.id === id);
    if (!section) return;
    const caret = el?.selectionStart ?? section.body.length;
    section.body =
        section.body.slice(0, caret) + snippet + section.body.slice(caret);
    nextTick(() => {
        const pos = caret + snippet.length;
        // preventScroll stops the browser jumping the fields column to the caret.
        el?.focus({ preventScroll: true });
        el?.setSelectionRange(pos, pos);
    });
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
// The 'world' tool renders a compendium search rather than a fixed snippet list.
const SECTION_TOOLS = [
    { name: "text", icon: "✎", label: "Text", snippets: TEXT_SNIPPETS },
    { name: "blocks", icon: "▤", label: "Blocks", snippets: BLOCK_SNIPPETS },
    { name: "images", icon: "▦", label: "Images", snippets: IMAGE_SNIPPETS },
    { name: "page", icon: "❏", label: "Page", snippets: PAGE_SNIPPETS },
    {
        name: "world",
        icon: "✦",
        label: "WorldBuilder Info",
        snippets: undefined,
    },
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

/* ---- field types ---- */
const inputType = (type) =>
    ({ number: "number", date: "date", url: "url" })[type] ?? "text";
const isTrue = (v) => v === true || v === "true" || v === 1;
const factValue = (v) => {
    if (typeof v === "boolean") return v ? "Yes" : "";
    return v === null || v === undefined ? "" : String(v).trim();
};

/* ---- preview ---- */
const previewAs = ref("gm");
const factDisplay = (f) => {
    const raw = form.data[f.key];
    if (f.multiple) {
        const values = Array.isArray(raw) ? raw : [];
        return (
            f.type === "reference" ? values.map((id) => entryTitle(id)) : values
        )
            .filter(Boolean)
            .join(", ");
    }
    return f.type === "reference" ? entryTitle(raw) : factValue(raw);
};
const facts = computed(() =>
    props.fields
        .map((f) => ({ k: f.label, v: factDisplay(f) }))
        .filter((f) => f.v),
);
const paras = (body) =>
    body
        .replace(/!\[[^\]]*\]\([^)]*\)/g, "")
        .split("\n")
        .map((x) => x.trim())
        .filter(Boolean);
const leadIn = computed(() => {
    const intro = sections.value.find((s) => !s.secret && s.body.trim());
    return intro
        ? (paras(intro.body)[0] ?? "No description yet.")
        : "No description yet.";
});
const visibleSections = computed(() =>
    sections.value.filter((s) => !(previewAs.value === "player" && s.secret)),
);
const hiddenCount = computed(
    () => sections.value.filter((s) => s.secret).length,
);
const stripes =
    "repeating-linear-gradient(48deg,#1f232b 0 10px,#1b1f26 10px 20px)";

/* ---- Claude ---- */
const showClaude = ref(true); // the Claude panel can be collapsed to a thin strip
const cols = computed(
    () => `340px minmax(0,1fr) ${showClaude.value ? "320px" : "2.5rem"}`,
);

const messages = ref([]);
const input = ref("");
const asking = ref(false);
const chatError = ref("");
const suggestions = [
    "Draft a full first version of this entry.",
    "Expand this into three vivid paragraphs.",
    "Suggest a secret the players don't know.",
    "Write a rumour heard in a nearby tavern.",
];

// Write Claude's structured reply into the entry: fill the structured fields, rebuild the sections
// from the returned body, set the summary. Returns an undo snapshot, or undefined if nothing changed.
const applyPatch = ({ content, summary, fields: filled }) => {
    const hasFields = filled && Object.keys(filled).length > 0;
    const hasContent = typeof content === "string" && content.trim() !== "";
    if (!hasFields && !hasContent && !summary) return undefined;

    const undo = {
        data: { ...form.data },
        summary: form.summary,
        sections: sections.value.map((s) => ({ ...s })),
    };
    if (summary) form.summary = summary;
    if (hasFields)
        Object.entries(filled).forEach(([key, value]) => {
            form.data[key] = value;
        });
    if (hasContent) sections.value = splitSections(content);
    return undo;
};

const undoPatch = (message) => {
    if (!message.undo) return;
    form.summary = message.undo.summary;
    form.data = { ...message.undo.data };
    sections.value = message.undo.sections.map((s) => ({ ...s }));
    message.applied = false;
    message.undo = undefined;
};

const send = async (text) => {
    const prompt = (text ?? input.value).trim();
    if (!prompt || asking.value) return;
    input.value = "";
    chatError.value = "";
    const history = messages.value.map((m) => ({
        role: m.role,
        content: m.content,
    }));
    messages.value.push({ role: "user", content: prompt });
    asking.value = true;
    try {
        const res = await window.axios.post(
            route("documents.draft", props.document.id),
            { prompt, content: form.content, history },
        );
        const undo = applyPatch(res.data);
        messages.value.push({
            role: "assistant",
            content: res.data.reply || (undo ? "Updated the entry." : ""),
            applied: !!undo,
            undo,
        });
    } catch (e) {
        chatError.value = e.response?.data?.message ?? "The AI request failed.";
    } finally {
        asking.value = false;
    }
};
</script>

<template>
    <div class="flex h-full flex-col bg-night">
        <!-- Top bar -->
        <div
            class="flex items-center gap-3.5 border-b border-edge2 bg-surface px-5 py-2"
        >
            <!-- Breadcrumb: back + title + open-in-new-tab, grouped -->
            <div class="flex items-center gap-3">
                <Link
                    :href="route('worlds.show', campaign.id)"
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
                        class="border-0 bg-transparent px-0 font-display text-[16px] text-bright focus:ring-0"
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

            <!-- Center: Entry / Map / Settings -->
            <div
                class="mx-auto flex items-center gap-1 rounded-md border border-edge3 p-0.5"
            >
                <button
                    v-for="t in tabs"
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

            <span
                class="text-xs"
                :class="saved ? 'text-faint' : 'text-amber'"
                >{{ saved ? "✓ Saved" : "Saving…" }}</span
            >
        </div>

        <!-- Click-away backdrop for the per-section insert menus -->
        <div
            v-if="openMenu"
            class="fixed inset-0 z-10"
            @click="openMenu = undefined"
        />

        <!-- Map tab (locations only) -->
        <div
            v-if="isLocation && tab === 'map'"
            class="min-h-0 flex-1 overflow-hidden p-4"
        >
            <LocationMapEditor
                :campaign="campaign"
                :document="document"
                :map="map"
                :entries="entries"
                :compendium="compendium"
            />
        </div>

        <!-- Settings tab -->
        <EntrySettings
            v-else-if="tab === 'settings'"
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

        <div
            v-show="tab === 'entry'"
            class="grid min-h-0 flex-1 grid-rows-1 overflow-hidden"
            :style="{ gridTemplateColumns: cols }"
        >
            <!-- Fields -->
            <div
                class="flex min-h-0 flex-col gap-4 overflow-auto border-r border-edge2 bg-surface px-4 pb-10 pt-4"
            >
                <div
                    v-for="f in fields"
                    :key="f.key"
                    class="flex flex-col gap-1.5"
                >
                    <div class="text-[13px] font-semibold text-ink">
                        {{ f.label
                        }}<span v-if="f.required" class="ml-0.5 text-amber"
                            >*</span
                        >
                    </div>

                    <div
                        v-if="f.type === 'select' && f.multiple"
                        class="flex flex-wrap gap-1.5"
                    >
                        <button
                            v-for="o in f.options || []"
                            :key="o"
                            type="button"
                            class="rounded-full border px-2.5 py-0.5 text-xs"
                            :class="
                                (form.data[f.key] || []).includes(o)
                                    ? 'border-teal bg-teal/10 text-teal'
                                    : 'border-edge3 text-muted hover:text-ink'
                            "
                            @click="toggleMultiValue(f.key, o)"
                        >
                            {{ o }}
                        </button>
                    </div>

                    <select
                        v-else-if="f.type === 'select'"
                        v-model="form.data[f.key]"
                        class="field !py-2 text-[13.5px]"
                    >
                        <option value="">{{ f.placeholder || "—" }}</option>
                        <option
                            v-for="o in f.options || []"
                            :key="o"
                            :value="o"
                        >
                            {{ o }}
                        </option>
                    </select>

                    <textarea
                        v-else-if="f.type === 'longtext'"
                        v-model="form.data[f.key]"
                        rows="3"
                        :placeholder="f.placeholder"
                        class="field !py-2 text-[13.5px]"
                    />

                    <EntryPicker
                        v-else-if="f.type === 'reference'"
                        v-model="form.data[f.key]"
                        :entries="entriesFor(f)"
                        :multiple="f.multiple"
                        :placeholder="f.placeholder || 'Search entries…'"
                    />

                    <button
                        v-else-if="f.type === 'boolean'"
                        type="button"
                        class="self-start rounded-full px-3 py-1 font-mono text-[9.5px] tracking-[0.12em]"
                        :class="
                            isTrue(form.data[f.key])
                                ? 'border border-[#3f5c2e] bg-[#1c2416] text-[#9dc47a]'
                                : 'border border-edge3 text-faint'
                        "
                        @click="form.data[f.key] = !isTrue(form.data[f.key])"
                    >
                        {{ isTrue(form.data[f.key]) ? "YES" : "NO" }}
                    </button>

                    <input
                        v-else
                        v-model="form.data[f.key]"
                        :type="inputType(f.type)"
                        :placeholder="f.placeholder"
                        class="field !py-2 text-[13.5px]"
                    />

                    <div
                        v-if="f.help"
                        class="text-[11px] leading-snug text-faint"
                    >
                        {{ f.help }}
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-[13px] font-semibold text-ink">
                        Visible to players
                    </div>
                    <button
                        class="rounded-full px-3 py-1 font-mono text-[9.5px] tracking-[0.12em]"
                        :class="
                            !form.is_private
                                ? 'border border-[#3f5c2e] bg-[#1c2416] text-[#9dc47a]'
                                : 'border border-[#6b4c14] bg-[#241c0e] text-amber'
                        "
                        @click="form.is_private = !form.is_private"
                    >
                        {{ form.is_private ? "GM ONLY" : "PLAYERS" }}
                    </button>
                </div>
                <div class="h-px bg-edge2"></div>

                <div class="flex items-center justify-between">
                    <div
                        class="font-mono text-[9.5px] uppercase tracking-[0.18em] text-faint"
                    >
                        Sections
                    </div>
                    <button
                        class="text-[13.5px] text-amber"
                        @click="addSection"
                    >
                        + Add
                    </button>
                </div>
                <div class="flex flex-col gap-2">
                    <div
                        v-for="(s, i) in sections"
                        :key="s.id"
                        class="rounded-[7px] border border-edge2 bg-[#1a1d24]"
                    >
                        <!-- Section header: heading, players toggle, reorder, delete -->
                        <div
                            class="flex items-center gap-1.5 rounded-t-[7px] border-b border-edge2 bg-[#1e222a] px-3 py-2"
                        >
                            <input
                                v-model="s.heading"
                                :placeholder="
                                    i === 0
                                        ? 'Overview (no heading)'
                                        : 'Section heading'
                                "
                                class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[14px] font-semibold text-ink focus:ring-0"
                            />
                            <button
                                class="rounded-full px-2 py-0.5 font-mono text-[9px] tracking-[0.12em]"
                                :class="
                                    s.secret
                                        ? 'border border-[#6b4c14] bg-[#241c0e] text-amber'
                                        : 'border border-[#3f5c2e] bg-[#1c2416] text-[#9dc47a]'
                                "
                                @click="s.secret = !s.secret"
                            >
                                {{ s.secret ? "GM ONLY" : "PLAYERS" }}
                            </button>
                            <button
                                class="rounded px-1 text-faint enabled:hover:text-ink disabled:opacity-30"
                                :disabled="i === 0"
                                title="Move up"
                                @click="moveSection(i, -1)"
                            >
                                ↑
                            </button>
                            <button
                                class="rounded px-1 text-faint enabled:hover:text-ink disabled:opacity-30"
                                :disabled="i === sections.length - 1"
                                title="Move down"
                                @click="moveSection(i, 1)"
                            >
                                ↓
                            </button>
                            <button
                                class="text-faint hover:text-amber"
                                title="Delete section"
                                @click="removeSection(i)"
                            >
                                🗑
                            </button>
                        </div>

                        <!-- Insert toolbar: icons only; click opens that tool's menu -->
                        <div
                            class="flex items-center gap-0.5 border-b border-edge2 bg-[#191c22] px-2 py-1"
                        >
                            <div
                                v-for="tool in SECTION_TOOLS"
                                :key="tool.name"
                                class="relative"
                            >
                                <button
                                    class="rounded px-1.5 py-0.5 text-[13px] text-faint hover:bg-raised hover:text-ink"
                                    :class="
                                        isMenuOpen(s.id, tool.name) &&
                                        'bg-raised text-ink'
                                    "
                                    :title="tool.label"
                                    @click.stop="toggleMenu(s.id, tool.name)"
                                >
                                    {{ tool.icon }}
                                </button>

                                <!-- Snippet menu (text / blocks / images / page) -->
                                <div
                                    v-if="
                                        tool.snippets &&
                                        isMenuOpen(s.id, tool.name)
                                    "
                                    class="absolute left-0 top-full z-30 mt-1 w-56 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                                >
                                    <button
                                        v-for="[label, snip] in tool.snippets"
                                        :key="label"
                                        class="block w-full px-3 py-1.5 text-left text-[13px] text-muted hover:bg-raised hover:text-ink"
                                        @click="insertIntoSection(s.id, snip)"
                                    >
                                        {{ label }}
                                    </button>
                                </div>

                                <!-- WorldBuilder Info: searchable compendium embeds -->
                                <div
                                    v-else-if="
                                        tool.name === 'world' &&
                                        isMenuOpen(s.id, tool.name)
                                    "
                                    class="absolute left-0 top-full z-30 mt-1 w-64 rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                                    @click.stop
                                >
                                    <div class="px-2 pb-1 pt-0.5">
                                        <input
                                            v-model="worldSearch"
                                            class="field !py-1.5 !text-[13px]"
                                            placeholder="Search the compendium…"
                                        />
                                    </div>
                                    <div class="max-h-64 overflow-auto">
                                        <button
                                            v-for="c in worldResults"
                                            :key="c.id"
                                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-raised"
                                            @click="
                                                insertIntoSection(
                                                    s.id,
                                                    `\n${c.token}\n`,
                                                )
                                            "
                                        >
                                            <span
                                                class="min-w-0 flex-1 truncate text-[13px] text-ink"
                                                >{{ c.name }}</span
                                            >
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
                        </div>

                        <textarea
                            :ref="(el) => setSectionEl(s.id, el)"
                            v-model="s.body"
                            rows="4"
                            placeholder="Write the section. Markdown and **bold** work here."
                            class="w-full rounded-b-[7px] border-0 bg-transparent p-3 font-mono text-[13.5px] leading-relaxed text-ink focus:ring-0"
                        />
                    </div>
                </div>
            </div>

            <!-- Live preview -->
            <div class="flex min-h-0 flex-col bg-[#0b0d10]">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-edge2 bg-surface px-3.5 py-2"
                >
                    <div
                        class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-[#6f7580]"
                    >
                        Live page preview
                    </div>
                    <div
                        class="flex items-center gap-0.5 rounded-full border border-edge3 bg-[#1a1d24] p-[3px]"
                    >
                        <button
                            class="rounded-full px-3 py-1 font-mono text-[10.5px] tracking-[0.1em]"
                            :class="
                                previewAs === 'gm'
                                    ? 'bg-amber text-night'
                                    : 'text-muted'
                            "
                            @click="previewAs = 'gm'"
                        >
                            GM
                        </button>
                        <button
                            class="rounded-full px-3 py-1 font-mono text-[10.5px] tracking-[0.1em]"
                            :class="
                                previewAs === 'player'
                                    ? 'bg-teal text-night'
                                    : 'text-muted'
                            "
                            @click="previewAs = 'player'"
                        >
                            PLAYER
                        </button>
                    </div>
                    <div class="text-[13px] font-light text-faint">
                        {{
                            previewAs === "player"
                                ? "Exactly what a player loads."
                                : "Your view — secrets shown inline."
                        }}
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto bg-[#0b0d10] p-5">
                    <div
                        class="mx-auto flex w-full max-w-[820px] flex-col overflow-hidden rounded-[10px] border border-edge2 bg-[#14161b] shadow-[0_22px_50px_rgba(0,0,0,0.55)]"
                    >
                        <div
                            class="flex items-center gap-2 border-b border-edge bg-surface px-4 py-2.5 font-mono text-[11.5px] text-faint"
                        >
                            <span>{{ campaign.name }}</span
                            ><span class="text-[#4a4f59]">/</span
                            ><span class="text-ink">{{ form.title }}</span>
                        </div>
                        <div
                            class="h-[150px] flex-shrink-0 bg-cover bg-center"
                            :style="
                                document.banner_url
                                    ? {
                                          backgroundImage: `url(${document.banner_url})`,
                                      }
                                    : { background: stripes }
                            "
                        ></div>
                        <div
                            class="grid gap-6 px-6 pb-8"
                            style="
                                grid-template-columns: minmax(0, 1fr) 250px;
                                margin-top: -34px;
                            "
                        >
                            <div class="flex min-w-0 flex-col gap-3.5">
                                <div class="flex flex-col gap-2">
                                    <div
                                        class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-teal"
                                    >
                                        {{ document.kindLabel }}
                                    </div>
                                    <div
                                        class="font-display text-[36px] leading-[1.07] text-bright"
                                    >
                                        {{ form.title }}
                                    </div>
                                    <div
                                        class="text-[16px] font-light italic leading-[1.5] text-[#b8bcc4]"
                                    >
                                        {{ leadIn }}
                                    </div>
                                </div>
                                <div class="h-px bg-edge2"></div>
                                <div
                                    v-for="(s, i) in visibleSections"
                                    :key="i"
                                    class="flex flex-col gap-2"
                                >
                                    <div
                                        v-if="s.heading"
                                        class="flex flex-wrap items-center gap-2.5"
                                    >
                                        <div
                                            class="font-display text-[22px] text-[#f3efe6]"
                                        >
                                            {{ s.heading }}
                                        </div>
                                        <div
                                            v-if="s.secret"
                                            class="rounded-full border border-dashed border-[#6b4c14] bg-[#241c0e] px-2 py-1 font-mono text-[9.5px] tracking-[0.14em] text-amber"
                                        >
                                            GM ONLY
                                        </div>
                                    </div>
                                    <div
                                        :class="
                                            s.secret
                                                ? 'rounded-lg border border-dashed border-[#6b4c14] bg-[#20190d] px-4 py-3'
                                                : ''
                                        "
                                    >
                                        <RenderedContent
                                            :content="s.body"
                                            :embeds="embeds"
                                            :gm="previewAs === 'gm'"
                                            :wiki-targets="entries"
                                            link-base="/documents/"
                                            link-suffix="/edit"
                                            single-column
                                        />
                                    </div>
                                </div>
                                <div
                                    v-if="previewAs === 'player' && hiddenCount"
                                    class="border-t border-edge pt-3 font-mono text-[11px] leading-relaxed text-[#6f7580]"
                                >
                                    {{ hiddenCount }} GM-only
                                    {{
                                        hiddenCount === 1
                                            ? "section is"
                                            : "sections are"
                                    }}
                                    hidden from this view.
                                </div>
                            </div>
                            <div
                                class="flex min-w-0 flex-col gap-3"
                                style="padding-top: 44px"
                            >
                                <div
                                    v-if="facts.length"
                                    class="flex flex-col gap-2.5 rounded-lg border border-edge2 bg-card p-3.5"
                                >
                                    <div
                                        class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-[#6f7580]"
                                    >
                                        Quick facts
                                    </div>
                                    <div
                                        v-for="f in facts"
                                        :key="f.k"
                                        class="flex justify-between gap-3 text-[14px]"
                                    >
                                        <span class="text-[#8a909b]">{{
                                            f.k
                                        }}</span
                                        ><span
                                            class="text-right text-[#c8ccd3]"
                                            >{{ f.v }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
                    <span class="text-sm">✳</span>
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
                        <div class="flex items-center gap-3">
                            <span
                                class="font-mono text-[11px] uppercase tracking-[0.2em] text-amber"
                                >✳ {{ aiName }}</span
                            >
                            <CreditCost :kind="document.kind" prefix="Costs" />
                        </div>
                        <div class="flex items-center gap-3">
                            <button
                                v-if="messages.length"
                                class="text-xs text-faint hover:text-ink"
                                @click="messages = []"
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
                        <div
                            class="text-[14.5px] font-light leading-[1.6] text-[#b8bcc4]"
                        >
                            Ask for a draft, a rewrite or a secret.
                            {{ aiName }} reads this page and writes into it.
                        </div>
                        <button
                            v-for="(s, i) in suggestions"
                            :key="i"
                            v-show="!messages.length"
                            class="w-full rounded-md border border-edge3 bg-[#1a1d24] px-3 py-2.5 text-left text-[14.5px] leading-[1.45] text-ink transition hover:border-amber"
                            @click="send(s)"
                        >
                            {{ s }}
                        </button>
                        <div v-for="(m, i) in messages" :key="i">
                            <div
                                v-if="m.role === 'user'"
                                class="ml-6 rounded-lg bg-amber/90 px-3 py-2 text-sm text-night"
                            >
                                {{ m.content }}
                            </div>
                            <div v-else>
                                <div
                                    v-if="m.content"
                                    class="prose prose-invert max-w-none text-sm text-ink prose-headings:font-display"
                                    v-html="md(m.content)"
                                />
                                <div
                                    v-if="m.applied"
                                    class="mt-1 flex items-center gap-2 text-xs"
                                >
                                    <span class="text-[#9dc47a]"
                                        >✓ Applied to the entry</span
                                    >
                                    <button
                                        class="text-faint underline hover:text-ink"
                                        @click="undoPatch(m)"
                                    >
                                        Undo
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="asking"
                            class="font-mono text-[13px] tracking-[0.1em] text-amber"
                            style="animation: pulse 1.1s ease-in-out infinite"
                        >
                            writing into the page…
                        </p>
                        <p v-if="chatError" class="text-sm text-red-400">
                            {{ chatError }}
                        </p>
                    </div>
                    <form
                        class="flex flex-col gap-2 border-t border-edge2 p-3.5"
                        @submit.prevent="send()"
                    >
                        <textarea
                            v-model="input"
                            rows="2"
                            class="field !border-[#5c4a1f]"
                            :placeholder="`Ask ${aiName} about this page…`"
                            @keydown.enter.exact.prevent="send()"
                        />
                        <button
                            type="submit"
                            :disabled="asking || !input.trim()"
                            class="btn-primary self-end"
                        >
                            Send
                        </button>
                    </form>
                </template>
            </div>
        </div>
    </div>
</template>
