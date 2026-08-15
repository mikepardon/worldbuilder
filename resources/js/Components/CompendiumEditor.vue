<script setup>
import PickerModal from "@/Components/PickerModal.vue";
import SpellPicker from "@/Components/SpellPicker.vue";
import StatblockCard from "@/Components/StatblockCard.vue";
import { renderMarkdown } from "@/lib/compendiumFields";
import {
    ABILITY_LABELS,
    ALIGNMENTS,
    CONDITIONS,
    CREATURE_TYPES,
    DAMAGE_TYPES,
    LANGUAGES,
    SIZES,
    SKILLS,
} from "@/lib/dnd";
import { Link, router, usePage } from "@inertiajs/vue3";
import { marked } from "marked";
import { computed, nextTick, reactive, ref, watch } from "vue";

const aiName = computed(() => usePage().props.ai?.name ?? "Muse");

const props = defineProps({
    item: Object,
    allTags: { type: Array, default: () => [] },
    races: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    fieldSchema: { type: Array, default: () => [] },
    ai: {
        type: Object,
        default: () => ({ configured: false, remaining: 0, limit: 0 }),
    },
    // Admin edits the shared library (visibility flag); GMs edit a world copy (players/active/tags).
    admin: { type: Boolean, default: false },
    backHref: { type: String, default: "#" },
    backLabel: { type: String, default: "Compendium" },
    updateRoute: { type: String, required: true },
    draftRoute: { type: String, default: "" }, // when set, the Claude panel is shown
    destroyRoute: { type: String, default: "" },
    cloneRoute: { type: String, default: "" },
    rebuildRoute: { type: String, default: "" },
});

const isMonster = computed(() => props.item.item_type === "monster");
const locked = computed(() => !props.admin && !!props.item.locked);
const showClaude = computed(() => !!props.draftRoute && !locked.value);

const preview = reactive({ frame: true, wide: true });
const gridCols = computed(() =>
    showClaude.value ? "360px minmax(0,1fr) 380px" : "360px minmax(0,1fr)",
);

const form = reactive({
    name: props.item.name,
    summary: props.item.summary ?? "",
    is_private: props.item.is_private,
    is_active: props.item.is_active,
    visible: props.item.visible ?? true,
    document: props.item.document ?? "",
    block: props.item.block ?? null,
    fields: { ...(props.item.fields ?? {}) },
    tags: [...(props.item.tags ?? [])],
});

// Non-monster types edit structured fields: ensure each schema key exists, and seed the description
// from any legacy freeform document so switching to the field editor loses nothing.
if (!isMonster.value && props.fieldSchema.length) {
    props.fieldSchema.forEach((field) => {
        if (form.fields[field.key] === undefined) form.fields[field.key] = "";
    });
    if (!locked.value && !form.fields.description && props.item.document) {
        form.fields.description = props.item.document
            .replace(/^#.*\n+/, "")
            .trim();
    }
}

const saved = ref(true);
let timer = null;
const save = () => {
    let payload;
    if (props.admin) {
        payload = {
            name: form.name,
            summary: form.summary,
            document: form.document,
            block: form.block,
            fields: form.fields,
            visible: form.visible,
        };
    } else if (locked.value) {
        payload = { is_active: form.is_active, tags: form.tags };
    } else {
        payload = {
            name: form.name,
            summary: form.summary,
            is_private: form.is_private,
            is_active: form.is_active,
            document: form.document,
            block: form.block,
            fields: form.fields,
            tags: form.tags,
        };
    }
    router.put(route(props.updateRoute, props.item.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (saved.value = true),
    });
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

/* ---- Tags ---- */
const tagInput = ref("");
const addTag = () => {
    const t = tagInput.value.trim().toLowerCase();
    if (t && !form.tags.includes(t)) form.tags.push(t);
    tagInput.value = "";
};
const removeTag = (t) => {
    form.tags = form.tags.filter((x) => x !== t);
};

/* ---- Monster block helpers ---- */
const groups = {
    traits: "Traits",
    actions: "Actions",
    bonusActions: "Bonus Actions",
    reactions: "Reactions",
    legendary: "Legendary Actions",
    mythic: "Mythic Actions",
};
const addEntry = (key) => {
    form.block[key] = [...(form.block[key] ?? []), { name: "", desc: "" }];
};
const removeEntry = (key, i) => form.block[key].splice(i, 1);

/* ---- Spellcasting ---- */
const enableSpellcasting = () => {
    form.block.spellcasting = {
        intro: "",
        groups: [{ label: "", spells: [] }],
    };
};
const addSpellGroup = () =>
    form.block.spellcasting.groups.push({ label: "", spells: [] });
const removeSpellGroup = (i) => form.block.spellcasting.groups.splice(i, 1);

const typeExtra = computed(() => {
    const current = form.block?.type;
    const known = [...CREATURE_TYPES, ...(props.races ?? [])].map((s) =>
        s.toLowerCase(),
    );
    return current && !known.includes(String(current).toLowerCase())
        ? current
        : undefined;
});

const PICKERS = {
    saves: { title: "Saving Throws", mode: "scored", options: ABILITY_LABELS },
    skills: { title: "Skills", mode: "scored", options: SKILLS },
    resistances: {
        title: "Damage Resistances",
        mode: "plain",
        options: DAMAGE_TYPES,
    },
    immunities: {
        title: "Damage Immunities",
        mode: "plain",
        options: DAMAGE_TYPES,
    },
    conditionImmunities: {
        title: "Condition Immunities",
        mode: "plain",
        options: CONDITIONS,
    },
    languages: {
        title: "Languages",
        mode: "plain",
        options: LANGUAGES,
        allowCustom: true,
    },
};
const pickerFields = [
    ["saves", "Saving Throws"],
    ["skills", "Skills"],
    ["resistances", "Resistances"],
    ["immunities", "Immunities"],
    ["conditionImmunities", "Condition Immunities"],
    ["languages", "Languages"],
];
const pickerKey = ref();
const pickerConfig = computed(() =>
    pickerKey.value ? PICKERS[pickerKey.value] : undefined,
);

const rebuild = () =>
    router.post(
        route(props.rebuildRoute, props.item.id),
        {},
        { onSuccess: () => router.reload({ only: ["item"] }) },
    );
const cloneItem = () => router.post(route(props.cloneRoute, props.item.id));
const print = () => window.print();
const copyToken = () => navigator.clipboard?.writeText(props.item.embedToken);

const html = computed(() => {
    const source =
        !isMonster.value && !locked.value && props.fieldSchema.length
            ? renderMarkdown(props.fieldSchema, form.fields, form.name)
            : form.document;
    return marked.parse(source || "", { breaks: true });
});

/* ---- Claude assistant ---- */
const remaining = ref(props.ai.remaining);
const messages = ref([]);
const chatInput = ref("");
const asking = ref(false);
const chatError = ref("");
const chatBottom = ref(null);

const canAsk = computed(
    () =>
        props.ai.configured &&
        !locked.value &&
        (props.admin || remaining.value > 0),
);
const suggestions = computed(() =>
    isMonster.value
        ? [
              "Draft a full stat block for this creature.",
              "Make it a tougher, higher-CR version.",
              "Add a signature legendary action.",
          ]
        : [
              "Draft a full first version of this entry.",
              "Expand it into rich, evocative detail.",
              "Add a hook the GM can hang a scene on.",
          ],
);

const applyPatch = ({ document, block, summary, fields }) => {
    const hasDoc = typeof document === "string" && document.trim() !== "";
    const hasBlock = block && typeof block === "object";
    const hasFields =
        fields && typeof fields === "object" && Object.keys(fields).length > 0;
    if (!hasDoc && !hasBlock && !hasFields && !summary) return undefined;
    const undo = {
        document: form.document,
        summary: form.summary,
        block: form.block ? JSON.parse(JSON.stringify(form.block)) : undefined,
        fields: { ...form.fields },
    };
    if (summary) form.summary = summary;
    if (hasBlock) form.block = block;
    if (hasFields)
        Object.entries(fields).forEach(([key, value]) => {
            form.fields[key] = value;
        });
    if (hasDoc) form.document = document;
    return undo;
};

const undoPatch = (message) => {
    if (!message.undo) return;
    form.summary = message.undo.summary;
    form.document = message.undo.document;
    if (message.undo.block) form.block = message.undo.block;
    if (message.undo.fields) form.fields = message.undo.fields;
    message.applied = false;
    message.undo = undefined;
};

const send = async (text) => {
    const prompt = (text ?? chatInput.value).trim();
    if (!prompt || asking.value || !canAsk.value) return;
    chatInput.value = "";
    chatError.value = "";
    const history = messages.value.map((m) => ({
        role: m.role,
        content: m.content,
    }));
    messages.value.push({ role: "user", content: prompt });
    asking.value = true;
    await nextTick(() => chatBottom.value?.scrollIntoView());
    try {
        const res = await window.axios.post(
            route(props.draftRoute, props.item.id),
            { prompt, document: form.document, history },
        );
        const undo = applyPatch(res.data);
        messages.value.push({
            role: "assistant",
            content: res.data.reply || (undo ? "Updated the entry." : ""),
            applied: !!undo,
            undo,
        });
        if (res.data.ai) remaining.value = res.data.ai.remaining;
    } catch (e) {
        chatError.value = e.response?.data?.message ?? "The AI request failed.";
    } finally {
        asking.value = false;
        await nextTick(() => chatBottom.value?.scrollIntoView());
    }
};
const boxText = (t) => marked.parse(t ?? "", { breaks: true });
</script>

<template>
    <PickerModal
        v-if="pickerKey"
        v-model="form.block[pickerKey]"
        v-bind="pickerConfig"
        @close="pickerKey = undefined"
    />

    <div class="flex h-[calc(100vh-4rem)] flex-col bg-night">
        <!-- Top bar -->
        <div
            class="flex items-center gap-3 border-b border-edge2 bg-surface px-5 py-2"
        >
            <Link
                :href="backHref"
                class="shrink-0 text-sm text-[#b8bcc4] hover:text-ink"
                >← {{ backLabel }}</Link
            >
            <input
                v-model="form.name"
                :readonly="locked"
                class="min-w-0 max-w-xs flex-1 border-0 bg-transparent px-0 font-display text-[16px] text-bright focus:ring-0"
                :class="locked ? 'cursor-default opacity-90' : ''"
            />
            <span class="badge-players shrink-0">{{ item.typeLabel }}</span>
            <span
                v-if="locked"
                class="shrink-0 rounded-full border border-edge3 px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.1em] text-faint"
                >🔒 read-only</span
            >
            <code
                v-if="item.embedToken"
                class="shrink-0 rounded bg-raised px-2 py-0.5 font-mono text-[11px] text-faint"
                title="Paste this into any entry to embed it"
                @click="copyToken"
                >{{ item.embedToken }}</code
            >
            <div class="ml-auto flex items-center gap-3">
                <button
                    v-if="cloneRoute"
                    class="text-xs text-muted hover:text-amber"
                    @click="cloneItem"
                >
                    Clone
                </button>
                <button
                    v-if="rebuildRoute && item.hasSource && !locked"
                    class="text-xs text-muted hover:text-amber"
                    @click="rebuild"
                >
                    Rebuild
                </button>
                <button
                    class="text-xs text-muted hover:text-amber"
                    @click="print"
                >
                    Print
                </button>
                <span
                    class="text-xs"
                    :class="saved ? 'text-faint' : 'text-amber'"
                    >{{ saved ? "✓ Saved" : "Saving…" }}</span
                >
            </div>
        </div>

        <div
            class="grid min-h-0 flex-1 grid-rows-1 overflow-hidden"
            :style="{ gridTemplateColumns: gridCols }"
        >
            <!-- COLUMN 1: fields -->
            <div
                class="flex min-h-0 flex-col gap-4 overflow-auto border-r border-edge2 bg-surface px-4 pb-8 pt-4"
            >
                <div class="flex flex-col gap-1.5">
                    <label class="text-[13px] font-semibold text-ink"
                        >Summary</label
                    >
                    <input
                        v-model="form.summary"
                        :readonly="locked"
                        class="field !py-2 text-[13.5px]"
                        :class="locked ? 'opacity-90' : ''"
                        placeholder="One line shown in lists."
                    />
                </div>

                <!-- Admin: library visibility -->
                <label
                    v-if="admin"
                    class="flex items-center gap-2 text-sm text-muted"
                >
                    <input
                        type="checkbox"
                        v-model="form.visible"
                        class="rounded border-edge3 bg-raised text-teal focus:ring-0"
                    />
                    Visible to game masters (offered when importing)
                </label>

                <!-- GM: world-local visibility + tags -->
                <template v-else>
                    <div class="flex flex-wrap items-center gap-4">
                        <label
                            v-if="!locked"
                            class="flex items-center gap-2 text-sm text-muted"
                        >
                            <input
                                type="checkbox"
                                :checked="!form.is_private"
                                class="rounded border-edge3 bg-raised text-amber focus:ring-0"
                                @change="
                                    form.is_private = !$event.target.checked
                                "
                            />
                            Visible to players
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm text-muted"
                        >
                            <input
                                type="checkbox"
                                v-model="form.is_active"
                                class="rounded border-edge3 bg-raised text-teal focus:ring-0"
                            />
                            Active
                        </label>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[13px] font-semibold text-ink"
                            >Tags</label
                        >
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                v-for="t in form.tags"
                                :key="t"
                                class="flex items-center gap-1 rounded-full bg-raised px-2.5 py-0.5 text-xs text-muted"
                            >
                                {{ t }}
                                <button
                                    class="text-faint hover:text-blood"
                                    @click="removeTag(t)"
                                >
                                    ✕
                                </button>
                            </span>
                            <input
                                v-model="tagInput"
                                list="tag-suggestions"
                                class="field !w-32 !py-1 text-sm"
                                placeholder="add tag…"
                                @keydown.enter.prevent="addTag"
                                @keydown.,.prevent="addTag"
                            />
                            <datalist id="tag-suggestions">
                                <option
                                    v-for="t in allTags"
                                    :key="t"
                                    :value="t"
                                />
                            </datalist>
                        </div>
                    </div>
                </template>

                <div class="h-px bg-edge2"></div>

                <div
                    v-if="locked"
                    class="rounded-md border border-dashed border-edge3 bg-[#161920] px-3 py-2.5 text-[13px] text-muted"
                >
                    Imported from the global library and read-only. Toggle
                    <strong>Active</strong> to show or hide it, or
                    <button
                        class="text-teal hover:underline"
                        @click="cloneItem"
                    >
                        clone it
                    </button>
                    to make an editable copy.
                </div>

                <!-- Monster: structured stat block fields -->
                <template v-if="isMonster && form.block && !locked">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs text-faint"
                                >Size</label
                            >
                            <select
                                v-model="form.block.size"
                                class="field !py-2 text-[13.5px]"
                            >
                                <option v-for="s in SIZES" :key="s" :value="s">
                                    {{ s }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-faint"
                                >Type</label
                            >
                            <select
                                v-model="form.block.type"
                                class="field !py-2 text-[13.5px] capitalize"
                            >
                                <option v-if="typeExtra" :value="typeExtra">
                                    {{ typeExtra }}
                                </option>
                                <optgroup label="Creature type">
                                    <option
                                        v-for="t in CREATURE_TYPES"
                                        :key="t"
                                        :value="t"
                                    >
                                        {{ t }}
                                    </option>
                                </optgroup>
                                <optgroup
                                    v-if="races.length"
                                    label="Races (compendium)"
                                >
                                    <option
                                        v-for="r in races"
                                        :key="r"
                                        :value="r"
                                    >
                                        {{ r }}
                                    </option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs text-faint"
                                >Alignment</label
                            >
                            <select
                                v-model="form.block.alignment"
                                class="field !py-2 text-[13.5px] capitalize"
                            >
                                <option
                                    v-for="a in ALIGNMENTS"
                                    :key="a"
                                    :value="a"
                                >
                                    {{ a }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs text-faint"
                                >Armor Class</label
                            >
                            <input
                                v-model="form.block.ac"
                                class="field !py-2 text-[13.5px]"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-faint"
                                >Hit Points</label
                            >
                            <input
                                v-model="form.block.hp"
                                class="field !py-2 text-[13.5px]"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-faint"
                                >Speed</label
                            >
                            <input
                                v-model="form.block.speed"
                                class="field !py-2 text-[13.5px]"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-faint"
                                >Challenge</label
                            >
                            <input
                                v-model="form.block.cr"
                                class="field !py-2 text-[13.5px]"
                            />
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs text-faint"
                                >Legendary Resistance (uses)</label
                            >
                            <input
                                v-model="form.block.legendaryResistance"
                                class="field !py-2 text-[13.5px]"
                                placeholder="e.g. 3/Day — leave blank for none"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-faint"
                            >Ability scores</label
                        >
                        <div class="grid grid-cols-6 gap-1.5">
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
                            >
                                <div
                                    class="text-center text-[10px] uppercase text-faint"
                                >
                                    {{ a }}
                                </div>
                                <input
                                    v-model.number="form.block.abilities[a]"
                                    type="number"
                                    class="field !px-1 !py-1.5 text-center text-[13px]"
                                />
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-faint"
                            >Senses</label
                        >
                        <input
                            v-model="form.block.senses"
                            class="field !py-2 text-[13.5px]"
                            placeholder="darkvision 60 ft., passive Perception 13"
                        />
                    </div>

                    <div class="flex flex-col gap-3">
                        <div v-for="[key, label] in pickerFields" :key="key">
                            <label class="mb-1 block text-xs text-faint">{{
                                label
                            }}</label>
                            <button
                                type="button"
                                class="field flex w-full items-center justify-between gap-2 !py-2 text-left text-[13.5px]"
                                @click="pickerKey = key"
                            >
                                <span v-if="form.block[key]" class="truncate">{{
                                    form.block[key]
                                }}</span>
                                <span v-else class="text-faint">Choose…</span>
                                <span class="shrink-0 text-faint">▾</span>
                            </button>
                        </div>
                    </div>

                    <div v-for="(label, key) in groups" :key="key">
                        <div class="mb-1 flex items-center justify-between">
                            <label class="text-xs font-medium text-faint">{{
                                label
                            }}</label>
                            <button
                                class="text-xs text-amber"
                                @click="addEntry(key)"
                            >
                                + Add
                            </button>
                        </div>
                        <div
                            v-for="(entry, i) in form.block[key] ?? []"
                            :key="i"
                            class="mb-2 rounded-md border border-edge2 p-2"
                        >
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="entry.name"
                                    class="field !py-1.5 text-[13px]"
                                    placeholder="Name"
                                />
                                <button
                                    class="text-xs text-blood"
                                    @click="removeEntry(key, i)"
                                >
                                    ✕
                                </button>
                            </div>
                            <textarea
                                v-model="entry.desc"
                                rows="2"
                                class="field mt-1 text-[13px]"
                                placeholder="Description"
                            />
                        </div>
                    </div>

                    <!-- Spellcasting -->
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label class="text-xs font-medium text-faint"
                                >Spellcasting</label
                            >
                            <button
                                v-if="!form.block.spellcasting"
                                class="text-xs text-amber"
                                @click="enableSpellcasting"
                            >
                                + Add
                            </button>
                            <button
                                v-else
                                class="text-xs text-blood"
                                @click="form.block.spellcasting = null"
                            >
                                Remove
                            </button>
                        </div>
                        <div
                            v-if="form.block.spellcasting"
                            class="space-y-2 rounded-md border border-edge2 p-2"
                        >
                            <textarea
                                v-model="form.block.spellcasting.intro"
                                rows="3"
                                class="field !py-1.5 text-[13px]"
                                placeholder="The mage is a 9th-level spellcaster. Its spellcasting ability is Intelligence (spell save DC 14, +6 to hit with spell attacks)…"
                            />
                            <div
                                v-for="(g, gi) in form.block.spellcasting
                                    .groups"
                                :key="gi"
                                class="rounded border border-edge2 p-2"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="g.label"
                                        class="field !py-1 text-[13px]"
                                        placeholder="1st level (4 slots)"
                                    />
                                    <button
                                        class="text-xs text-blood"
                                        @click="removeSpellGroup(gi)"
                                    >
                                        ✕
                                    </button>
                                </div>
                                <div
                                    v-if="g.spells.length"
                                    class="mt-1.5 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="(s, si) in g.spells"
                                        :key="si"
                                        class="flex items-center gap-1 rounded-full bg-raised px-2 py-0.5 text-[12px] text-ink"
                                        :class="
                                            s.id ? 'ring-1 ring-teal/50' : ''
                                        "
                                        :title="
                                            s.id
                                                ? 'From the compendium'
                                                : 'Custom'
                                        "
                                    >
                                        {{ s.name }}
                                        <button
                                            class="text-faint hover:text-blood"
                                            @click="g.spells.splice(si, 1)"
                                        >
                                            ✕
                                        </button>
                                    </span>
                                </div>
                                <div class="mt-1.5">
                                    <SpellPicker
                                        :options="spells"
                                        @add="g.spells.push($event)"
                                    />
                                </div>
                            </div>
                            <button
                                class="text-xs text-amber"
                                @click="addSpellGroup"
                            >
                                + Add spell level
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Non-monster: structured fields -->
                <template
                    v-else-if="!isMonster && !locked && fieldSchema.length"
                >
                    <div
                        v-for="f in fieldSchema"
                        :key="f.key"
                        class="flex flex-col gap-1.5"
                    >
                        <label class="text-[13px] font-semibold text-ink">{{
                            f.label
                        }}</label>
                        <select
                            v-if="f.type === 'select'"
                            v-model="form.fields[f.key]"
                            class="field !py-2 text-[13.5px]"
                        >
                            <option value="">—</option>
                            <option v-for="o in f.options" :key="o" :value="o">
                                {{ o }}
                            </option>
                        </select>
                        <textarea
                            v-else-if="f.type === 'longtext'"
                            v-model="form.fields[f.key]"
                            rows="6"
                            :placeholder="f.placeholder"
                            class="field !py-2 text-[13.5px]"
                        />
                        <input
                            v-else
                            v-model="form.fields[f.key]"
                            :placeholder="f.placeholder"
                            class="field !py-2 text-[13.5px]"
                        />
                    </div>
                </template>

                <!-- Fallback markdown -->
                <div
                    v-else-if="!isMonster && !locked"
                    class="flex flex-col gap-1.5"
                >
                    <label class="text-[13px] font-semibold text-ink"
                        >Entry (Markdown)</label
                    >
                    <textarea
                        v-model="form.document"
                        rows="22"
                        class="field min-h-[48vh] w-full font-mono text-[13px] leading-relaxed"
                        placeholder="Write the entry in Markdown."
                    />
                </div>

                <div v-if="destroyRoute" class="mt-auto pt-4">
                    <button
                        class="text-sm text-blood hover:underline"
                        @click="router.delete(route(destroyRoute, item.id))"
                    >
                        {{ locked ? "Remove from this world" : "Delete entry" }}
                    </button>
                </div>
            </div>

            <!-- COLUMN 2: preview -->
            <div class="flex min-h-0 flex-col bg-[#0b0d10]">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-edge2 bg-surface px-3.5 py-2"
                >
                    <div
                        class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-[#6f7580]"
                    >
                        Preview
                    </div>
                    <template v-if="isMonster">
                        <div
                            class="flex items-center gap-0.5 rounded-full border border-edge3 bg-[#1a1d24] p-[3px]"
                        >
                            <button
                                class="rounded-full px-3 py-1 font-mono text-[10.5px] tracking-[0.1em]"
                                :class="
                                    preview.frame
                                        ? 'bg-amber text-night'
                                        : 'text-muted'
                                "
                                @click="preview.frame = !preview.frame"
                            >
                                FRAMED
                            </button>
                            <button
                                class="rounded-full px-3 py-1 font-mono text-[10.5px] tracking-[0.1em]"
                                :class="
                                    preview.wide
                                        ? 'bg-amber text-night'
                                        : 'text-muted'
                                "
                                @click="preview.wide = !preview.wide"
                            >
                                WIDE
                            </button>
                        </div>
                        <div class="text-[13px] font-light text-faint">
                            How it looks when embedded on a page.
                        </div>
                    </template>
                </div>
                <div
                    class="flex min-h-0 flex-1 justify-center overflow-auto p-6"
                >
                    <div
                        class="w-full"
                        :class="
                            isMonster && preview.wide
                                ? 'max-w-[820px]'
                                : 'max-w-[440px]'
                        "
                    >
                        <StatblockCard
                            v-if="isMonster && form.block"
                            class="printable"
                            :block="form.block"
                            :name="form.name"
                            :image="item.image_url"
                            :spells="spells"
                            :wide="preview.wide"
                            :framed="preview.frame"
                        />
                        <div
                            v-else
                            class="printable prose max-w-none rounded-md border border-amber-900/20 bg-[#fdf1dc] p-6 text-[#3a2c14]"
                            v-html="html"
                        />
                    </div>
                </div>
            </div>

            <!-- COLUMN 3: Claude -->
            <div
                v-if="showClaude"
                class="flex min-h-0 flex-col border-l border-edge2 bg-surface"
            >
                <div
                    class="flex items-center justify-between border-b border-edge2 px-4 py-2.5"
                >
                    <span class="eyebrow">✻ {{ aiName }}</span>
                    <span class="font-mono text-[10px] text-faint">
                        <template v-if="!ai.configured"
                            >not configured</template
                        >
                        <template v-else-if="admin">ready</template>
                        <template v-else
                            >{{ remaining }} / {{ ai.limit }} generations
                            left</template
                        >
                    </span>
                </div>
                <div class="min-h-0 flex-1 space-y-3 overflow-auto p-4">
                    <template v-if="ai.configured">
                        <p v-if="!messages.length" class="text-sm text-faint">
                            Ask {{ aiName }} to write or change this entry — it can
                            see the current
                            {{ isMonster ? "stat block" : "draft" }} and writes
                            straight into it.{{
                                admin
                                    ? ""
                                    : " Replies count against your world's budget."
                            }}
                        </p>
                        <div
                            v-if="!messages.length"
                            class="flex flex-col gap-2"
                        >
                            <button
                                v-for="s in suggestions"
                                :key="s"
                                class="rounded-md border border-edge3 bg-raised px-3 py-1.5 text-left text-sm text-ink transition hover:border-amber disabled:opacity-50"
                                :disabled="!canAsk"
                                @click="send(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                    </template>
                    <p v-else class="text-sm text-faint">
                        Ask an administrator to enable AI generation.
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
                                v-if="m.content"
                                class="prose prose-sm prose-invert max-w-none text-sm text-ink"
                                v-html="boxText(m.content)"
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
                    <p v-if="asking" class="text-sm text-faint">Thinking…</p>
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
                        v-model="chatInput"
                        rows="2"
                        class="field"
                        :disabled="!canAsk"
                        :placeholder="
                            canAsk
                                ? `Ask ${aiName} to change or draft this entry…`
                                : 'AI unavailable.'
                        "
                        @keydown.enter.exact.prevent="send()"
                    />
                    <button
                        type="submit"
                        :disabled="asking || !chatInput.trim() || !canAsk"
                        class="btn-primary mt-2 w-full justify-center"
                    >
                        Send
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
