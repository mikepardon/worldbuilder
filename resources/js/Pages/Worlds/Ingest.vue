<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, ref } from "vue";

const props = defineProps({
    world: { type: Object, required: true },
    campaign: { type: Object, default: () => ({}) },
    ingestion: { type: Object, default: null },
});

const ingestion = ref(props.ingestion);
const notes = ref("");
const busy = ref(false);
const error = ref("");
// Change ids the reviewer has ticked to apply. Everything is approved by default; untick to drop.
const approved = ref(new Set());

let poller;

const status = computed(() => ingestion.value?.status ?? null);
const changes = computed(() => ingestion.value?.changes ?? []);
const creates = computed(() => changes.value.filter((c) => c.action === "create"));
const updates = computed(() => changes.value.filter((c) => c.action === "update"));
const counts = computed(() => Object.entries(ingestion.value?.counts ?? {}));
const lastLog = computed(() => {
    const log = ingestion.value?.log ?? [];
    return log.length ? log[log.length - 1].line : ingestion.value?.message;
});
const progressPct = computed(() => {
    const planned = ingestion.value?.planned ?? 0;
    return planned > 0 ? Math.round(((ingestion.value?.applied ?? 0) / planned) * 100) : 0;
});

const stopPolling = () => {
    if (poller) {
        window.clearInterval(poller);
        poller = undefined;
    }
};

const setIngestion = (value) => {
    ingestion.value = value;
    if (value?.status === "ready") {
        // Default to applying everything; the reviewer unticks what they don't want.
        approved.value = new Set(value.changes.map((c) => c.id));
    }
    if (value?.status === "planning" || value?.status === "applying") {
        startPolling();
    } else {
        stopPolling();
    }
};

const startPolling = () => {
    if (poller || !ingestion.value) return;
    poller = window.setInterval(async () => {
        try {
            const { data } = await window.axios.get(
                route("worlds.ingest.status", [props.world.id, ingestion.value.id]),
            );
            setIngestion(data.ingestion);
        } catch {
            stopPolling();
        }
    }, 2000);
};

// Resume polling if we arrived mid-run (page reload during planning/applying).
if (status.value === "planning" || status.value === "applying") startPolling();
if (status.value === "ready") approved.value = new Set(changes.value.map((c) => c.id));

const start = async () => {
    if (notes.value.trim().length < 20 || busy.value) return;
    busy.value = true;
    error.value = "";
    try {
        const { data } = await window.axios.post(route("worlds.ingest.store", props.world.id), {
            source_text: notes.value,
        });
        setIngestion(data.ingestion);
    } catch (e) {
        error.value = e.response?.data?.message ?? "Could not start — please try again.";
    } finally {
        busy.value = false;
    }
};

const toggle = (id) => {
    const next = new Set(approved.value);
    next.has(id) ? next.delete(id) : next.add(id);
    approved.value = next;
};

const apply = async () => {
    if (busy.value) return;
    busy.value = true;
    error.value = "";
    try {
        const { data } = await window.axios.post(
            route("worlds.ingest.apply", [props.world.id, ingestion.value.id]),
            { approved: [...approved.value] },
        );
        setIngestion(data.ingestion);
    } catch (e) {
        error.value = e.response?.data?.message ?? "Could not apply — please try again.";
    } finally {
        busy.value = false;
    }
};

const resume = async () => {
    if (busy.value) return;
    busy.value = true;
    error.value = "";
    try {
        const { data } = await window.axios.post(
            route("worlds.ingest.resume", [props.world.id, ingestion.value.id]),
        );
        setIngestion(data.ingestion);
    } catch (e) {
        error.value = e.response?.data?.message ?? "Could not resume — please try again.";
    } finally {
        busy.value = false;
    }
};

const discardAndReset = async () => {
    if (ingestion.value && !window.confirm("Discard this plan? Nothing will be applied.")) return;
    stopPolling();
    if (ingestion.value) {
        try {
            await window.axios.delete(
                route("worlds.ingest.destroy", [props.world.id, ingestion.value.id]),
            );
        } catch {
            /* best effort — resetting the form either way */
        }
    }
    ingestion.value = undefined;
    notes.value = "";
    error.value = "";
};

const kindLabel = (c) => `${c.action === "update" ? "Update" : "New"} · ${c.target === "compendium" ? "compendium" : "wiki"} · ${c.kind}`;

onBeforeUnmount(stopPolling);
</script>

<template>
    <Head title="Add knowledge" />

    <WorldLayout :world="world">
        <div class="mx-auto max-w-3xl">
            <div class="mb-6 flex flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">
                    {{ campaign.name }}
                </div>
                <div class="font-display text-[28px] leading-[1.05] text-bright">Add knowledge</div>
                <p class="max-w-2xl text-sm text-muted">
                    Paste lore, session notes or any freeform text. The AI reviews it against what your world
                    already knows, then proposes new entries and updates for you to approve before anything is
                    written. Applying uses your AI credits.
                </p>
            </div>

            <p v-if="error" class="mb-4 rounded-md border border-blood/40 bg-blood/10 px-4 py-2.5 text-sm text-red-300">
                {{ error }}
            </p>

            <!-- Input -->
            <section v-if="!ingestion || status === 'failed'" class="panel p-5">
                <p v-if="status === 'failed'" class="mb-3 rounded-md border border-blood/40 bg-blood/10 px-4 py-2.5 text-sm text-red-300">
                    {{ ingestion?.error }}
                </p>
                <label class="mb-1.5 block text-[13px] font-semibold text-ink">Your notes</label>
                <textarea
                    v-model="notes"
                    rows="12"
                    class="field w-full font-mono text-[13px]"
                    placeholder="Paste the lore or notes you want folded into this world…"
                />
                <div class="mt-3 flex items-center justify-between">
                    <span class="font-mono text-[11px] text-faint">{{ notes.trim().length }} characters</span>
                    <button
                        type="button"
                        class="rounded-md bg-amber px-4 py-2 text-sm font-semibold text-night transition hover:bg-amber/90 disabled:opacity-50"
                        :disabled="notes.trim().length < 20 || busy"
                        @click="start"
                    >
                        {{ busy ? "Reviewing…" : "Review my notes" }}
                    </button>
                </div>
            </section>

            <!-- Planning -->
            <section v-else-if="status === 'planning'" class="panel flex items-center gap-3 p-5 text-sm text-muted">
                <span class="h-2 w-2 animate-pulse rounded-full bg-amber" />
                {{ lastLog || "Reviewing your notes…" }}
            </section>

            <!-- Review -->
            <section v-else-if="status === 'ready'">
                <div class="mb-3 flex items-center justify-between">
                    <div class="eyebrow-muted">Proposed changes</div>
                    <span class="font-mono text-[11px] text-faint">{{ approved.size }} of {{ changes.length }} selected</span>
                </div>

                <div v-for="group in [{ label: 'New entries', items: creates }, { label: 'Updates to existing entries', items: updates }]" :key="group.label">
                    <div v-if="group.items.length" class="mb-2 mt-4 font-mono text-[10px] uppercase tracking-wider text-faint">
                        {{ group.label }}
                    </div>
                    <label
                        v-for="c in group.items"
                        :key="c.id"
                        class="mb-2 flex cursor-pointer items-start gap-3 rounded-lg border border-edge2 bg-raised/40 p-3 transition hover:border-edge3"
                        :class="approved.has(c.id) ? '' : 'opacity-55'"
                    >
                        <input
                            type="checkbox"
                            class="mt-1 rounded border-edge3 bg-raised text-amber focus:ring-0"
                            :checked="approved.has(c.id)"
                            @change="toggle(c.id)"
                        />
                        <div class="min-w-0">
                            <div class="flex items-baseline gap-2">
                                <span class="font-medium text-ink">{{ c.title }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 font-mono text-[9px] uppercase tracking-wide"
                                    :class="c.action === 'update' ? 'bg-teal/15 text-teal' : 'bg-amber/15 text-amber'"
                                >{{ kindLabel(c) }}</span>
                            </div>
                            <p v-if="c.rationale" class="mt-0.5 text-sm text-muted">{{ c.rationale }}</p>
                        </div>
                    </label>
                </div>

                <div class="mt-5 flex items-center justify-between">
                    <button type="button" class="text-sm text-faint transition hover:text-amber" @click="discardAndReset">
                        Discard
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-amber px-4 py-2 text-sm font-semibold text-night transition hover:bg-amber/90 disabled:opacity-50"
                        :disabled="approved.size === 0 || busy"
                        @click="apply"
                    >
                        {{ busy ? "Starting…" : `Apply ${approved.size} ${approved.size === 1 ? "change" : "changes"}` }}
                    </button>
                </div>
            </section>

            <!-- Applying -->
            <section v-else-if="status === 'applying'" class="panel p-5">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span class="text-muted">{{ lastLog || "Applying…" }}</span>
                    <span class="font-mono text-xs text-faint">{{ ingestion.applied }} / {{ ingestion.planned }}</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-raised">
                    <div class="h-full rounded-full bg-teal transition-all" :style="{ width: progressPct + '%' }" />
                </div>
            </section>

            <!-- Paused (out of credits) -->
            <section v-else-if="status === 'paused'" class="panel border-amber/30 p-5">
                <p class="text-sm text-amber">
                    Paused — you’re out of AI credits ({{ ingestion.applied }} of {{ ingestion.planned }} applied).
                    Top up, then resume to finish the rest.
                </p>
                <div class="mt-4 flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded-md bg-amber px-4 py-2 text-sm font-semibold text-night transition hover:bg-amber/90 disabled:opacity-50"
                        :disabled="busy"
                        @click="resume"
                    >
                        {{ busy ? "Resuming…" : "Resume" }}
                    </button>
                    <a :href="route('billing.index')" class="text-sm text-faint transition hover:text-amber">Top up credits ↗</a>
                </div>
            </section>

            <!-- Completed -->
            <section v-else-if="status === 'completed'" class="panel border-teal/30 p-5">
                <div class="font-display text-lg text-bright">Done</div>
                <p class="mt-1 text-sm text-muted">{{ ingestion.message }}</p>
                <div v-if="counts.length" class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-for="[kind, n] in counts"
                        :key="kind"
                        class="rounded-full bg-raised px-2.5 py-1 font-mono text-[11px] text-muted ring-1 ring-edge2"
                    >{{ n }} {{ kind }}</span>
                </div>
                <button
                    type="button"
                    class="mt-5 rounded-md border border-edge3 px-4 py-2 text-sm text-ink transition hover:border-amber hover:text-amber"
                    @click="discardAndReset"
                >
                    Add more knowledge
                </button>
            </section>
        </div>
    </WorldLayout>
</template>
