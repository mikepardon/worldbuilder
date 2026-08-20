<script setup>
import CreditCost from "@/Components/CreditCost.vue";
import StatblockCard from "@/Components/StatblockCard.vue";
import StatblockEditor from "@/Components/StatblockEditor.vue";
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { captureError } from "@/monitoring";
import { Head, router, usePage } from "@inertiajs/vue3";
import { computed, reactive, ref } from "vue";

const aiName = computed(() => usePage().props.ai?.name ?? "Muse");

const props = defineProps({
    world: Object,
    campaign: Object,
    generators: { type: Array, default: () => [] },
    aiConfigured: { type: Boolean, default: false },
    creditsRemaining: { type: Number, default: 0 },
    batches: { type: Array, default: () => [] },
});

const selected = ref(props.generators[0]?.key ?? "names");
const selectedGen = computed(() => props.generators.find((g) => g.key === selected.value));
const context = ref("");
const count = ref(5);

// Live values for the selected generator's option controls.
const optionValues = reactive({});
const syncOptions = (overrides = {}) => {
    for (const key of Object.keys(optionValues)) delete optionValues[key];
    for (const option of selectedGen.value?.options ?? []) {
        optionValues[option.key] = overrides[option.key] ?? option.default;
    }
};
syncOptions();

const results = ref([]);
const batches = ref([...props.batches]);
const openBatchId = ref(null); // the saved batch currently loaded, so edits can be saved back
const loading = ref(false);
const error = ref("");
const savedFlash = ref(false);
const credits = ref(props.creditsRemaining);
const copiedIndex = ref(null);
const editingIndex = ref(null);
const busy = ref(null); // `${index}:${target}:${kind}` of the import currently running

const canGenerate = computed(() => props.aiConfigured && credits.value > 0 && !loading.value);

// Select options that aren't "any" travel with an import as meta, so they land on the created entry.
const activeMeta = computed(() => {
    const meta = {};
    for (const option of selectedGen.value?.options ?? []) {
        if (option.type !== "select") continue;
        const value = optionValues[option.key];
        if (value && value !== "any") meta[option.label] = value;
    }
    return meta;
});

const generate = () => {
    loading.value = true;
    error.value = "";
    copiedIndex.value = null;
    editingIndex.value = null;
    window.axios
        .post(route("generators.run", props.world.id), {
            kind: selected.value,
            count: count.value,
            context: context.value.trim() || null,
            options: { ...optionValues },
        })
        .then((res) => {
            results.value = res.data.items;
            credits.value = res.data.creditsRemaining;
            openBatchId.value = res.data.batch?.id ?? null;
            batches.value = res.data.batches ?? batches.value;
        })
        .catch((e) => {
            captureError(e);
            error.value = e.response?.data?.message ?? "Something went wrong — please try again.";
        })
        .finally(() => {
            loading.value = false;
        });
};

const reopenBatch = (batch) => {
    error.value = "";
    window.axios
        .get(route("generators.batches.show", { world: props.world.id, batch: batch.id }))
        .then((res) => {
            selected.value = res.data.kind;
            syncOptions(res.data.options ?? {});
            context.value = res.data.context ?? "";
            results.value = res.data.items ?? [];
            openBatchId.value = batch.id;
            editingIndex.value = null;
        })
        .catch((e) => {
            captureError(e);
            error.value = "Couldn't reopen that batch.";
        });
};

const saveBatch = () => {
    if (!openBatchId.value) return;
    window.axios
        .put(route("generators.batches.update", { world: props.world.id, batch: openBatchId.value }), {
            items: results.value,
        })
        .then((res) => {
            results.value = res.data.items;
            batches.value = res.data.batches ?? batches.value;
            savedFlash.value = true;
            window.setTimeout(() => (savedFlash.value = false), 1500);
        })
        .catch((e) => {
            captureError(e);
            error.value = e.response?.data?.message ?? "Couldn't save changes.";
        });
};

const deleteBatch = (batch) => {
    if (!window.confirm("Delete this saved generation?")) return;
    window.axios
        .delete(route("generators.batches.destroy", { world: props.world.id, batch: batch.id }))
        .then((res) => {
            batches.value = res.data.batches ?? batches.value;
            if (openBatchId.value === batch.id) {
                openBatchId.value = null;
                results.value = [];
            }
        });
};

const copy = (item, index) => {
    const parts = [item.title, item.detail, item.description].filter(Boolean);
    navigator.clipboard?.writeText(parts.join("\n\n"));
    copiedIndex.value = index;
    window.setTimeout(() => {
        if (copiedIndex.value === index) copiedIndex.value = null;
    }, 1500);
};

const toggleEdit = (index) => (editingIndex.value = editingIndex.value === index ? null : index);

const itemCategories = ["Wondrous item", "Weapon", "Armor", "Ring", "Rod", "Staff", "Wand", "Potion", "Scroll"];
const itemRarities = ["Common", "Uncommon", "Rare", "Very rare", "Legendary", "Artifact"];

const payloadFor = (item) => ({
    title: item.title,
    detail: item.detail,
    description: item.description || null,
    block: item.block || null,
    item: item.item || null,
    meta: activeMeta.value,
});

const runImport = (item, index, target, kind) => {
    busy.value = `${index}:${target}:${kind}`;
    const url = target === "statblock" ? route("generators.statblock", props.world.id) : route("generators.create", props.world.id);
    const payload = target === "statblock" ? { type: kind, ...payloadFor(item) } : { kind, ...payloadFor(item) };
    router.post(url, payload, { onFinish: () => (busy.value = null) });
};

const pick = (key) => {
    selected.value = key;
    syncOptions();
    results.value = [];
    openBatchId.value = null;
    editingIndex.value = null;
};
</script>

<template>
    <Head title="AI generators" />

    <WorldLayout :world="world">
        <div class="flex items-end justify-between gap-5">
            <div class="flex flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign?.name }}</div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">AI generators</div>
            </div>
            <div class="font-mono text-[11px] text-faint">{{ credits }} AI credits left</div>
        </div>

        <p class="max-w-2xl text-sm text-muted">
            Roll up people, places, items, encounters and hooks that fit this world. Tick what each result should
            carry — including a full stat block — then review, tweak, and turn it into an entry or compendium
            stat block. Each batch spends one AI credit and is saved so you can come back to it.
        </p>

        <p
            v-if="!aiConfigured"
            class="max-w-2xl rounded-md border border-amber/30 bg-amber/10 px-4 py-2.5 text-sm text-amber"
        >
            {{ aiName }} isn't set up on this server yet — ask an administrator to enable it.
        </p>

        <!-- Generator picker -->
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="g in generators"
                :key="g.key"
                class="rounded-full border px-3 py-1 text-sm transition"
                :class="selected === g.key ? 'border-amber bg-amber/10 text-amber' : 'border-edge3 text-muted hover:border-amber/60 hover:text-ink'"
                @click="pick(g.key)"
            >
                {{ g.label }}
            </button>
        </div>

        <!-- Controls -->
        <form class="panel flex flex-col gap-3 p-4" @submit.prevent="generate">
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex-1">
                    <span class="mb-1 block text-sm text-muted">Focus (optional)</span>
                    <input v-model="context" class="field" :placeholder="selectedGen?.placeholder" />
                </label>
                <label>
                    <span class="mb-1 block text-sm text-muted">How many</span>
                    <select v-model.number="count" class="field !w-auto">
                        <option :value="3">3</option>
                        <option :value="5">5</option>
                        <option :value="8">8</option>
                    </select>
                </label>
                <div class="flex flex-col items-center gap-1">
                    <button type="submit" class="btn-primary" :disabled="!canGenerate">
                        {{ loading ? "Generating…" : "Generate" }}
                    </button>
                    <CreditCost :kind="selected" prefix="Costs" />
                </div>
            </div>

            <!-- Per-type options -->
            <div v-if="selectedGen?.options?.length" class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-edge2 pt-3">
                <template v-for="option in selectedGen.options" :key="option.key">
                    <label v-if="option.type === 'toggle'" class="flex items-center gap-2 text-sm text-muted">
                        <input v-model="optionValues[option.key]" type="checkbox" class="accent-amber" />
                        {{ option.label }}
                    </label>
                    <label v-else class="flex items-center gap-2 text-sm text-muted">
                        {{ option.label }}
                        <select v-model="optionValues[option.key]" class="field !w-auto !py-1 text-sm">
                            <option v-for="choice in option.choices" :key="choice" :value="choice">{{ choice }}</option>
                        </select>
                    </label>
                </template>
            </div>

            <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
            <p v-else-if="credits <= 0 && aiConfigured" class="text-sm text-faint">
                You're out of AI credits for today — they reset daily, or top up from Billing.
            </p>
        </form>

        <!-- Recent generations -->
        <section v-if="batches.length" class="flex flex-col gap-2">
            <div class="eyebrow-muted">Recent generations</div>
            <div class="flex flex-wrap gap-1.5">
                <div
                    v-for="batch in batches"
                    :key="batch.id"
                    class="flex items-center gap-2 rounded-md border px-2.5 py-1 text-xs transition"
                    :class="openBatchId === batch.id ? 'border-amber text-amber' : 'border-edge3 text-muted hover:border-amber/60'"
                >
                    <button class="flex items-center gap-1.5" @click="reopenBatch(batch)">
                        <span class="font-medium">{{ batch.label }}</span>
                        <span v-if="batch.context" class="text-faint">· {{ batch.context }}</span>
                        <span class="text-faint">· {{ batch.count }} · {{ batch.ago }}</span>
                    </button>
                    <button class="text-faint hover:text-red-400" title="Delete" @click="deleteBatch(batch)">✕</button>
                </div>
            </div>
        </section>

        <!-- Results -->
        <div v-if="results.length" class="flex flex-col gap-3">
            <div v-if="openBatchId" class="flex items-center gap-3">
                <button class="btn-ghost !py-1.5 text-sm" @click="saveBatch">Save changes</button>
                <span v-if="savedFlash" class="text-xs text-teal">Saved</span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="(item, index) in results"
                    :key="index"
                    class="panel flex flex-col gap-2 p-4"
                    :class="item.block ? 'sm:col-span-2' : ''"
                >
                    <!-- Title + edit toggle -->
                    <div class="flex items-start justify-between gap-3">
                        <input v-if="editingIndex === index" v-model="item.title" class="field !py-1 font-display text-[17px] text-bright" />
                        <div v-else class="font-display text-[17px] text-bright">{{ item.title }}</div>
                        <button class="shrink-0 text-xs text-faint hover:text-amber" @click="toggleEdit(index)">
                            {{ editingIndex === index ? "Done" : "Edit" }}
                        </button>
                    </div>

                    <!-- Detail -->
                    <input v-if="editingIndex === index" v-model="item.detail" class="field !py-1 text-sm" placeholder="One-line detail" />
                    <p v-else-if="item.detail" class="text-sm text-muted">{{ item.detail }}</p>

                    <!-- Description -->
                    <textarea v-if="editingIndex === index" v-model="item.description" rows="3" class="field !py-1 text-sm" placeholder="Description"></textarea>
                    <p v-else-if="item.description" class="text-sm text-faint">{{ item.description }}</p>

                    <!-- Magic-item mechanics -->
                    <div v-if="item.item" class="rounded-md border border-edge2 bg-raised/60 p-3">
                        <div v-if="editingIndex === index" class="flex flex-col gap-2">
                            <div class="flex flex-wrap gap-2">
                                <select v-model="item.item.category" class="field !w-auto !py-1 text-sm">
                                    <option v-for="c in itemCategories" :key="c" :value="c">{{ c }}</option>
                                </select>
                                <select v-model="item.item.rarity" class="field !w-auto !py-1 text-sm">
                                    <option v-for="r in itemRarities" :key="r" :value="r">{{ r }}</option>
                                </select>
                                <input v-model="item.item.attunement" class="field !w-48 !py-1 text-sm" placeholder="Attunement (No / Yes …)" />
                            </div>
                            <textarea v-model="item.item.mechanics" rows="4" class="field !py-1 text-sm" placeholder="Mechanics — bonuses, charges, effects…"></textarea>
                        </div>
                        <div v-else>
                            <div class="mb-1 font-mono text-[10px] uppercase tracking-wide text-amber">
                                {{ item.item.category }} · {{ item.item.rarity }}<template v-if="item.item.attunement && item.item.attunement !== 'No'"> · attunement</template>
                            </div>
                            <p v-if="item.item.mechanics" class="whitespace-pre-wrap text-sm text-ink">{{ item.item.mechanics }}</p>
                        </div>
                    </div>

                    <!-- Stat block: editor when editing, always the frame preview -->
                    <div v-if="item.block" class="flex flex-col gap-3">
                        <StatblockEditor v-if="editingIndex === index" :block="item.block" />
                        <StatblockCard :block="item.block" :name="item.title" :wide="true" :framed="true" />
                    </div>

                    <!-- Actions -->
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <button
                            class="rounded-md border border-edge3 px-2.5 py-1 text-xs text-muted hover:border-teal hover:text-teal"
                            @click="copy(item, index)"
                        >
                            {{ copiedIndex === index ? "Copied" : "Copy" }}
                        </button>
                        <button
                            v-for="imp in selectedGen?.imports ?? []"
                            :key="imp.target + imp.kind"
                            class="rounded-md border border-edge3 px-2.5 py-1 text-xs text-muted hover:border-amber hover:text-amber disabled:opacity-50"
                            :disabled="busy === `${index}:${imp.target}:${imp.kind}`"
                            @click="runImport(item, index, imp.target, imp.kind)"
                        >
                            {{ busy === `${index}:${imp.target}:${imp.kind}` ? "Importing…" : imp.label }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
