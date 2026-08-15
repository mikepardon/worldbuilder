<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, reactive, ref } from "vue";

const props = defineProps({
    world: Object,
    tables: { type: Array, default: () => [] },
    aiConfigured: { type: Boolean, default: false },
    creditsRemaining: { type: Number, default: 0 },
});

const DICE = [4, 6, 8, 10, 12, 20, 100];

const blank = () => ({
    id: null,
    name: "",
    description: "",
    die: 20,
    rows: [],
    is_private: false,
});

const form = useForm(blank());
const editing = ref(false);
const credits = ref(props.creditsRemaining);

// Other tables this one can chain into (exclude self).
const linkTargets = computed(() =>
    props.tables.filter((table) => table.id !== form.id),
);

const openEditor = () => {
    rollResult.value = null;
    search.value = "";
    muse.messages = [];
    muse.error = "";
    form.clearErrors();
    editing.value = true;
};

const startNew = () => {
    Object.assign(form, blank());
    openEditor();
};

const edit = (table) => {
    Object.assign(form, blank(), {
        id: table.id,
        name: table.name,
        description: table.description ?? "",
        die: table.die,
        rows: (table.rows ?? []).map((row) => ({ ...row })),
        is_private: !!table.is_private,
    });
    openEditor();
};

const backToList = () => {
    editing.value = false;
    form.reset();
    form.clearErrors();
    rollResult.value = null;
};

const save = () => {
    const options = { preserveScroll: true, onSuccess: backToList };
    if (form.id) {
        form.put(route("tables.update", form.id), options);
    } else {
        form.post(route("tables.store", props.world.id), options);
    }
};

const remove = () => {
    if (!form.id) return;
    if (!confirm(`Delete “${form.name}”? This can’t be undone.`)) return;
    router.delete(route("tables.destroy", form.id), {
        preserveScroll: true,
        onSuccess: backToList,
    });
};

// --- Row editing -------------------------------------------------------------

const addRow = () => {
    const highest = form.rows.reduce(
        (max, row) => Math.max(max, Number(row.max) || 0),
        0,
    );
    const min = Math.min(highest + 1, form.die);
    form.rows.push({ min, max: min, result: "", detail: "", link: null });
};

const removeRow = (index) => form.rows.splice(index, 1);

// Auto-number the ranges back-to-back across the die, one entry per number left over.
const autoNumber = () => {
    if (form.rows.length === 0) return;
    const span = Math.max(1, Math.floor(form.die / form.rows.length));
    let cursor = 1;
    form.rows.forEach((row, index) => {
        const isLast = index === form.rows.length - 1;
        row.min = cursor;
        row.max = isLast ? form.die : Math.min(form.die, cursor + span - 1);
        cursor = row.max + 1;
    });
};

const search = ref("");
const visibleRows = computed(() => {
    const query = search.value.trim().toLowerCase();
    const indexed = form.rows.map((row, index) => ({ row, index }));
    if (!query) return indexed;
    return indexed.filter(({ row }) =>
        `${row.result} ${row.detail ?? ""}`.toLowerCase().includes(query),
    );
});

// Rows that don't cover the die cleanly get a gentle warning (not a hard error).
const coverageGap = computed(() => {
    const covered = new Set();
    for (const row of form.rows) {
        const min = Number(row.min);
        const max = Number(row.max);
        if (!Number.isFinite(min) || !Number.isFinite(max)) continue;
        for (let value = min; value <= max; value += 1) covered.add(value);
    }
    const missing = [];
    for (let value = 1; value <= form.die; value += 1) {
        if (!covered.has(value)) missing.push(value);
    }
    return missing;
});

// --- Rolling -----------------------------------------------------------------

const rollResult = ref(null);

const rollOnce = (die, rows) => {
    const value = 1 + Math.floor(Math.random() * die);
    const row = (rows ?? []).find(
        (candidate) =>
            value >= Number(candidate.min) && value <= Number(candidate.max),
    );
    return { value, row };
};

const roll = () => {
    const { value, row } = rollOnce(form.die, form.rows);
    const chain = [];
    const seen = new Set();
    let linkId = row?.link ?? null;
    // Follow linked tables so one roll cascades, guarding against a loop.
    while (linkId && !seen.has(linkId)) {
        seen.add(linkId);
        const linked = props.tables.find((table) => table.id === linkId);
        if (!linked) break;
        const outcome = rollOnce(linked.die, linked.rows);
        chain.push({
            table: linked.name,
            value: outcome.value,
            result: outcome.row?.result ?? "No matching row",
            detail: outcome.row?.detail ?? "",
        });
        linkId = outcome.row?.link ?? null;
    }
    rollResult.value = {
        value,
        result: row?.result ?? "No matching row",
        detail: row?.detail ?? "",
        chain,
    };
};

// --- Muse: a conversational assistant that builds and reworks the table -------

const muse = reactive({
    open: true,
    input: "",
    loading: false,
    error: "",
    progress: "",
    messages: [],
});

const canGenerate = computed(
    () => props.aiConfigured && credits.value > 0 && !muse.loading,
);

// Above this many faces a single request risks the gateway timeout, so we build in slices.
// Slices are kept small so each call returns in ~15s — well under both the AI and gateway limits.
const BATCH_THRESHOLD = 24;
const BATCH_SPAN = 24;

const requestTable = (payload) =>
    window.axios
        .post(route("tables.generate", props.world.id), {
            die: form.die,
            ...payload,
        })
        .then((res) => {
            credits.value = res.data.creditsRemaining ?? credits.value;
            return res.data;
        });

const museError = (error) => {
    const status = error.response?.status;
    muse.error =
        error.response?.data?.message ||
        (status === 504 || !status
            ? "Muse timed out. Try a smaller die, or generate again — large tables build in slices to avoid this."
            : `Muse request failed (HTTP ${status}).`);
};

// A big table is built one slice at a time so no request runs long enough to hit the 504 gateway
// timeout. Each slice avoids the results already produced, then they're stitched into one table.
const buildInBatches = async (prompt, history) => {
    const collected = [];
    const used = new Set();
    let next = 1;
    while (next <= form.die) {
        const max = Math.min(form.die, next + BATCH_SPAN - 1);
        muse.progress = `Building ${next}–${max} of d${form.die}…`;
        const data = await requestTable({
            prompt,
            history,
            range: { min: next, max },
            avoid: [...used].slice(0, 400),
        });
        for (const row of data.rows ?? []) {
            collected.push(row);
            if (row.result) used.add(row.result);
        }
        next = max + 1;
    }
    muse.progress = "";
    collected.sort((first, second) => first.min - second.min);
    form.rows = collected;
    muse.messages.push({
        role: "assistant",
        content: `Built a d${form.die} table with ${collected.length} rows.`,
        applied: collected.length,
    });
};

const send = async () => {
    const text = muse.input.trim();
    if (!text || !canGenerate.value) return;
    muse.input = "";
    muse.error = "";
    const history = muse.messages.map((message) => ({
        role: message.role,
        content: message.content,
    }));
    muse.messages.push({ role: "user", content: text });
    muse.loading = true;
    try {
        if (form.die > BATCH_THRESHOLD) {
            await buildInBatches(text, history);
        } else {
            const data = await requestTable({
                prompt: text,
                history,
                existing: form.rows
                    .filter((row) => (row.result ?? "").trim())
                    .map((row) => ({
                        min: Number(row.min) || 1,
                        max: Number(row.max) || 1,
                        result: row.result,
                    })),
            });
            const rows = data.rows ?? [];
            if (rows.length) form.rows = rows;
            muse.messages.push({
                role: "assistant",
                content:
                    data.reply ||
                    (rows.length
                        ? `Updated the table to ${rows.length} rows.`
                        : ""),
                applied: rows.length,
            });
        }
    } catch (error) {
        museError(error);
        muse.progress = "";
    } finally {
        muse.loading = false;
    }
};
</script>

<template>
    <Head title="Roll tables" />

    <WorldLayout :world="world">
        <!-- List view ------------------------------------------------------- -->
        <div v-if="!editing" class="space-y-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="font-display text-[28px] text-bright">
                        Roll tables
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted">
                        Random tables you can roll on the spot — encounters,
                        loot, weather, rumours. Rows can chain into other tables,
                        and Muse can build one for you.
                    </p>
                </div>
                <button class="btn-primary shrink-0" @click="startNew">
                    + New table
                </button>
            </div>

            <div
                v-if="tables.length"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    v-for="table in tables"
                    :key="table.id"
                    class="panel flex flex-col items-start gap-2 p-4 text-left transition hover:border-teal"
                    @click="edit(table)"
                >
                    <div class="flex w-full items-center gap-2">
                        <span class="min-w-0 flex-1 truncate text-ink">{{
                            table.name
                        }}</span>
                        <span v-if="table.is_private" class="badge-gm"
                            >GM</span
                        >
                    </div>
                    <p
                        v-if="table.description"
                        class="line-clamp-2 text-xs text-muted"
                    >
                        {{ table.description }}
                    </p>
                    <div class="mt-auto font-mono text-[11px] text-faint">
                        d{{ table.die }} · {{ table.rows.length }} rows
                    </div>
                </button>
            </div>
            <p
                v-else
                class="rounded-lg border border-dashed border-edge3 p-10 text-center text-sm text-muted"
            >
                No tables yet — create one, or let Muse draft it for you.
            </p>
        </div>

        <!-- Detail / editor view -------------------------------------------- -->
        <div v-else class="flex min-h-[calc(100vh-11rem)] flex-col gap-4">
            <div class="flex items-center gap-3">
                <button
                    class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted transition hover:border-teal hover:text-teal"
                    @click="backToList"
                >
                    ← All tables
                </button>
                <span class="font-display text-lg text-bright">{{
                    form.id ? form.name || "Untitled table" : "New table"
                }}</span>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-4 lg:flex-row">
                <!-- Editor -->
                <div class="min-w-0 flex-1 space-y-5">
                    <!-- Roll it -->
                    <div class="panel space-y-3 p-5">
                        <div class="flex items-center gap-3">
                            <button
                                class="btn-teal"
                                :disabled="!form.rows.length"
                                @click="roll"
                            >
                                🎲 Roll d{{ form.die }}
                            </button>
                            <span class="text-xs text-faint"
                                >Rolls the current rows (unsaved edits
                                included).</span
                            >
                        </div>
                        <div
                            v-if="rollResult"
                            class="rounded-lg border border-edge2 bg-[#191c22] p-4"
                        >
                            <div class="text-sm text-bright">
                                <span class="font-mono text-teal"
                                    >[{{ rollResult.value }}]</span
                                >
                                {{ rollResult.result }}
                            </div>
                            <div
                                v-if="rollResult.detail"
                                class="mt-1 text-xs text-muted"
                            >
                                {{ rollResult.detail }}
                            </div>
                            <div
                                v-for="(link, index) in rollResult.chain"
                                :key="index"
                                class="mt-2 border-l-2 border-edge3 pl-3 text-sm text-ink"
                            >
                                <span class="text-xs text-faint"
                                    >↳ {{ link.table }}</span
                                >
                                <div>
                                    <span class="font-mono text-teal"
                                        >[{{ link.value }}]</span
                                    >
                                    {{ link.result }}
                                </div>
                                <div
                                    v-if="link.detail"
                                    class="text-xs text-muted"
                                >
                                    {{ link.detail }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel space-y-4 p-5">
                        <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                            <label class="block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Name</span
                                >
                                <input
                                    v-model="form.name"
                                    class="field text-sm"
                                    placeholder="Wilderness encounters"
                                />
                                <span
                                    v-if="form.errors.name"
                                    class="mt-1 block text-xs text-red-400"
                                    >{{ form.errors.name }}</span
                                >
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Die</span
                                >
                                <select
                                    v-model.number="form.die"
                                    class="field text-sm"
                                >
                                    <option
                                        v-for="sides in DICE"
                                        :key="sides"
                                        :value="sides"
                                    >
                                        d{{ sides }}
                                    </option>
                                </select>
                            </label>
                            <label
                                class="flex items-end gap-2 pb-2 text-xs text-muted"
                            >
                                <input
                                    v-model="form.is_private"
                                    type="checkbox"
                                    class="rounded border-edge3 bg-transparent"
                                />
                                GM-only
                            </label>
                        </div>

                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Description (optional)</span
                            >
                            <input
                                v-model="form.description"
                                class="field text-sm"
                                placeholder="Roll when the party travels off-road."
                            />
                        </label>

                        <!-- Rows -->
                        <div class="space-y-2">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="text-xs text-faint"
                                    >Rows ({{ form.rows.length }})</span
                                >
                                <div class="flex items-center gap-3">
                                    <input
                                        v-if="form.rows.length"
                                        v-model="search"
                                        class="field h-8 w-40 text-xs"
                                        placeholder="Search rows…"
                                    />
                                    <button
                                        v-if="form.rows.length"
                                        class="text-xs text-muted hover:text-teal"
                                        title="Spread ranges evenly across the die"
                                        @click="autoNumber"
                                    >
                                        Auto-number
                                    </button>
                                    <button
                                        class="text-xs text-muted hover:text-teal"
                                        @click="addRow"
                                    >
                                        + Add row
                                    </button>
                                </div>
                            </div>

                            <p
                                v-if="coverageGap.length"
                                class="text-[11px] text-amber"
                            >
                                No row covers: {{ coverageGap.join(", ") }} — a
                                roll landing there returns nothing.
                            </p>
                            <span
                                v-if="form.errors.rows"
                                class="block text-xs text-red-400"
                                >{{ form.errors.rows }}</span
                            >

                            <div
                                v-for="{ row, index } in visibleRows"
                                :key="index"
                                class="rounded-lg border border-edge2 bg-[#191c22] p-2.5"
                            >
                                <div class="flex items-start gap-2">
                                    <div class="flex items-center gap-1 pt-0.5">
                                        <input
                                            v-model.number="row.min"
                                            type="number"
                                            min="1"
                                            :max="form.die"
                                            class="field h-8 w-12 px-1 text-center text-xs"
                                        />
                                        <span class="text-faint">–</span>
                                        <input
                                            v-model.number="row.max"
                                            type="number"
                                            min="1"
                                            :max="form.die"
                                            class="field h-8 w-12 px-1 text-center text-xs"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1 space-y-1.5">
                                        <input
                                            v-model="row.result"
                                            class="field h-8 text-sm"
                                            placeholder="Result"
                                        />
                                        <input
                                            v-model="row.detail"
                                            class="field h-8 text-xs"
                                            placeholder="Detail (optional)"
                                        />
                                        <select
                                            v-if="linkTargets.length"
                                            v-model="row.link"
                                            class="field h-8 text-xs"
                                        >
                                            <option :value="null">
                                                No linked table
                                            </option>
                                            <option
                                                v-for="target in linkTargets"
                                                :key="target.id"
                                                :value="target.id"
                                            >
                                                ↳ then roll {{ target.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <button
                                        class="pt-1 text-xs text-faint hover:text-red-400"
                                        title="Remove row"
                                        @click="removeRow(index)"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>

                            <p
                                v-if="!form.rows.length"
                                class="rounded-lg border border-dashed border-edge3 p-5 text-center text-xs text-muted"
                            >
                                No rows yet — add some, or ask Muse to draft the
                                table.
                            </p>
                        </div>

                        <div class="flex items-center gap-3 pt-1">
                            <button
                                class="btn-primary"
                                :disabled="form.processing"
                                @click="save"
                            >
                                {{ form.id ? "Save table" : "Create table" }}
                            </button>
                            <button
                                v-if="form.id"
                                class="text-sm text-faint hover:text-red-400"
                                @click="remove"
                            >
                                Delete
                            </button>
                            <button
                                class="text-sm text-faint hover:text-ink"
                                @click="backToList"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>

                </div>

                <!-- Muse: conversational assistant, collapsible -->
                <div
                    class="flex min-h-0 shrink-0 flex-col self-stretch rounded-xl border border-edge2 bg-surface"
                >
                    <button
                        v-if="!muse.open"
                        class="flex h-full w-full flex-col items-center gap-2 px-2 py-3 text-faint transition hover:text-amber"
                        title="Open Muse"
                        @click="muse.open = true"
                    >
                        <span class="text-sm">✦</span>
                        <span
                            class="text-[10px] uppercase tracking-[0.16em]"
                            style="writing-mode: vertical-rl"
                            >Muse</span
                        >
                    </button>
                    <template v-else>
                        <div
                            class="flex w-80 items-center justify-between border-b border-edge2 px-4 py-2.5"
                        >
                            <span
                                class="font-mono text-[11px] uppercase tracking-[0.18em] text-amber"
                                >✦ Muse</span
                            >
                            <div class="flex items-center gap-3">
                                <button
                                    v-if="muse.messages.length"
                                    class="text-xs text-faint hover:text-ink"
                                    @click="muse.messages = []"
                                >
                                    clear
                                </button>
                                <button
                                    class="text-faint hover:text-ink"
                                    title="Collapse Muse"
                                    @click="muse.open = false"
                                >
                                    »
                                </button>
                            </div>
                        </div>
                        <div
                            class="w-80 flex-1 space-y-3 overflow-y-auto p-4"
                            style="max-height: 60vh"
                        >
                            <p
                                v-if="!aiConfigured"
                                class="text-sm text-faint"
                            >
                                AI isn’t set up on this server yet.
                            </p>
                            <p
                                v-else-if="!muse.messages.length"
                                class="text-sm text-faint"
                            >
                                Ask Muse to draft a table, reweight it, or add
                                entries — e.g. “a d20 of spooky roadside events
                                for a haunted moor”. It rebuilds the rows for
                                you.
                            </p>
                            <div v-for="(message, index) in muse.messages" :key="index">
                                <div
                                    v-if="message.role === 'user'"
                                    class="ml-6 rounded-lg bg-amber/90 px-3 py-2 text-sm text-night"
                                >
                                    {{ message.content }}
                                </div>
                                <div v-else class="mr-2 text-sm text-ink">
                                    <p>{{ message.content }}</p>
                                    <p
                                        v-if="message.applied"
                                        class="mt-1 text-xs text-teal"
                                    >
                                        ✓ Rebuilt {{ message.applied }}
                                        {{ message.applied === 1 ? "row" : "rows" }}
                                    </p>
                                </div>
                            </div>
                            <p v-if="muse.loading" class="text-sm text-faint">
                                {{ muse.progress || "Thinking…" }}
                            </p>
                            <p v-if="muse.error" class="text-sm text-red-400">
                                {{ muse.error }}
                            </p>
                        </div>
                        <form
                            class="w-80 border-t border-edge2 p-3"
                            @submit.prevent="send"
                        >
                            <textarea
                                v-model="muse.input"
                                rows="2"
                                class="field text-sm"
                                :disabled="!aiConfigured"
                                placeholder="Draft a d20 of coastal weather…"
                                @keydown.enter.exact.prevent="send"
                            />
                            <div class="mt-2 flex items-center gap-3">
                                <button
                                    type="submit"
                                    class="btn-primary flex-1 justify-center"
                                    :disabled="!canGenerate || !muse.input.trim()"
                                >
                                    Send
                                </button>
                                <span class="text-[11px] text-faint"
                                    >{{ credits }} credits</span
                                >
                            </div>
                        </form>
                    </template>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
