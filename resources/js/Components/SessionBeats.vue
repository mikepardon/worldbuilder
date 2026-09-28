<script setup>
// The campaign board's session panel: the quest that frames the night, then its key moments as an
// ordered, editable list of beats. Reads its data from the session prop and saves straight to the API.
import { statusMeta } from "@/lib/sessionStatus";
import { router } from "@inertiajs/vue3";
import { ref, watch } from "vue";

const props = defineProps({
    session: { type: Object, required: true },
});

const BEAT_KINDS = [
    { key: "start", label: "Start of session" },
    { key: "event", label: "Event" },
    { key: "encounter", label: "Main encounter" },
    { key: "link", label: "Link" },
    { key: "cliffhanger", label: "Cliffhanger" },
];

// Local editable copies, rebuilt whenever the server hands back a fresh session (i.e. after a save).
const quest = ref("");
const beats = ref([]);
watch(
    () => props.session,
    (session) => {
        quest.value = session?.quest ?? "";
        beats.value = (session?.beats ?? []).map((beat) => ({ ...beat }));
    },
    { immediate: true, deep: true },
);

// Events are numbered in order; every other kind reads as its plain label.
const headingFor = (beat, index) => {
    if (beat.kind !== "event") {
        return BEAT_KINDS.find((k) => k.key === beat.kind)?.label ?? beat.kind;
    }
    const number = beats.value
        .slice(0, index + 1)
        .filter((b) => b.kind === "event").length;
    return `Event ${number}`;
};

const saveQuest = () =>
    router.patch(
        route("sessions.organise", props.session.id),
        { arc_id: props.session.arc_id ?? null, quest: quest.value },
        { preserveScroll: true },
    );

const saveBeat = (beat) =>
    router.put(
        route("beats.update", beat.id),
        { kind: beat.kind, body: beat.body },
        { preserveScroll: true },
    );

const newBeatKind = ref("event");
const addBeat = () =>
    router.post(
        route("beats.store", props.session.id),
        { kind: newBeatKind.value },
        { preserveScroll: true },
    );

const moveBeat = (index, direction) => {
    const ids = beats.value.map((b) => b.id);
    const to = index + direction;
    if (to < 0 || to >= ids.length) return;
    [ids[index], ids[to]] = [ids[to], ids[index]];
    router.put(
        route("beats.reorder", props.session.id),
        { ids },
        { preserveScroll: true },
    );
};

const removeBeat = (beat) => {
    if (confirm("Delete this beat?")) {
        router.delete(route("beats.destroy", beat.id), { preserveScroll: true });
    }
};
</script>

<template>
    <div class="panel flex flex-col gap-4 p-4">
        <div class="flex items-center gap-2">
            <div class="min-w-0">
                <div class="eyebrow-muted">Session notes</div>
                <div class="truncate font-display text-lg text-bright">
                    {{ session.title }}
                </div>
            </div>
            <span
                class="ml-auto shrink-0 rounded px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider"
                :class="statusMeta(session.status).pill"
                >{{ statusMeta(session.status).label }}</span
            >
        </div>

        <!-- Quest -->
        <div>
            <div
                class="font-mono text-[9px] uppercase tracking-[0.14em] text-amber"
            >
                Quest — the problem the players face
            </div>
            <textarea
                v-model="quest"
                rows="2"
                class="field mt-1 !py-2 text-sm"
                placeholder="e.g. Silence the choir under the chapel before it finishes its song."
                @blur="saveQuest"
            ></textarea>
        </div>

        <!-- Key moments -->
        <div class="flex flex-col gap-2">
            <div
                class="font-mono text-[9px] uppercase tracking-[0.14em] text-amber"
            >
                Key moments
            </div>

            <div
                v-for="(beat, i) in beats"
                :key="beat.id"
                class="rounded-md border p-2.5"
                :class="
                    beat.kind === 'encounter'
                        ? 'border-amber/50 bg-amber/5'
                        : 'border-edge3'
                "
            >
                <div class="flex items-center gap-2">
                    <span
                        class="font-mono text-[9px] uppercase tracking-wider"
                        :class="
                            beat.kind === 'encounter'
                                ? 'text-amber'
                                : 'text-faint'
                        "
                        >{{ headingFor(beat, i) }}</span
                    >
                    <div class="ml-auto flex items-center gap-1.5">
                        <button
                            type="button"
                            class="text-faint hover:text-ink disabled:opacity-30"
                            :disabled="i === 0"
                            title="Move up"
                            @click="moveBeat(i, -1)"
                        >
                            ▲
                        </button>
                        <button
                            type="button"
                            class="text-faint hover:text-ink disabled:opacity-30"
                            :disabled="i === beats.length - 1"
                            title="Move down"
                            @click="moveBeat(i, 1)"
                        >
                            ▼
                        </button>
                        <button
                            type="button"
                            class="text-faint hover:text-red-400"
                            title="Delete beat"
                            @click="removeBeat(beat)"
                        >
                            ✕
                        </button>
                    </div>
                </div>
                <textarea
                    v-model="beat.body"
                    rows="2"
                    class="field mt-1.5 !py-1.5 text-[13px]"
                    placeholder="What happens…"
                    @blur="saveBeat(beat)"
                ></textarea>
                <select
                    v-model="beat.kind"
                    class="field mt-1.5 !w-auto !py-1 text-[11px]"
                    @change="saveBeat(beat)"
                >
                    <option v-for="k in BEAT_KINDS" :key="k.key" :value="k.key">
                        {{ k.label }}
                    </option>
                </select>
            </div>

            <p v-if="!beats.length" class="text-sm text-muted">
                No moments yet — add them below, or (once a session has a recap)
                pull them in from the write-up.
            </p>

            <div class="flex items-center gap-2">
                <select
                    v-model="newBeatKind"
                    class="field !w-auto !py-1.5 text-[12px]"
                >
                    <option v-for="k in BEAT_KINDS" :key="k.key" :value="k.key">
                        {{ k.label }}
                    </option>
                </select>
                <button type="button" class="btn-primary" @click="addBeat">
                    Add moment
                </button>
            </div>
        </div>
    </div>
</template>
