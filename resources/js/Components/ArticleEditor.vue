<script setup>
import ConnectionGraph from "@/Components/ConnectionGraph.vue";
import EntrySettings from "@/Components/EntrySettings.vue";
import RenderedContent from "@/Components/RenderedContent.vue";
import { useTextareaAutocomplete } from "@/composables/useTextareaAutocomplete";
import { Link, router } from "@inertiajs/vue3";
import { nextTick, reactive, ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    document: Object,
    entries: { type: Array, default: () => [] },
    compendium: { type: Array, default: () => [] },
    characters: { type: Array, default: () => [] },
    embeds: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    links: { type: Array, default: () => [] },
    backlinks: { type: Array, default: () => [] },
    relationshipOptions: { type: Array, default: () => [] },
    access: {
        type: Object,
        default: () => ({ has_password: false, share_url: null }),
    },
    graph: { type: Object, default: () => ({ nodes: [], edges: [] }) },
    allTags: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    viewUrl: { type: String, default: null }, // public reader URL for this entry, when the world is published
});

/* ---- access control (password gate + share link) ---- */
const passwordInput = ref("");
const xhr = { preserveScroll: true, preserveState: true };
const setPassword = () =>
    router.put(
        route("documents.access", props.document.id),
        { password: passwordInput.value || null },
        {
            ...xhr,
            onSuccess: () => (passwordInput.value = ""),
        },
    );
const removePassword = () =>
    router.put(
        route("documents.access", props.document.id),
        { password: null },
        xhr,
    );
const createShare = () =>
    router.post(route("documents.share", props.document.id), {}, xhr);
const revokeShare = () =>
    router.delete(route("documents.unshare", props.document.id), xhr);
const copyShare = () => {
    if (props.access.share_url)
        navigator.clipboard?.writeText(props.access.share_url);
};

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
    tags: [...(props.document.tags ?? [])],
});

const mode = ref("split"); // source | split | preview
const tab = ref("entry"); // entry | settings
const saved = ref(true);
let timer = null;

const save = () => {
    router.put(
        route("documents.update", props.document.id),
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

/* ---- live preview (shares the reader's renderer: embeds, secrets, [[wiki-links]]) ---- */
const textarea = ref(null);
const reveal = (index) =>
    router.post(
        route("documents.reveal", props.document.id),
        { index },
        { preserveScroll: true },
    );

const insert = (text, caretOffset) => {
    const el = textarea.value;
    const caret = el?.selectionStart ?? form.content.length;
    form.content =
        form.content.slice(0, caret) + text + form.content.slice(caret);
    nextTick(() => {
        el?.focus();
        const pos = caret + (caretOffset ?? text.length);
        el?.setSelectionRange(pos, pos);
    });
};
const mention = (entry) => insert(`[[${entry.title}]]`);

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
            form.content = value;
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

/* ---- connections panel ---- */
const linkQuery = ref("");
const linkLabel = ref("");
const linkRelationship = ref("related_to"); // a key from relationshipOptions, or "custom"
const linkedIds = computed(() => new Set(props.links.map((l) => l.to)));
const linkResults = computed(() => {
    const query = linkQuery.value.trim().toLowerCase();
    return props.entries
        .filter((e) => !query || e.title.toLowerCase().includes(query))
        .slice(0, 25);
});

const addLink = (entry) => {
    const custom = linkRelationship.value === "custom";
    router.post(
        route("documents.links.store", props.document.id),
        {
            to_document_id: entry.id,
            relationship: custom ? null : linkRelationship.value,
            label: custom ? linkLabel.value.trim() || null : null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                linkQuery.value = "";
                linkLabel.value = "";
            },
        },
    );
};
const removeLink = (link) => {
    router.delete(route("documents.links.destroy", link.id), {
        preserveScroll: true,
        preserveState: true,
    });
};
const openEntry = (id) => router.get(route("documents.edit", id));

const cols = computed(() =>
    mode.value === "split"
        ? "minmax(0,1fr) minmax(0,1fr) 340px"
        : "minmax(0,1fr) 340px",
);
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
            <div
                v-show="tab === 'entry'"
                class="flex items-center gap-1 rounded-md border border-edge3 p-0.5"
            >
                <button
                    v-for="m in ['source', 'split', 'preview']"
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
            <span
                class="text-xs"
                :class="saved ? 'text-faint' : 'text-amber'"
                >{{ saved ? "✓ Saved" : "Saving…" }}</span
            >
        </div>

        <!-- Meta row -->
        <div
            v-show="tab === 'entry'"
            class="flex flex-wrap items-center gap-4 border-b border-edge bg-surface px-4 py-1.5"
        >
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
            <span class="ml-auto font-mono text-[11px] text-faint"
                >Type <b class="text-muted">[[Entry name]]</b> to link
                entries.</span
            >
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

        <!-- Panes -->
        <div
            v-show="tab === 'entry'"
            class="grid min-h-0 flex-1 grid-rows-1 overflow-hidden"
            :style="{ gridTemplateColumns: cols }"
        >
            <!-- Source -->
            <div
                v-if="mode !== 'preview'"
                class="relative flex min-h-0 flex-col border-r border-edge2 bg-night"
            >
                <textarea
                    ref="textarea"
                    :value="form.content"
                    spellcheck="true"
                    class="min-h-0 flex-1 resize-none overflow-auto border-0 bg-night px-4 py-4 font-mono text-[13.5px] leading-relaxed text-ink focus:ring-0"
                    placeholder="Write the article. Markdown works, and [[ starts a link to another entry."
                    @input="onInput"
                    @keydown="onKeydown"
                    @keyup="onKeyup"
                    @click="onTextareaClick"
                    @blur="onBlur"
                />
                <!-- inline autocomplete ([[ · [statblock= · @) -->
                <div
                    v-if="ac.active"
                    class="absolute z-40 w-64 overflow-hidden rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl"
                    :style="{ top: ac.top + 'px', left: ac.left + 'px' }"
                >
                    <button
                        v-for="(item, i) in acItems"
                        :key="item.key"
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left"
                        :class="
                            i === ac.index ? 'bg-raised text-ink' : 'text-muted'
                        "
                        @mousedown.prevent="acceptAutocomplete(item)"
                    >
                        <span class="min-w-0 flex-1 truncate text-[13.5px]">{{
                            item.label
                        }}</span>
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

            <!-- Preview -->
            <div
                v-if="mode !== 'source'"
                class="flex min-h-0 flex-col bg-[#0b0d10]"
            >
                <div
                    class="border-b border-edge2 bg-surface px-4 py-2 font-mono text-[9.5px] uppercase tracking-[0.16em] text-[#6f7580]"
                >
                    Article preview
                </div>
                <div class="flex-1 overflow-auto px-6 py-6">
                    <div class="mx-auto max-w-[720px]">
                        <div
                            class="mb-1 font-mono text-[9.5px] uppercase tracking-[0.2em] text-teal"
                        >
                            {{ document.kindLabel }}
                        </div>
                        <h1
                            class="mb-4 font-display text-[34px] leading-[1.08] text-bright"
                        >
                            {{ form.title }}
                        </h1>
                        <RenderedContent
                            :content="form.content"
                            :embeds="embeds"
                            :spells="spells"
                            :gm="true"
                            :wiki-targets="entries"
                            link-base="/documents/"
                            link-suffix="/edit"
                            @reveal="reveal"
                        />
                    </div>
                </div>
            </div>

            <!-- Connections -->
            <div class="flex min-h-0 flex-col border-l border-edge2 bg-surface">
                <div class="border-b border-edge2 px-4 py-2.5">
                    <span class="eyebrow">✦ Connections</span>
                </div>
                <div class="flex-1 space-y-5 overflow-auto p-4">
                    <!-- Add a relationship -->
                    <div class="flex flex-col gap-2">
                        <div class="eyebrow-muted">Add a connection</div>
                        <select
                            v-model="linkRelationship"
                            class="field !py-1.5 !text-[13px]"
                            title="Relationship type"
                        >
                            <option
                                v-for="o in relationshipOptions"
                                :key="o.key"
                                :value="o.key"
                            >
                                {{ o.label }}
                            </option>
                            <option value="custom">Custom…</option>
                        </select>
                        <input
                            v-if="linkRelationship === 'custom'"
                            v-model="linkLabel"
                            class="field !py-1.5 !text-[13px]"
                            placeholder="Custom relationship, e.g. sworn enemy of"
                        />
                        <input
                            v-model="linkQuery"
                            class="field !py-1.5 !text-[13px]"
                            placeholder="Search entries…"
                        />
                        <div
                            v-if="linkQuery"
                            class="max-h-52 overflow-auto rounded-md border border-edge2"
                        >
                            <div
                                v-for="e in linkResults"
                                :key="e.id"
                                class="flex items-center gap-2 border-b border-edge px-2.5 py-1.5 last:border-0"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate text-[13.5px] text-ink"
                                    >{{ e.title }}</span
                                >
                                <button
                                    class="rounded border border-edge3 px-1.5 py-0.5 text-[11px] text-muted hover:border-teal hover:text-teal"
                                    title="Mention in the text"
                                    @click="mention(e)"
                                >
                                    [[…]]
                                </button>
                                <button
                                    class="rounded border border-edge3 px-1.5 py-0.5 text-[11px] text-muted hover:border-amber hover:text-amber"
                                    :disabled="linkedIds.has(e.id)"
                                    title="Add relationship"
                                    @click="addLink(e)"
                                >
                                    + link
                                </button>
                            </div>
                            <p
                                v-if="!linkResults.length"
                                class="px-3 py-3 text-[13px] text-faint"
                            >
                                No matching entries.
                            </p>
                        </div>
                    </div>

                    <!-- This entry's connections -->
                    <div class="flex flex-col gap-1.5">
                        <div class="eyebrow-muted">
                            Links out ({{ links.length }})
                        </div>
                        <p v-if="!links.length" class="text-[13px] text-faint">
                            No manual connections yet.
                        </p>
                        <div
                            v-for="l in links"
                            :key="l.id"
                            class="flex items-center gap-2 rounded-md border border-edge2 bg-[#1a1d24] px-2.5 py-1.5"
                        >
                            <div class="min-w-0 flex-1">
                                <button
                                    class="block max-w-full truncate text-left text-[13.5px] text-ink hover:text-teal"
                                    @click="openEntry(l.to)"
                                >
                                    {{ l.title }}
                                </button>
                                <div class="font-mono text-[10px] text-faint">
                                    {{ l.label || l.kindLabel }}
                                </div>
                            </div>
                            <button
                                class="text-faint hover:text-amber"
                                title="Remove"
                                @click="removeLink(l)"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Backlinks -->
                    <div class="flex flex-col gap-1.5">
                        <div class="eyebrow-muted">
                            Linked from ({{ backlinks.length }})
                        </div>
                        <p
                            v-if="!backlinks.length"
                            class="text-[13px] text-faint"
                        >
                            Nothing links here yet.
                        </p>
                        <button
                            v-for="b in backlinks"
                            :key="b.id"
                            class="flex items-center gap-2 rounded-md border border-edge2 bg-[#1a1d24] px-2.5 py-1.5 text-left hover:border-teal/40"
                            @click="openEntry(b.from)"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-[13.5px] text-ink"
                                >{{ b.title }}</span
                            >
                            <span class="font-mono text-[10px] text-faint">{{
                                b.label ||
                                (b.source === "wikilink"
                                    ? "mentions"
                                    : b.kindLabel)
                            }}</span>
                        </button>
                    </div>

                    <!-- Mini graph -->
                    <div class="flex flex-col gap-1.5">
                        <div class="eyebrow-muted">Nearby web</div>
                        <ConnectionGraph
                            :nodes="graph.nodes"
                            :edges="graph.edges"
                            :focus="document.id"
                            :height="240"
                            @select="openEntry"
                        />
                    </div>

                    <!-- Reader access: password gate + share link -->
                    <div class="flex flex-col gap-2">
                        <div class="eyebrow-muted">Reader access</div>

                        <div
                            class="rounded-md border border-edge2 bg-[#1a1d24] p-2.5"
                        >
                            <div class="mb-1.5 text-[12px] text-muted">
                                Password
                            </div>
                            <div
                                v-if="access.has_password"
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="text-[13px] text-teal"
                                    >✓ Protected</span
                                >
                                <button
                                    class="text-[12px] text-faint hover:text-red-400"
                                    @click="removePassword"
                                >
                                    Remove
                                </button>
                            </div>
                            <div v-else class="flex gap-2">
                                <input
                                    v-model="passwordInput"
                                    type="text"
                                    class="field !py-1.5 !text-[13px]"
                                    placeholder="Set a password"
                                    autocomplete="off"
                                />
                                <button
                                    class="shrink-0 rounded border border-edge3 px-2 text-[12px] text-muted hover:border-amber hover:text-amber disabled:opacity-40"
                                    :disabled="!passwordInput"
                                    @click="setPassword"
                                >
                                    Set
                                </button>
                            </div>
                        </div>

                        <div
                            class="rounded-md border border-edge2 bg-[#1a1d24] p-2.5"
                        >
                            <div class="mb-1.5 text-[12px] text-muted">
                                Share link
                            </div>
                            <template v-if="access.share_url">
                                <div class="flex items-center gap-1.5">
                                    <input
                                        :value="access.share_url"
                                        readonly
                                        class="field !py-1.5 !text-[11px]"
                                    />
                                    <button
                                        class="shrink-0 rounded border border-edge3 px-2 text-[12px] text-muted hover:border-teal hover:text-teal"
                                        @click="copyShare"
                                    >
                                        Copy
                                    </button>
                                </div>
                                <button
                                    class="mt-1.5 text-[12px] text-faint hover:text-red-400"
                                    @click="revokeShare"
                                >
                                    Revoke link
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    class="rounded border border-edge3 px-2 py-1 text-[12px] text-muted hover:border-amber hover:text-amber"
                                    @click="createShare"
                                >
                                    Create share link
                                </button>
                                <p class="mt-1 text-[11px] text-faint">
                                    Anyone with the link can read this entry —
                                    even if it's GM-only.
                                </p>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
