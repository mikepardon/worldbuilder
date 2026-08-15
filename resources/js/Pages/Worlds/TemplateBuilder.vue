<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import TemplateBlockPreview from "@/Components/TemplateBlockPreview.vue";
import { scopeBlockCss } from "@/lib/readerCss";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, nextTick, reactive, ref } from "vue";

const props = defineProps({
    world: Object,
    // 'entry' styles a document; 'home' designs the reader home page.
    target: { type: String, default: "entry" },
    // The template being edited, or null for a new one.
    template: { type: Object, default: null },
    kindOptions: { type: Array, default: () => [] },
    fieldsByKind: { type: Object, default: () => ({}) },
    // { type: { label, hint, settings, classes } }
    blockTypes: { type: Object, default: () => ({}) },
    // The catalogue for the entry sidebar (widgets + common blocks) and its default set.
    sidebarBlockTypes: { type: Object, default: () => ({}) },
    sidebarStarter: { type: Array, default: () => [] },
    starterBlocks: { type: Array, default: () => [] },
    // [{ key, label, description, blocks }] — one-click starting layouts.
    presets: { type: Array, default: () => [] },
    // { kind: [{ id, title }] } — real entries the builder can preview against.
    entriesByKind: { type: Object, default: () => ({}) },
    // [{ id, name, type }] — compendium entries a Reference block can embed.
    compendiumItems: { type: Array, default: () => [] },
    // [{ slug, label }] — sections an archive template can target.
    sectionOptions: { type: Array, default: () => [] },
    // The section chosen before entering the archive builder.
    initialSection: { type: String, default: null },
    // { sectionSlug: [{ key, label }] } — the fields a section offers, for a table's column picker.
    fieldsBySection: { type: Object, default: () => ({}) },
    // { sectionSlug: [cards] } — real entries per section, so the archive preview shows actual data.
    sectionSampleItems: { type: Object, default: () => ({}) },
    // [{ url, name }] — the world's media library, for the Image block picker.
    mediaItems: { type: Array, default: () => [] },
    // [{ id, name }] — the world's maps, for the Map block picker.
    mapOptions: { type: Array, default: () => [] },
    // [{ id, name }] — the world's reusable block sets, for the Reusable block picker.
    reusableBlocks: { type: Array, default: () => [] },
});

// Repeatable settings rows (stat tiles, accordion panels, timeline events).
const addRow = (block, key, blank) => {
    if (!Array.isArray(block.settings[key])) block.settings[key] = [];
    block.settings[key].push({ ...blank });
};
const removeRow = (block, key, index) => block.settings[key].splice(index, 1);
const addValue = (block, key, value) => {
    if (!value) return;
    if (!Array.isArray(block.settings[key])) block.settings[key] = [];
    block.settings[key].push(value);
};
// Toggle a scalar value in a settings array (checkbox groups like the Entries kind filter).
const toggleValue = (block, key, value) => {
    if (!Array.isArray(block.settings[key])) block.settings[key] = [];
    const list = block.settings[key];
    const index = list.indexOf(value);
    if (index === -1) list.push(value);
    else list.splice(index, 1);
};

// All entries (flattened) for the Linked-entries picker.
const allEntries = computed(() =>
    Object.values(props.entriesByKind).flat(),
);
const entryTitle = (id) =>
    allEntries.value.find((e) => e.id === id)?.title ?? `#${id}`;

const uid = () => Math.random().toString(36).slice(2, 9);
const clone = (value) => JSON.parse(JSON.stringify(value));
// The default sidebar (portrait, quick facts, notes), with fresh ids — used to seed an empty sidebar so
// its widgets show up as editable blocks.
const seedSidebar = () =>
    clone(props.sidebarStarter).map((b) => ({ ...b, id: uid() }));

const isHome = computed(() => props.target === "home");
const isArchive = computed(() => props.target === "archive");
// A reusable block set — edited in the same builder, saved to its own endpoints.
const isBlock = computed(() => props.target === "block");
const defaultSection = () =>
    props.initialSection ?? props.sectionOptions[0]?.slug ?? "";
const initial = () =>
    props.template
        ? {
              id: props.template.id,
              name: props.template.name,
              kind: props.template.kind,
              target: props.target,
              section: props.template.section ?? defaultSection(),
              is_default: props.template.is_default ?? false,
              layout: {
                  blocks: (props.template.blocks ?? []).map((b) => clone(b)),
                  sidebar: (props.template.sidebar ?? []).length
                      ? props.template.sidebar.map((b) => clone(b))
                      : props.target === "entry"
                        ? seedSidebar()
                        : [],
                  hideSidebar: props.template.hideSidebar ?? false,
                  width: props.template.width ?? "normal",
              },
          }
        : {
              id: null,
              name: isHome.value ? "Home page" : "",
              kind: "location",
              target: props.target,
              section: defaultSection(),
              is_default: false,
              layout: {
                  blocks: clone(props.starterBlocks).map((b) => ({
                      ...b,
                      id: uid(),
                  })),
                  sidebar: props.target === "entry" ? seedSidebar() : [],
                  hideSidebar: false,
                  width: "normal",
              },
          };

const form = useForm(initial());
const blocks = computed(() => form.layout.blocks);
const selectedId = ref(blocks.value[0]?.id ?? null);
const schemaFor = (type) => props.blockTypes[type]?.settings ?? {};
const kindFields = computed(() => props.fieldsByKind[form.kind] ?? []);
// The fields a field-picker (quick facts on entries, table columns on archives) can choose from.
const availableFields = computed(() =>
    isArchive.value
        ? (props.fieldsBySection[form.section] ?? [])
        : kindFields.value,
);
const fieldLabels = computed(() =>
    Object.fromEntries(availableFields.value.map((f) => [f.key, f.label])),
);
const hooksOpen = ref(false); // the "style hooks" help modal
const fieldsOpen = ref(false); // the "entry fields you can use" help modal

// A field key as its reader token, e.g. "region" -> "{{ region }}". Built in the script (not the
// template) because a literal "}}" inside a {{ }} interpolation would break the Vue compiler.
const asToken = (key) => `{{ ${key} }}`;
const fieldTokenExample = "{{ field }}";
const fieldFallbackExample = "{{ field | fallback }}";

// The entry fields a GM can drop into any text, label or link as a token: the current kind's
// quick-facts fields (dynamic from the data) plus the built-in entry basics the reader always resolves.
const mergeFields = computed(() => [
    ...kindFields.value,
    { key: "title", label: "Title" },
    { key: "summary", label: "Summary" },
    { key: "kind", label: "Kind" },
    { key: "reading_time", label: "Reading time" },
    { key: "word_count", label: "Word count" },
    { key: "related_count", label: "Related count" },
    { key: "connection_count", label: "Connections count" },
]);

// Inserting a field token into a specific text/textarea setting at the caret. The picker modal doubles
// as a browser (opened from the header {} icon, no target) and an inserter (opened from a field's
// database icon, which sets the target key).
const fieldEls = {}; // setting key -> input/textarea element, for caret-aware insertion
const setFieldEl = (key) => (el) => {
    if (el) fieldEls[key] = el;
};
const insertTargetKey = ref(null);
const browseFields = () => {
    insertTargetKey.value = null;
    fieldsOpen.value = true;
};
const openFieldPicker = (key) => {
    insertTargetKey.value = key;
    fieldsOpen.value = true;
};
const closeFields = () => {
    fieldsOpen.value = false;
    insertTargetKey.value = null;
};
const insertField = (key) => {
    const targetKey = insertTargetKey.value;
    const block = selectedBlock.value;
    if (targetKey && block) {
        const token = asToken(key);
        const element = fieldEls[targetKey];
        const current = String(block.settings[targetKey] ?? "");
        if (element && typeof element.selectionStart === "number") {
            const start = element.selectionStart;
            const end = element.selectionEnd;
            block.settings[targetKey] =
                current.slice(0, start) + token + current.slice(end);
            const caret = start + token.length;
            // Restore focus and drop the caret just after the inserted token.
            nextTick(() => {
                element.focus();
                element.setSelectionRange(caret, caret);
            });
        } else {
            block.settings[targetKey] = current + token;
        }
    }
    closeFields();
};

// Conditional-visibility rule editing: the entry's own fields plus a few computed variables.
const ruleFieldOptions = computed(() => [
    ...kindFields.value,
    { key: "reading_time", label: "Reading time" },
    { key: "word_count", label: "Word count" },
    { key: "related_count", label: "Related count" },
    { key: "connection_count", label: "Connections count" },
]);
const setRule = (patch) => {
    const block = selectedBlock.value;
    if (!block) return;
    const next = {
        ...(block.visibleIf ?? { field: "", op: "set", value: "" }),
        ...patch,
    };
    block.visibleIf = next.field ? next : undefined;
};

// The "Add block" palette, grouped into categories in a fixed order.
const GROUP_ORDER = ["Structure", "Content", "Media", "Data", "Layout"];
const paletteGroups = computed(() => {
    const buckets = {};
    for (const [type, def] of Object.entries(props.blockTypes)) {
        const group = def.group ?? "Content";
        (buckets[group] ??= []).push({ type, def });
    }
    return GROUP_ORDER.filter((g) => buckets[g]).map((g) => ({
        group: g,
        items: buckets[g],
    }));
});
// The grouped "add block" options for the entry sidebar (flat common blocks).
const sidebarPaletteGroups = computed(() => {
    const buckets = {};
    for (const [type, def] of Object.entries(props.sidebarBlockTypes)) {
        (buckets[def.group ?? "Content"] ??= []).push({ type, def });
    }
    return GROUP_ORDER.filter((g) => buckets[g]).map((g) => ({
        group: g,
        items: buckets[g],
    }));
});

// Block types that may be dropped inside a Columns block (everything but Banner and nested Columns),
// grouped into the same palette categories.
const columnChildGroups = computed(() =>
    paletteGroups.value
        .map((grp) => ({
            group: grp.group,
            items: grp.items.filter(
                ({ type }) =>
                    type !== "banner" &&
                    type !== "columns" &&
                    type !== "repeater",
            ),
        }))
        .filter((grp) => grp.items.length),
);

// Blocks nest exactly one level (columns hold non-columns blocks), so walking the tree is simple.
const columnsOf = (block) =>
    Array.from({ length: columnCount(block) }, (_, i) => block.settings.cols?.[i] ?? []);
const sidebarBlocks = computed(() => form.layout.sidebar);
const allBlocks = () => {
    const out = [];
    for (const b of blocks.value) {
        out.push(b);
        if (b.type === "columns")
            for (const col of b.settings.cols ?? []) out.push(...col);
        if (b.type === "repeater") out.push(...(b.settings.blocks ?? []));
    }
    // Sidebar blocks are flat (common blocks, no containers).
    out.push(...sidebarBlocks.value);
    return out;
};
const selectedBlock = computed(() =>
    allBlocks().find((b) => b.id === selectedId.value),
);
// The array a block lives in — the root list, a column's list, or a repeater's block list.
const containerOf = (id) => {
    if (blocks.value.some((b) => b.id === id)) return blocks.value;
    for (const b of blocks.value) {
        if (b.type === "columns") {
            for (const col of b.settings.cols ?? [])
                if (col.some((c) => c.id === id)) return col;
        }
        if (
            b.type === "repeater" &&
            (b.settings.blocks ?? []).some((c) => c.id === id)
        ) {
            return b.settings.blocks;
        }
    }
    if (sidebarBlocks.value.some((b) => b.id === id)) return form.layout.sidebar;
    return null;
};
const indexUrl = computed(() =>
    route("worlds.templates.index", props.world.id),
);

const save = () => {
    // Reusable block sets save to their own endpoints; templates to theirs. On success the controller
    // redirects back to the template list.
    const routes = isBlock.value
        ? { update: "worlds.blocks.update", store: "worlds.blocks.store" }
        : { update: "worlds.templates.update", store: "worlds.templates.store" };
    if (form.id) {
        form.put(route(routes.update, form.id), { preserveScroll: true });
    } else {
        form.post(route(routes.store, props.world.id), {
            preserveScroll: true,
        });
    }
};

// Changing the kind invalidates any facts field selection, and the preview entry.
const onKindChange = () => {
    for (const block of blocks.value) {
        if (block.type === "facts") block.settings.fields = [];
    }
    previewEntryId.value = "";
    resetPreview();
};

// --- Block operations --------------------------------------------------------
const defaultsFor = (type) => {
    const settings = {};
    for (const [key, schema] of Object.entries(schemaFor(type))) {
        settings[key] = clone(schema.default ?? null);
    }
    return settings;
};
const makeBlock = (type) => {
    const block = {
        id: uid(),
        type,
        settings: defaultsFor(type),
        css: "",
        device: "all",
    };
    // A new columns block starts with empty columns to drop blocks into.
    if (type === "columns") {
        block.settings.cols = Array.from(
            { length: Number(block.settings.count) || 2 },
            () => [],
        );
    }
    // A new repeater starts with a text block that reads the item's title and summary.
    if (type === "repeater") {
        const child = makeBlock("text");
        child.settings.markdown =
            "### [{{ item.title }}]({{ item.url }})\n\n{{ item.summary }}";
        block.settings.blocks = [child];
    }
    return block;
};
const addBlock = (type) => {
    const block = makeBlock(type);
    form.layout.blocks.push(block);
    selectedId.value = block.id;
};
const presetsOpen = ref(false);
// Replace the canvas with a preset's blocks (fresh copies so edits don't touch the prop).
const applyPreset = (preset) => {
    form.layout.blocks = clone(preset.blocks);
    selectedId.value = blocks.value[0]?.id ?? null;
    presetsOpen.value = false;
};
// Save the selected block as a reusable block set (staying on this page so the template isn't lost).
const saveAsReusable = () => {
    const block = selectedBlock.value;
    if (!block) return;
    const name = window.prompt("Name this reusable block:");
    if (!name || !name.trim()) return;
    router.post(
        route("worlds.blocks.store", props.world.id),
        { name: name.trim(), stay: true, layout: { blocks: [clone(block)] } },
        { preserveState: true, preserveScroll: true },
    );
};
const addToColumn = (colsBlock, colIndex, type) => {
    if (!type) return;
    if (!Array.isArray(colsBlock.settings.cols)) colsBlock.settings.cols = [];
    while (colsBlock.settings.cols.length <= colIndex)
        colsBlock.settings.cols.push([]);
    const child = makeBlock(type);
    colsBlock.settings.cols[colIndex].push(child);
    selectedId.value = child.id;
};
const addSidebarBlock = (type) => {
    if (!type) return;
    const block = makeBlock(type);
    form.layout.sidebar.push(block);
    selectedId.value = block.id;
};
const addToRepeater = (repeaterBlock, type) => {
    if (!type) return;
    if (!Array.isArray(repeaterBlock.settings.blocks))
        repeaterBlock.settings.blocks = [];
    const child = makeBlock(type);
    repeaterBlock.settings.blocks.push(child);
    selectedId.value = child.id;
};

// Per-block CSS from every block (top-level and nested) — each scoped to its own element.
const previewCss = computed(() =>
    allBlocks()
        .map((b) => scopeBlockCss(b.css, b.id))
        .filter(Boolean)
        .join("\n"),
);

const removeBlock = (id) => {
    const container = containerOf(id);
    if (!container) return;
    const index = container.findIndex((b) => b.id === id);
    if (index >= 0) container.splice(index, 1);
    if (selectedId.value === id) selectedId.value = blocks.value[0]?.id ?? null;
};
const moveBlock = (id, delta) => {
    const container = containerOf(id);
    if (!container) return;
    const index = container.findIndex((b) => b.id === id);
    const target = index + delta;
    if (target < 0 || target >= container.length) return;
    [container[index], container[target]] = [
        container[target],
        container[index],
    ];
};

// --- Facts field picker ------------------------------------------------------
const factsShown = (block) =>
    (block.settings.fields ?? []).filter((key) =>
        availableFields.value.some((f) => f.key === key),
    );
const factsAvailable = (block) =>
    availableFields.value.filter(
        (f) => !(block.settings.fields ?? []).includes(f.key),
    );
const labelForField = (key) =>
    availableFields.value.find((f) => f.key === key)?.label ?? key;
const addFactField = (block, key) => block.settings.fields.push(key);
const removeFactField = (block, key) => {
    block.settings.fields = block.settings.fields.filter((k) => k !== key);
};
const moveFactField = (block, index, delta) => {
    const target = index + delta;
    if (target < 0 || target >= block.settings.fields.length) return;
    const fields = [...block.settings.fields];
    [fields[index], fields[target]] = [fields[target], fields[index]];
    block.settings.fields = fields;
};

// --- Columns block -----------------------------------------------------------
const columnCount = (block) => Number(block.settings.count) || 2;

// --- Preview data: a built-in sample, or a real entry the GM picks -----------
const samplePreview = () =>
    isArchive.value
        ? {
              sectionLabel:
                  props.sectionOptions.find((s) => s.slug === form.section)
                      ?.label ?? "",
              // Real entries for the chosen section (falls back to a small sample if the section is empty).
              items: (props.sectionSampleItems[form.section] ?? []).length
                  ? props.sectionSampleItems[form.section]
                  : [
                        { id: 1, title: "Sunspire Keep", kindLabel: "Location" },
                        { id: 2, title: "The Deepmarket", kindLabel: "Location" },
                        {
                            id: 3,
                            title: "Saltmere Harbour",
                            kindLabel: "Location",
                        },
                    ],
          }
        : isHome.value
        ? {
              name: props.world.name,
              description:
                  "A drowned-city sandbox of salt, smuggling and old gods.",
              sections: [
                  { label: "Locations", count: 14 },
                  { label: "People", count: 22 },
                  { label: "Lore", count: 9 },
              ],
              recent: [
                  { id: 1, title: "Sunspire Keep", kindLabel: "Location" },
                  { id: 2, title: "Dame Aldis Vane", kindLabel: "Person" },
                  { id: 3, title: "The Salt Accord", kindLabel: "Lore" },
              ],
              featured: [
                  { id: 4, title: "The Deepmarket", kindLabel: "Location" },
                  { id: 5, title: "Captain Vell", kindLabel: "Person" },
              ],
              bannerUrl: null,
          }
        : {
              eyebrow: "Location",
              title: "Sunspire Keep",
              summary:
                  "A cliff-top fortress guarding the only pass into the highlands.",
              readingTime: 4,
              words: 820,
              facts: [
                  { key: "type", label: "Type", value: "Fortress" },
                  { key: "region", label: "Region", value: "The Frostreach" },
                  { key: "ruler", label: "Ruler", value: "Dame Aldis Vane" },
                  { key: "population", label: "Population", value: "1,200" },
              ],
              content: "",
              related: [
                  { title: "Dame Aldis Vane", kind: "Person" },
                  { title: "The Frostreach", kind: "Location" },
              ],
              bannerUrl: null,
          };
const preview = reactive(samplePreview());
const previewEntryId = ref("");
const previewEntries = computed(() => props.entriesByKind[form.kind] ?? []);
const resetPreview = () => Object.assign(preview, samplePreview());
const loadPreview = () => {
    if (!previewEntryId.value) {
        resetPreview();
        return;
    }
    window.axios
        .get(
            route("worlds.templates.preview", {
                world: props.world.id,
                document: previewEntryId.value,
            }),
        )
        .then((res) => Object.assign(preview, samplePreview(), res.data))
        .catch(() => resetPreview());
};

</script>

<template>
    <Head :title="form.id ? `Edit ${form.name}` : 'New template'" />

    <WorldLayout :world="world" flush>
        <div class="flex h-full min-h-0 flex-col">
            <!-- Top bar -->
            <div
                class="flex flex-wrap items-center gap-3 border-b border-edge bg-surface px-5 py-2.5"
            >
                <Link
                    :href="indexUrl"
                    class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted transition hover:border-teal hover:text-teal"
                    >← Templates</Link
                >
                <input
                    v-model="form.name"
                    class="field !w-auto min-w-[200px] text-sm"
                    :placeholder="isBlock ? 'Block name' : 'Template name'"
                />
                <label
                    v-if="target === 'entry'"
                    class="flex items-center gap-2 text-xs text-faint"
                >
                    Kind
                    <select
                        v-model="form.kind"
                        class="field !w-auto text-sm"
                        @change="onKindChange"
                    >
                        <option v-for="k in kindOptions" :key="k" :value="k">
                            {{ k }}
                        </option>
                    </select>
                </label>
                <button
                    v-if="target === 'entry'"
                    class="text-xs text-muted hover:text-teal"
                    :class="{ 'text-teal': selectedId === null }"
                    title="Template settings — width, sidebar and defaults"
                    @click="selectedId = null"
                >
                    ⚙ Template settings
                </button>
                <span v-else-if="isHome" class="text-xs text-faint"
                    >Home page</span
                >
                <span v-else-if="isBlock" class="text-xs text-faint"
                    >Reusable block</span
                >
                <label
                    v-else-if="isArchive"
                    class="flex items-center gap-2 text-xs text-faint"
                >
                    Section
                    <select
                        v-model="form.section"
                        class="field !w-auto text-sm"
                        @change="resetPreview()"
                    >
                        <option
                            v-for="s in sectionOptions"
                            :key="s.slug"
                            :value="s.slug"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="target === 'entry' && previewEntries.length"
                    class="flex items-center gap-2 text-xs text-faint"
                >
                    Preview with
                    <select
                        v-model="previewEntryId"
                        class="field !w-auto text-sm"
                        @change="loadPreview"
                    >
                        <option value="">Sample entry</option>
                        <option
                            v-for="e in previewEntries"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.title }}
                        </option>
                    </select>
                </label>
                <span v-if="form.errors.name" class="text-xs text-red-400">{{
                    form.errors.name
                }}</span>
                <div class="ml-auto flex items-center gap-3">
                    <Link :href="indexUrl" class="text-sm text-faint hover:text-ink"
                        >Cancel</Link
                    >
                    <button
                        class="btn-primary"
                        :disabled="form.processing"
                        @click="save"
                    >
                        {{ form.id ? "Save template" : "Create template" }}
                    </button>
                </div>
            </div>

            <div class="flex min-h-0 flex-1">
                <!-- Left: palette -->
                <div
                    class="w-56 shrink-0 overflow-y-auto border-r border-edge2 bg-surface p-3"
                >
                    <!-- Start from a preset layout. -->
                    <div v-if="presets.length" class="mb-3">
                        <button
                            class="flex w-full items-center justify-between rounded-md border border-edge3 px-3 py-2 text-left text-sm text-ink transition hover:border-teal"
                            @click="presetsOpen = !presetsOpen"
                        >
                            <span>Start from a preset</span>
                            <span class="text-faint">{{
                                presetsOpen ? "▾" : "▸"
                            }}</span>
                        </button>
                        <div v-if="presetsOpen" class="mt-1.5 space-y-1.5">
                            <button
                                v-for="preset in presets"
                                :key="preset.key"
                                class="block w-full rounded-md border border-edge3 bg-[#191c22] px-3 py-2 text-left transition hover:border-teal"
                                @click="applyPreset(preset)"
                            >
                                <div class="text-sm text-ink">
                                    {{ preset.label }}
                                </div>
                                <div
                                    class="mt-0.5 text-[11px] leading-snug text-faint"
                                >
                                    {{ preset.description }}
                                </div>
                            </button>
                        </div>
                    </div>

                    <div
                        class="mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
                    >
                        Add block
                    </div>
                    <div
                        v-for="grp in paletteGroups"
                        :key="grp.group"
                        class="mb-3"
                    >
                        <div
                            class="mb-1 font-mono text-[9px] uppercase tracking-[0.18em] text-teal/80"
                        >
                            {{ grp.group }}
                        </div>
                        <button
                            v-for="{ type, def } in grp.items"
                            :key="type"
                            class="mb-1.5 block w-full rounded-md border border-edge3 px-3 py-2 text-left transition hover:border-teal"
                            @click="addBlock(type)"
                        >
                            <div class="text-sm text-ink">{{ def.label }}</div>
                            <div
                                class="mt-0.5 text-[11px] leading-snug text-faint"
                            >
                                {{ def.hint }}
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Center: styled preview canvas -->
                <div class="min-w-0 flex-1 overflow-y-auto bg-[#0e1014] p-6">
                    <component
                        :is="'style'"
                        v-if="previewCss"
                        v-text="previewCss"
                    />
                    <div class="mx-auto flex max-w-[1010px] items-start gap-4">
                      <div class="min-w-0 flex-1 space-y-2">
                        <div
                            v-for="block in blocks"
                            :key="block.id"
                            class="group relative rounded-lg border-2 transition"
                            :class="
                                selectedId === block.id
                                    ? 'border-teal'
                                    : 'border-transparent hover:border-edge3'
                            "
                            @click="selectedId = block.id"
                        >
                            <div
                                class="absolute -top-2.5 left-2 z-10 flex items-center gap-1 rounded bg-surface px-1.5 py-0.5 text-[10px] text-faint opacity-0 transition group-hover:opacity-100"
                                :class="{
                                    '!opacity-100': selectedId === block.id,
                                }"
                            >
                                <span class="cursor-grab">⠿</span>
                                {{ blockTypes[block.type]?.label ?? block.type }}
                                <button
                                    class="hover:text-ink"
                                    title="Move up"
                                    @click.stop="moveBlock(block.id, -1)"
                                >
                                    ↑
                                </button>
                                <button
                                    class="hover:text-ink"
                                    title="Move down"
                                    @click.stop="moveBlock(block.id, 1)"
                                >
                                    ↓
                                </button>
                                <button
                                    class="hover:text-red-400"
                                    title="Remove"
                                    @click.stop="removeBlock(block.id)"
                                >
                                    ✕
                                </button>
                            </div>

                            <div
                                class="overflow-hidden rounded-md"
                                :class="`wb-block-${block.id}`"
                            >
                                <!-- Columns: drop blocks into each column -->
                                <div v-if="block.type === 'columns'" class="p-4">
                                    <div
                                        class="grid gap-3"
                                        :style="{
                                            gridTemplateColumns: `repeat(${columnCount(block)}, minmax(0, 1fr))`,
                                        }"
                                    >
                                        <div
                                            v-for="(col, ci) in columnsOf(block)"
                                            :key="ci"
                                            class="wb-col rounded-md border border-dashed border-edge3 p-2"
                                        >
                                            <div
                                                v-for="child in col"
                                                :key="child.id"
                                                class="group/child relative mb-1.5 rounded border transition"
                                                :class="
                                                    selectedId === child.id
                                                        ? 'border-teal'
                                                        : 'border-transparent hover:border-edge3'
                                                "
                                                @click.stop="
                                                    selectedId = child.id
                                                "
                                            >
                                                <div
                                                    class="absolute -top-2 right-1 z-10 flex items-center gap-1 rounded bg-surface px-1 text-[9px] text-faint opacity-0 transition group-hover/child:opacity-100"
                                                    :class="{
                                                        '!opacity-100':
                                                            selectedId ===
                                                            child.id,
                                                    }"
                                                >
                                                    <button
                                                        class="hover:text-ink"
                                                        title="Move up"
                                                        @click.stop="
                                                            moveBlock(
                                                                child.id,
                                                                -1,
                                                            )
                                                        "
                                                    >
                                                        ↑
                                                    </button>
                                                    <button
                                                        class="hover:text-ink"
                                                        title="Move down"
                                                        @click.stop="
                                                            moveBlock(
                                                                child.id,
                                                                1,
                                                            )
                                                        "
                                                    >
                                                        ↓
                                                    </button>
                                                    <button
                                                        class="hover:text-red-400"
                                                        title="Remove"
                                                        @click.stop="
                                                            removeBlock(child.id)
                                                        "
                                                    >
                                                        ✕
                                                    </button>
                                                </div>
                                                <div
                                                    :class="`wb-block-${child.id}`"
                                                >
                                                    <TemplateBlockPreview
                                                        :block="child"
                                                        :preview="preview"
                                                        :field-labels="
                                                            fieldLabels
                                                        "
                                                        :compendium-items="
                                                            compendiumItems
                                                        "
                                                        :map-options="mapOptions"
                                                    :reusable-options="reusableBlocks"
                                                    />
                                                </div>
                                            </div>
                                            <select
                                                class="field mt-1 h-7 text-[11px]"
                                                @click.stop
                                                @change="
                                                    addToColumn(
                                                        block,
                                                        ci,
                                                        $event.target.value,
                                                    );
                                                    $event.target.value = '';
                                                "
                                            >
                                                <option value="">
                                                    + add block…
                                                </option>
                                                <optgroup
                                                    v-for="grp in columnChildGroups"
                                                    :key="grp.group"
                                                    :label="grp.group"
                                                >
                                                    <option
                                                        v-for="{
                                                            type,
                                                            def,
                                                        } in grp.items"
                                                        :key="type"
                                                        :value="type"
                                                    >
                                                        {{ def.label }}
                                                    </option>
                                                </optgroup>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Repeater: blocks repeated once per related entry / connection -->
                                <div
                                    v-else-if="block.type === 'repeater'"
                                    class="p-4"
                                >
                                    <div
                                        class="mb-2 font-mono text-[9px] uppercase tracking-[0.16em] text-teal/70"
                                    >
                                        For each
                                        {{
                                            block.settings.source ===
                                            "connections"
                                                ? "connection"
                                                : "related entry"
                                        }}
                                    </div>
                                    <div
                                        class="wb-repeater-item rounded-md border border-dashed border-edge3 p-2"
                                    >
                                        <div
                                            v-for="child in block.settings
                                                .blocks || []"
                                            :key="child.id"
                                            class="group/child relative mb-1.5 rounded border transition"
                                            :class="
                                                selectedId === child.id
                                                    ? 'border-teal'
                                                    : 'border-transparent hover:border-edge3'
                                            "
                                            @click.stop="selectedId = child.id"
                                        >
                                            <div
                                                class="absolute -top-2 right-1 z-10 flex items-center gap-1 rounded bg-surface px-1 text-[9px] text-faint opacity-0 transition group-hover/child:opacity-100"
                                                :class="{
                                                    '!opacity-100':
                                                        selectedId === child.id,
                                                }"
                                            >
                                                <button
                                                    class="hover:text-ink"
                                                    title="Move up"
                                                    @click.stop="
                                                        moveBlock(child.id, -1)
                                                    "
                                                >
                                                    ↑
                                                </button>
                                                <button
                                                    class="hover:text-ink"
                                                    title="Move down"
                                                    @click.stop="
                                                        moveBlock(child.id, 1)
                                                    "
                                                >
                                                    ↓
                                                </button>
                                                <button
                                                    class="hover:text-red-400"
                                                    title="Remove"
                                                    @click.stop="
                                                        removeBlock(child.id)
                                                    "
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                            <div :class="`wb-block-${child.id}`">
                                                <TemplateBlockPreview
                                                    :block="child"
                                                    :preview="preview"
                                                    :field-labels="fieldLabels"
                                                    :compendium-items="
                                                        compendiumItems
                                                    "
                                                    :map-options="mapOptions"
                                                :reusable-options="reusableBlocks"
                                                />
                                            </div>
                                        </div>
                                        <select
                                            class="field mt-1 h-7 text-[11px]"
                                            @click.stop
                                            @change="
                                                addToRepeater(
                                                    block,
                                                    $event.target.value,
                                                );
                                                $event.target.value = '';
                                            "
                                        >
                                            <option value="">
                                                + add block…
                                            </option>
                                            <optgroup
                                                v-for="grp in columnChildGroups"
                                                :key="grp.group"
                                                :label="grp.group"
                                            >
                                                <option
                                                    v-for="{
                                                        type,
                                                        def,
                                                    } in grp.items"
                                                    :key="type"
                                                    :value="type"
                                                >
                                                    {{ def.label }}
                                                </option>
                                            </optgroup>
                                        </select>
                                    </div>
                                </div>

                                <!-- Any other block -->
                                <TemplateBlockPreview
                                    v-else
                                    :block="block"
                                    :preview="preview"
                                    :field-labels="fieldLabels"
                                    :compendium-items="compendiumItems"
                                    :map-options="mapOptions"
                                :reusable-options="reusableBlocks"
                                />
                            </div>
                        </div>

                        <p
                            v-if="!blocks.length"
                            class="rounded-lg border border-dashed border-edge3 p-10 text-center text-sm text-muted"
                        >
                            Empty layout — add blocks from the left.
                        </p>
                      </div>

                      <!-- Sidebar column (entry only): flat common blocks shown in the reader's right rail. -->
                      <div
                          v-if="target === 'entry' && !form.layout.hideSidebar"
                          class="w-[264px] shrink-0 rounded-lg border border-dashed border-edge3 bg-surface/40 p-2"
                      >
                          <div
                              class="mb-1.5 px-1 font-mono text-[9px] uppercase tracking-[0.16em] text-faint"
                          >
                              Sidebar
                          </div>
                          <div class="space-y-1.5">
                              <div
                                  v-for="sb in sidebarBlocks"
                                  :key="sb.id"
                                  class="group/sb relative rounded-md border-2 transition"
                                  :class="
                                      selectedId === sb.id
                                          ? 'border-teal'
                                          : 'border-transparent hover:border-edge3'
                                  "
                                  @click="selectedId = sb.id"
                              >
                                  <div
                                      class="absolute -top-2 right-1 z-10 flex items-center gap-1 rounded bg-surface px-1 text-[9px] text-faint opacity-0 transition group-hover/sb:opacity-100"
                                      :class="{
                                          '!opacity-100': selectedId === sb.id,
                                      }"
                                  >
                                      <button
                                          class="hover:text-ink"
                                          title="Move up"
                                          @click.stop="moveBlock(sb.id, -1)"
                                      >
                                          ↑
                                      </button>
                                      <button
                                          class="hover:text-ink"
                                          title="Move down"
                                          @click.stop="moveBlock(sb.id, 1)"
                                      >
                                          ↓
                                      </button>
                                      <button
                                          class="hover:text-red-400"
                                          title="Remove"
                                          @click.stop="removeBlock(sb.id)"
                                      >
                                          ✕
                                      </button>
                                  </div>
                                  <div :class="`wb-block-${sb.id}`">
                                      <TemplateBlockPreview
                                          :block="sb"
                                          :preview="preview"
                                          :field-labels="fieldLabels"
                                          :compendium-items="compendiumItems"
                                          :map-options="mapOptions"
                                          :reusable-options="reusableBlocks"
                                      />
                                  </div>
                              </div>
                          </div>
                          <select
                              class="mt-1.5 w-full cursor-pointer rounded-md border border-teal/50 bg-[#12261f] px-2 py-1.5 text-center text-[12px] font-medium text-teal transition hover:border-teal hover:bg-[#16302680] focus:outline-none"
                              @change="
                                  addSidebarBlock($event.target.value);
                                  $event.target.value = '';
                              "
                          >
                              <option value="">+ add to sidebar…</option>
                              <optgroup
                                  v-for="grp in sidebarPaletteGroups"
                                  :key="grp.group"
                                  :label="grp.group"
                              >
                                  <option
                                      v-for="{ type, def } in grp.items"
                                      :key="type"
                                      :value="type"
                                  >
                                      {{ def.label }}
                                  </option>
                              </optgroup>
                          </select>
                      </div>
                    </div>
                </div>

                <!-- Right: settings for the selected block -->
                <div
                    class="w-72 shrink-0 overflow-y-auto border-l border-edge2 bg-surface p-4"
                >
                    <template v-if="selectedBlock">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div
                                class="font-mono text-[11px] uppercase tracking-[0.18em] text-amber"
                            >
                                {{ blockTypes[selectedBlock.type]?.label }}
                            </div>
                            <button
                                v-if="target === 'entry'"
                                type="button"
                                class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-edge3 text-[9px] leading-none text-faint transition hover:border-teal hover:text-teal"
                                title="Entry fields you can drop into text, labels and links"
                                @click="browseFields"
                            >
                                {}
                            </button>
                        </div>

                        <div
                            class="mb-4 rounded-md border border-edge2 bg-[#191c22] p-2.5"
                        >
                            <p class="text-xs leading-snug text-muted">
                                {{ blockTypes[selectedBlock.type]?.hint }}
                            </p>
                        </div>

                        <div class="space-y-4">
                            <template
                                v-for="(schema, key) in schemaFor(
                                    selectedBlock.type,
                                )"
                                :key="key"
                            >
                                <label
                                    v-if="schema.type === 'bool'"
                                    class="flex items-center gap-2 text-sm text-ink"
                                >
                                    <input
                                        v-model="selectedBlock.settings[key]"
                                        type="checkbox"
                                        class="rounded border-edge3 bg-transparent"
                                    />
                                    {{ schema.label }}
                                </label>

                                <label
                                    v-else-if="schema.type === 'select'"
                                    class="block"
                                >
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <select
                                        v-model="selectedBlock.settings[key]"
                                        class="field text-sm"
                                    >
                                        <option
                                            v-for="(
                                                optLabel, optValue
                                            ) in schema.options"
                                            :key="optValue"
                                            :value="optValue"
                                        >
                                            {{ optLabel }}
                                        </option>
                                    </select>
                                </label>

                                <label
                                    v-else-if="schema.type === 'textarea'"
                                    class="block"
                                >
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="relative">
                                        <textarea
                                            :ref="setFieldEl(key)"
                                            v-model="selectedBlock.settings[key]"
                                            rows="5"
                                            class="field text-sm"
                                            :class="{ 'pr-9': target === 'entry' }"
                                        ></textarea>
                                        <button
                                            v-if="target === 'entry'"
                                            type="button"
                                            class="absolute right-2 top-2 text-faint transition hover:text-teal"
                                            title="Insert an entry field"
                                            @mousedown.prevent
                                            @click="openFieldPicker(key)"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                class="h-3.5 w-3.5"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <ellipse
                                                    cx="12"
                                                    cy="5"
                                                    rx="9"
                                                    ry="3"
                                                />
                                                <path
                                                    d="M3 5v14a9 3 0 0 0 18 0V5"
                                                />
                                                <path d="M3 12a9 3 0 0 0 18 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </label>

                                <label
                                    v-else-if="schema.type === 'text'"
                                    class="block"
                                >
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="relative">
                                        <input
                                            :ref="setFieldEl(key)"
                                            v-model="selectedBlock.settings[key]"
                                            type="text"
                                            class="field text-sm"
                                            :class="{ 'pr-9': target === 'entry' }"
                                        />
                                        <button
                                            v-if="target === 'entry'"
                                            type="button"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-faint transition hover:text-teal"
                                            title="Insert an entry field"
                                            @mousedown.prevent
                                            @click="openFieldPicker(key)"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                class="h-3.5 w-3.5"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <ellipse
                                                    cx="12"
                                                    cy="5"
                                                    rx="9"
                                                    ry="3"
                                                />
                                                <path
                                                    d="M3 5v14a9 3 0 0 0 18 0V5"
                                                />
                                                <path d="M3 12a9 3 0 0 0 18 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </label>

                                <div v-else-if="schema.type === 'image'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <img
                                        v-if="selectedBlock.settings[key]"
                                        :src="selectedBlock.settings[key]"
                                        alt=""
                                        class="mb-1.5 max-h-28 rounded border border-edge2 object-cover"
                                    />
                                    <select
                                        :value="selectedBlock.settings[key]"
                                        class="field text-sm"
                                        @change="
                                            selectedBlock.settings[key] =
                                                $event.target.value
                                        "
                                    >
                                        <option value="">— choose —</option>
                                        <option
                                            v-for="m in mediaItems"
                                            :key="m.url"
                                            :value="m.url"
                                        >
                                            {{ m.name }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="!mediaItems.length"
                                        class="mt-1 text-xs text-muted"
                                    >
                                        No media yet — upload some in the media
                                        library.
                                    </p>
                                </div>

                                <div v-else-if="schema.type === 'stats'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="space-y-1.5">
                                        <div
                                            v-for="(s, i) in selectedBlock
                                                .settings[key]"
                                            :key="i"
                                            class="flex items-center gap-1.5"
                                        >
                                            <input
                                                v-model="s.value"
                                                placeholder="12k"
                                                class="field h-8 w-16 text-xs"
                                            />
                                            <input
                                                v-model="s.label"
                                                placeholder="Population"
                                                class="field h-8 flex-1 text-xs"
                                            />
                                            <button
                                                class="text-faint hover:text-red-400"
                                                @click="
                                                    removeRow(
                                                        selectedBlock,
                                                        key,
                                                        i,
                                                    )
                                                "
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        class="mt-1.5 text-xs text-muted hover:text-teal"
                                        @click="
                                            addRow(selectedBlock, key, {
                                                label: '',
                                                value: '',
                                            })
                                        "
                                    >
                                        + Add tile
                                    </button>
                                </div>

                                <div v-else-if="schema.type === 'panes'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(p, i) in selectedBlock
                                                .settings[key]"
                                            :key="i"
                                            class="rounded border border-edge2 p-2"
                                        >
                                            <div
                                                class="flex items-center gap-1.5"
                                            >
                                                <input
                                                    v-model="p.title"
                                                    placeholder="Panel title"
                                                    class="field h-8 flex-1 text-xs"
                                                />
                                                <button
                                                    class="text-faint hover:text-red-400"
                                                    @click="
                                                        removeRow(
                                                            selectedBlock,
                                                            key,
                                                            i,
                                                        )
                                                    "
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                            <textarea
                                                v-model="p.markdown"
                                                rows="2"
                                                placeholder="Markdown…"
                                                class="field mt-1 text-xs"
                                            ></textarea>
                                        </div>
                                    </div>
                                    <button
                                        class="mt-1.5 text-xs text-muted hover:text-teal"
                                        @click="
                                            addRow(selectedBlock, key, {
                                                title: '',
                                                markdown: '',
                                            })
                                        "
                                    >
                                        + Add panel
                                    </button>
                                </div>

                                <div v-else-if="schema.type === 'faq'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(item, i) in selectedBlock
                                                .settings[key]"
                                            :key="i"
                                            class="rounded border border-edge2 p-2"
                                        >
                                            <div
                                                class="flex items-center gap-1.5"
                                            >
                                                <input
                                                    v-model="item.question"
                                                    placeholder="Question"
                                                    class="field h-8 flex-1 text-xs"
                                                />
                                                <button
                                                    class="text-faint hover:text-red-400"
                                                    @click="
                                                        removeRow(
                                                            selectedBlock,
                                                            key,
                                                            i,
                                                        )
                                                    "
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                            <textarea
                                                v-model="item.answer"
                                                rows="2"
                                                placeholder="Answer (Markdown)…"
                                                class="field mt-1 text-xs"
                                            ></textarea>
                                        </div>
                                    </div>
                                    <button
                                        class="mt-1.5 text-xs text-muted hover:text-teal"
                                        @click="
                                            addRow(selectedBlock, key, {
                                                question: '',
                                                answer: '',
                                            })
                                        "
                                    >
                                        + Add question
                                    </button>
                                </div>

                                <div v-else-if="schema.type === 'images'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="mb-1.5 flex flex-wrap gap-1.5">
                                        <div
                                            v-for="(url, i) in selectedBlock
                                                .settings[key]"
                                            :key="i"
                                            class="relative"
                                        >
                                            <img
                                                :src="url"
                                                alt=""
                                                class="h-12 w-12 rounded border border-edge2 object-cover"
                                            />
                                            <button
                                                class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-surface text-[10px] text-faint hover:text-red-400"
                                                @click="
                                                    removeRow(
                                                        selectedBlock,
                                                        key,
                                                        i,
                                                    )
                                                "
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                    <select
                                        value=""
                                        class="field text-sm"
                                        @change="
                                            addValue(
                                                selectedBlock,
                                                key,
                                                $event.target.value,
                                            );
                                            $event.target.value = '';
                                        "
                                    >
                                        <option value="">+ add image…</option>
                                        <option
                                            v-for="m in mediaItems"
                                            :key="m.url"
                                            :value="m.url"
                                        >
                                            {{ m.name }}
                                        </option>
                                    </select>
                                </div>

                                <div v-else-if="schema.type === 'events'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(e, i) in selectedBlock
                                                .settings[key]"
                                            :key="i"
                                            class="rounded border border-edge2 p-2"
                                        >
                                            <div
                                                class="flex items-center gap-1.5"
                                            >
                                                <input
                                                    v-model="e.when"
                                                    placeholder="1267"
                                                    class="field h-8 w-16 text-xs"
                                                />
                                                <input
                                                    v-model="e.title"
                                                    placeholder="Event"
                                                    class="field h-8 flex-1 text-xs"
                                                />
                                                <button
                                                    class="text-faint hover:text-red-400"
                                                    @click="
                                                        removeRow(
                                                            selectedBlock,
                                                            key,
                                                            i,
                                                        )
                                                    "
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                            <input
                                                v-model="e.detail"
                                                placeholder="Detail (optional)"
                                                class="field mt-1 h-8 text-xs"
                                            />
                                        </div>
                                    </div>
                                    <button
                                        class="mt-1.5 text-xs text-muted hover:text-teal"
                                        @click="
                                            addRow(selectedBlock, key, {
                                                when: '',
                                                title: '',
                                                detail: '',
                                            })
                                        "
                                    >
                                        + Add event
                                    </button>
                                </div>

                                <div v-else-if="schema.type === 'kinds'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <p class="mb-1.5 text-[11px] text-faint">
                                        Leave all unticked to show every kind.
                                    </p>
                                    <div
                                        v-for="g in schema.groups"
                                        :key="g.label"
                                        class="mb-2"
                                    >
                                        <div
                                            class="mb-0.5 font-mono text-[9px] uppercase tracking-[0.16em] text-teal/70"
                                        >
                                            {{ g.label }}
                                        </div>
                                        <label
                                            v-for="k in g.kinds"
                                            :key="k.value"
                                            class="flex cursor-pointer items-center gap-2 py-0.5 text-sm text-ink"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    (
                                                        selectedBlock.settings[
                                                            key
                                                        ] || []
                                                    ).includes(k.value)
                                                "
                                                @change="
                                                    toggleValue(
                                                        selectedBlock,
                                                        key,
                                                        k.value,
                                                    )
                                                "
                                            />
                                            {{ k.label }}
                                        </label>
                                    </div>
                                </div>

                                <div v-else-if="schema.type === 'entries'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <ul
                                        v-if="
                                            selectedBlock.settings[key].length
                                        "
                                        class="mb-1.5 space-y-1"
                                    >
                                        <li
                                            v-for="(id, i) in selectedBlock
                                                .settings[key]"
                                            :key="id"
                                            class="flex items-center gap-1.5 rounded border border-edge2 bg-[#191c22] px-2 py-1 text-xs"
                                        >
                                            <span
                                                class="min-w-0 flex-1 truncate text-ink"
                                                >{{ entryTitle(id) }}</span
                                            >
                                            <button
                                                class="text-faint hover:text-red-400"
                                                @click="
                                                    removeRow(
                                                        selectedBlock,
                                                        key,
                                                        i,
                                                    )
                                                "
                                            >
                                                ✕
                                            </button>
                                        </li>
                                    </ul>
                                    <select
                                        value=""
                                        class="field text-sm"
                                        @change="
                                            addValue(
                                                selectedBlock,
                                                key,
                                                Number($event.target.value),
                                            );
                                            $event.target.value = '';
                                        "
                                    >
                                        <option value="">+ add entry…</option>
                                        <option
                                            v-for="e in allEntries"
                                            :key="e.id"
                                            :value="e.id"
                                        >
                                            {{ e.title }}
                                        </option>
                                    </select>
                                </div>

                                <label
                                    v-else-if="schema.type === 'reference'"
                                    class="block"
                                >
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <select
                                        v-model.number="
                                            selectedBlock.settings[key]
                                        "
                                        class="field text-sm"
                                    >
                                        <option :value="null">
                                            — choose an entry —
                                        </option>
                                        <option
                                            v-for="it in compendiumItems"
                                            :key="it.id"
                                            :value="it.id"
                                        >
                                            {{ it.name }} ({{ it.type }})
                                        </option>
                                    </select>
                                    <p
                                        v-if="!compendiumItems.length"
                                        class="mt-1 text-xs text-muted"
                                    >
                                        No compendium entries yet — add or import
                                        some first.
                                    </p>
                                </label>

                                <label
                                    v-else-if="schema.type === 'map'"
                                    class="block"
                                >
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <select
                                        v-model.number="
                                            selectedBlock.settings[key]
                                        "
                                        class="field text-sm"
                                    >
                                        <option :value="null">
                                            — choose a map —
                                        </option>
                                        <option
                                            v-for="m in mapOptions"
                                            :key="m.id"
                                            :value="m.id"
                                        >
                                            {{ m.name }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="!mapOptions.length"
                                        class="mt-1 text-xs text-muted"
                                    >
                                        No maps yet — create one first.
                                    </p>
                                </label>

                                <label
                                    v-else-if="schema.type === 'reusable-ref'"
                                    class="block"
                                >
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <select
                                        v-model.number="
                                            selectedBlock.settings[key]
                                        "
                                        class="field text-sm"
                                    >
                                        <option :value="null">
                                            — choose a block —
                                        </option>
                                        <option
                                            v-for="rb in reusableBlocks"
                                            :key="rb.id"
                                            :value="rb.id"
                                        >
                                            {{ rb.name }}
                                        </option>
                                    </select>
                                    <a
                                        :href="
                                            route(
                                                'worlds.blocks.create',
                                                world.id,
                                            )
                                        "
                                        class="mt-1 inline-block text-xs text-teal hover:underline"
                                        >+ New reusable block</a
                                    >
                                </label>

                                <div v-else-if="schema.type === 'fields'">
                                    <span class="mb-1 block text-xs text-faint">{{
                                        schema.label
                                    }}</span>
                                    <p
                                        v-if="!availableFields.length"
                                        class="text-xs text-muted"
                                    >
                                        No structured fields available.
                                    </p>
                                    <template v-else>
                                        <p
                                            v-if="
                                                !factsShown(selectedBlock).length
                                            "
                                            class="mb-1.5 text-xs text-muted"
                                        >
                                            All fields, default order.
                                        </p>
                                        <ul v-else class="mb-1.5 space-y-1">
                                            <li
                                                v-for="(fkey, i) in factsShown(
                                                    selectedBlock,
                                                )"
                                                :key="fkey"
                                                class="flex items-center gap-1.5 rounded border border-edge2 bg-[#191c22] px-2 py-1 text-xs"
                                            >
                                                <span
                                                    class="min-w-0 flex-1 truncate text-ink"
                                                    >{{
                                                        labelForField(fkey)
                                                    }}</span
                                                >
                                                <button
                                                    class="text-faint hover:text-ink disabled:opacity-30"
                                                    :disabled="i === 0"
                                                    @click="
                                                        moveFactField(
                                                            selectedBlock,
                                                            i,
                                                            -1,
                                                        )
                                                    "
                                                >
                                                    ↑
                                                </button>
                                                <button
                                                    class="text-faint hover:text-ink disabled:opacity-30"
                                                    :disabled="
                                                        i ===
                                                        factsShown(selectedBlock)
                                                            .length -
                                                            1
                                                    "
                                                    @click="
                                                        moveFactField(
                                                            selectedBlock,
                                                            i,
                                                            1,
                                                        )
                                                    "
                                                >
                                                    ↓
                                                </button>
                                                <button
                                                    class="text-faint hover:text-red-400"
                                                    @click="
                                                        removeFactField(
                                                            selectedBlock,
                                                            fkey,
                                                        )
                                                    "
                                                >
                                                    ✕
                                                </button>
                                            </li>
                                        </ul>
                                        <div class="flex flex-wrap gap-1">
                                            <button
                                                v-for="f in factsAvailable(
                                                    selectedBlock,
                                                )"
                                                :key="f.key"
                                                class="rounded-full border border-edge3 px-2 py-0.5 text-[11px] text-muted hover:border-teal hover:text-teal"
                                                @click="
                                                    addFactField(
                                                        selectedBlock,
                                                        f.key,
                                                    )
                                                "
                                            >
                                                + {{ f.label }}
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <p
                                v-if="
                                    !Object.keys(schemaFor(selectedBlock.type))
                                        .length
                                "
                                class="text-xs text-muted"
                            >
                                This block has no settings.
                            </p>

                            <!-- Conditional visibility: show the block only when a field matches. -->
                            <div class="block border-t border-edge2 pt-3">
                                <span class="mb-1 block text-xs text-faint"
                                    >Show this block only if…</span
                                >
                                <select
                                    :value="selectedBlock.visibleIf?.field || ''"
                                    class="field text-sm"
                                    @change="setRule({ field: $event.target.value })"
                                >
                                    <option value="">Always show</option>
                                    <option
                                        v-for="f in ruleFieldOptions"
                                        :key="f.key"
                                        :value="f.key"
                                    >
                                        {{ f.label }}
                                    </option>
                                </select>
                                <div
                                    v-if="selectedBlock.visibleIf?.field"
                                    class="mt-1.5 flex gap-1.5"
                                >
                                    <select
                                        :value="selectedBlock.visibleIf.op"
                                        class="field text-sm"
                                        @change="setRule({ op: $event.target.value })"
                                    >
                                        <option value="set">is set</option>
                                        <option value="unset">is empty</option>
                                        <option value="eq">equals</option>
                                        <option value="ne">is not</option>
                                    </select>
                                    <input
                                        v-if="
                                            ['eq', 'ne'].includes(
                                                selectedBlock.visibleIf.op,
                                            )
                                        "
                                        :value="selectedBlock.visibleIf.value"
                                        placeholder="value"
                                        class="field text-sm"
                                        @input="setRule({ value: $event.target.value })"
                                    />
                                </div>
                            </div>

                            <!-- Responsive: which breakpoint this block shows on. -->
                            <label class="block border-t border-edge2 pt-3">
                                <span class="mb-1 block text-xs text-faint"
                                    >Show on</span
                                >
                                <select
                                    v-model="selectedBlock.device"
                                    class="field text-sm"
                                >
                                    <option value="all">All devices</option>
                                    <option value="desktop">
                                        Desktop only
                                    </option>
                                    <option value="mobile">Mobile only</option>
                                </select>
                            </label>

                            <!-- Per-block custom CSS, scoped to this block only. -->
                            <label class="block border-t border-edge2 pt-3">
                                <span
                                    class="mb-1 flex items-center gap-1.5 text-xs text-faint"
                                >
                                    Custom CSS (this block only)
                                    <button
                                        v-if="
                                            blockTypes[selectedBlock.type]
                                                ?.classes?.length
                                        "
                                        type="button"
                                        class="flex h-4 w-4 items-center justify-center rounded-full border border-edge3 text-[9px] leading-none text-faint transition hover:border-teal hover:text-teal"
                                        title="Style hooks you can target"
                                        @click.prevent="hooksOpen = true"
                                    >
                                        ?
                                    </button>
                                </span>
                                <textarea
                                    v-model="selectedBlock.css"
                                    rows="4"
                                    maxlength="5000"
                                    spellcheck="false"
                                    class="field font-mono text-[11px]"
                                    placeholder="/* styles the whole block */
:root { padding: 24px; }
.wb-title { color: #e8c07a; }"
                                ></textarea>
                            </label>
                        </div>

                        <div class="mt-5 flex items-center justify-between">
                            <button
                                v-if="!isBlock"
                                class="text-xs text-muted hover:text-teal"
                                @click="saveAsReusable"
                            >
                                Save as reusable
                            </button>
                            <button
                                class="text-xs text-faint hover:text-red-400"
                                @click="removeBlock(selectedBlock.id)"
                            >
                                Remove this block
                            </button>
                        </div>
                    </template>
                    <!-- Template settings (shown when no block is selected). -->
                    <div v-else>
                        <div
                            class="mb-3 font-mono text-[11px] uppercase tracking-[0.18em] text-amber"
                        >
                            Template settings
                        </div>

                        <template v-if="target === 'entry'">
                            <label class="mb-3 block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Content width</span
                                >
                                <select
                                    v-model="form.layout.width"
                                    class="field text-sm"
                                >
                                    <option value="narrow">
                                        Narrow (easy reading)
                                    </option>
                                    <option value="normal">
                                        Normal (with sidebar)
                                    </option>
                                    <option value="wide">
                                        Wide (full width)
                                    </option>
                                </select>
                            </label>

                            <label
                                class="mb-3 flex items-center gap-2 text-sm text-ink"
                            >
                                <input
                                    type="checkbox"
                                    :checked="!form.layout.hideSidebar"
                                    @change="
                                        form.layout.hideSidebar =
                                            !$event.target.checked
                                    "
                                />
                                Show the sidebar
                            </label>

                            <label
                                class="mb-1 flex items-center gap-2 text-sm text-ink"
                            >
                                <input
                                    v-model="form.is_default"
                                    type="checkbox"
                                />
                                Default for all {{ form.kind }} entries
                            </label>
                            <p class="text-[11px] leading-snug text-faint">
                                Styles every {{ form.kind }} entry that hasn’t
                                picked a template of its own.
                            </p>
                        </template>

                        <p v-else class="text-sm text-muted">
                            Select a block in the canvas to edit its settings.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Style-hooks help modal -->
            <div
                v-if="hooksOpen && selectedBlock"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                @click.self="hooksOpen = false"
            >
                <div class="panel w-full max-w-md space-y-3 p-5">
                    <div class="flex items-center justify-between">
                        <div class="font-display text-lg text-bright">
                            {{ blockTypes[selectedBlock.type]?.label }} — style
                            hooks
                        </div>
                        <button
                            class="text-faint hover:text-ink"
                            @click="hooksOpen = false"
                        >
                            ✕
                        </button>
                    </div>
                    <p class="text-xs text-muted">
                        Target these selectors in the Custom CSS box to style
                        parts of this block. <code>:root</code> is the block
                        itself.
                    </p>
                    <div>
                        <div
                            v-for="c in blockTypes[selectedBlock.type]
                                ?.classes ?? []"
                            :key="c.selector"
                            class="flex items-baseline justify-between gap-3 border-b border-edge2 py-2 last:border-0"
                        >
                            <code class="font-mono text-sm text-teal">{{
                                c.selector
                            }}</code>
                            <span class="text-right text-xs text-muted">{{
                                c.label
                            }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Merge-field help modal: entry fields usable as {{ tokens }} in text, labels and links -->
            <div
                v-if="fieldsOpen && selectedBlock"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                @click.self="closeFields"
            >
                <div class="panel w-full max-w-md space-y-3 p-5">
                    <div class="flex items-center justify-between">
                        <div class="font-display text-lg capitalize text-bright">
                            {{ form.kind }} fields
                        </div>
                        <button
                            class="text-faint hover:text-ink"
                            @click="closeFields"
                        >
                            ✕
                        </button>
                    </div>
                    <p v-if="insertTargetKey" class="text-xs text-teal/90">
                        Click a field to insert it at your cursor.
                    </p>
                    <p class="text-xs text-muted">
                        Drop these into any text, label or link as
                        <code class="text-teal">{{ fieldTokenExample }}</code> and
                        the reader fills in each entry's own value. Add
                        <code class="text-teal">{{ fieldFallbackExample }}</code>
                        to set a default when the field is empty.
                    </p>
                    <div>
                        <component
                            :is="insertTargetKey ? 'button' : 'div'"
                            v-for="f in mergeFields"
                            :key="f.key"
                            type="button"
                            class="flex w-full items-baseline justify-between gap-3 border-b border-edge2 py-2 text-left last:border-0"
                            :class="
                                insertTargetKey
                                    ? 'cursor-pointer rounded px-1 hover:bg-edge2/60'
                                    : ''
                            "
                            @click="insertTargetKey && insertField(f.key)"
                        >
                            <code class="font-mono text-sm text-teal">{{
                                asToken(f.key)
                            }}</code>
                            <span class="text-right text-xs text-muted">{{
                                f.label
                            }}</span>
                        </component>
                    </div>
                    <p v-if="!kindFields.length" class="text-[11px] text-faint">
                        This kind has no custom quick-facts fields yet — only the
                        basics above are available.
                    </p>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
