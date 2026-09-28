<script setup>
import SessionBeats from "@/Components/SessionBeats.vue";
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { STATUS_ORDER, nextStatus, statusMeta } from "@/lib/sessionStatus";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    players: { type: Array, default: () => [] },
    arcs: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
});

/* ---- arc selection ---- */
const UNASSIGNED = "unassigned";
const selectedArcId = ref(props.arcs[0]?.id ?? UNASSIGNED);
const selectedArc = computed(() =>
    props.arcs.find((a) => a.id === selectedArcId.value),
);
const arcIndex = computed(() =>
    props.arcs.findIndex((a) => a.id === selectedArcId.value),
);

// Keep the selection valid as arcs come and go (e.g. after deleting the selected one).
watch(
    () => props.arcs,
    (arcs) => {
        if (
            selectedArcId.value !== UNASSIGNED &&
            !arcs.some((a) => a.id === selectedArcId.value)
        ) {
            selectedArcId.value = arcs[0]?.id ?? UNASSIGNED;
        }
    },
);

const unassignedSessions = computed(() =>
    props.sessions.filter((s) => s.arc_id == undefined),
);
const sessionsForSelected = computed(() =>
    selectedArcId.value === UNASSIGNED
        ? unassignedSessions.value
        : props.sessions.filter((s) => s.arc_id === selectedArcId.value),
);

/* ---- session selection: opens its key-moments panel on the right ---- */
const selectedSessionId = ref(undefined);
const selectedSession = computed(() =>
    props.sessions.find((s) => s.id === selectedSessionId.value),
);
const toggleSession = (session) => {
    selectedSessionId.value =
        selectedSessionId.value === session.id ? undefined : session.id;
};
// Drop the selection when that session leaves the arc currently on screen.
watch([sessionsForSelected, selectedSessionId], ([sessions, id]) => {
    if (id !== undefined && !sessions.some((s) => s.id === id)) {
        selectedSessionId.value = undefined;
    }
});

/* ---- arcs ---- */
const arcForm = useForm({ title: "" });
const addArc = () =>
    arcForm.post(route("arcs.store", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => arcForm.reset(),
    });

const editForm = useForm({ title: "", summary: "", status: "to_play" });
const syncEditForm = () => {
    const arc = selectedArc.value;
    if (arc) {
        editForm.title = arc.title;
        editForm.summary = arc.summary ?? "";
        editForm.status = arc.status;
    }
};
watch(selectedArcId, syncEditForm, { immediate: true });
watch(() => props.arcs, syncEditForm);

const saveArc = () => {
    if (typeof selectedArcId.value !== "number") return;
    editForm.put(route("arcs.update", selectedArcId.value), {
        preserveScroll: true,
    });
};
const deleteArc = () => {
    if (typeof selectedArcId.value !== "number") return;
    if (
        !confirm(
            `Delete arc “${selectedArc.value?.title}”? Its sessions stay, just unassigned.`,
        )
    ) {
        return;
    }
    router.delete(route("arcs.destroy", selectedArcId.value), {
        preserveScroll: true,
    });
};
const moveArc = (direction) => {
    const ids = props.arcs.map((a) => a.id);
    const from = arcIndex.value;
    const to = from + direction;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];
    router.put(
        route("arcs.reorder", props.campaign.id),
        { ids },
        { preserveScroll: true },
    );
};

/* ---- sessions ---- */
const sessionForm = useForm({ title: "" });
const addSession = () =>
    sessionForm
        .transform((data) => ({
            ...data,
            arc_id:
                typeof selectedArcId.value === "number"
                    ? selectedArcId.value
                    : undefined,
        }))
        .post(route("sessions.store", props.campaign.id), {
            preserveScroll: true,
            onSuccess: () => sessionForm.reset(),
        });
const cycleStatus = (session) =>
    router.patch(
        route("sessions.organise", session.id),
        { arc_id: session.arc_id ?? null, status: nextStatus(session.status) },
        { preserveScroll: true },
    );
const moveSession = (session, arcId) =>
    router.patch(
        route("sessions.organise", session.id),
        { arc_id: arcId === UNASSIGNED ? null : Number(arcId) },
        { preserveScroll: true },
    );
const removeSession = (session) => {
    if (confirm(`Delete session “${session.title}”?`)) {
        router.delete(route("sessions.destroy", session.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head :title="`${campaign.name} — ${world.name}`" />

    <WorldLayout :world="world">
        <div class="flex flex-col gap-1.5">
            <Link
                :href="route('campaigns.index', world.id)"
                class="font-mono text-[10px] uppercase tracking-[0.2em] text-amber hover:text-amber/80"
                >← Campaigns</Link
            >
            <div class="font-display text-[30px] leading-[1.05] text-bright">
                {{ campaign.name }}
            </div>
            <p v-if="campaign.description" class="max-w-2xl text-sm text-muted">
                {{ campaign.description }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <Link
                :href="route('players.index', campaign.id)"
                class="rounded-md border border-edge3 px-3 py-2 text-sm text-ink hover:border-amber"
                >Players &amp; invites</Link
            >
            <Link
                :href="route('characters.index', campaign.id)"
                class="rounded-md border border-edge3 px-3 py-2 text-sm text-ink hover:border-amber"
                >Characters</Link
            >
            <Link
                :href="route('rooms.index', campaign.id)"
                class="rounded-md border border-edge3 px-3 py-2 text-sm text-ink hover:border-amber"
                >Battle rooms</Link
            >
            <Link
                :href="route('campaigns.settings', [world.id, campaign.id])"
                class="ml-auto rounded-md border border-edge3 px-3 py-2 text-sm text-ink hover:border-amber"
                >Settings</Link
            >
        </div>

        <!-- Story board: arcs as the top lane, the selected arc's sessions below -->
        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="eyebrow-muted">Story</div>
                <form
                    class="flex items-center gap-2"
                    @submit.prevent="addArc"
                >
                    <input
                        v-model="arcForm.title"
                        class="field !py-1.5 text-sm"
                        placeholder="New arc title"
                    />
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="arcForm.processing || !arcForm.title"
                    >
                        Add arc
                    </button>
                </form>
            </div>

            <!-- Arc cards lane -->
            <div
                class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(190px,1fr))]"
            >
                <button
                    v-for="(arc, i) in arcs"
                    :key="arc.id"
                    type="button"
                    class="panel flex min-h-[150px] flex-col gap-2 p-4 text-left transition"
                    :class="
                        selectedArcId === arc.id
                            ? 'ring-2 ' + statusMeta(arc.status).ring
                            : 'hover:border-amber'
                    "
                    @click="selectedArcId = arc.id"
                >
                    <div class="flex items-start justify-between gap-2">
                        <span
                            class="font-display text-2xl leading-none text-bright"
                            >{{ String(i + 1).padStart(2, "0") }}</span
                        >
                        <span
                            class="rounded px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider"
                            :class="statusMeta(arc.status).pill"
                            >{{ statusMeta(arc.status).label }}</span
                        >
                    </div>
                    <div
                        class="font-display text-[15px] leading-tight text-bright line-clamp-2"
                    >
                        {{ arc.title }}
                    </div>
                    <p
                        v-if="arc.summary"
                        class="text-[12.5px] leading-snug text-muted line-clamp-3"
                    >
                        {{ arc.summary }}
                    </p>
                    <div class="mt-auto font-mono text-[10px] text-faint">
                        {{ arc.sessions_count }}
                        session{{ arc.sessions_count === 1 ? "" : "s" }} ›
                    </div>
                </button>

                <!-- Unassigned bucket, so free-floating sessions stay reachable -->
                <button
                    v-if="unassignedSessions.length"
                    type="button"
                    class="panel flex min-h-[150px] flex-col gap-2 border-dashed p-4 text-left transition"
                    :class="
                        selectedArcId === UNASSIGNED
                            ? 'ring-2 ring-edge3'
                            : 'hover:border-amber'
                    "
                    @click="selectedArcId = UNASSIGNED"
                >
                    <span
                        class="font-mono text-[9px] uppercase tracking-wider text-faint"
                        >No arc</span
                    >
                    <div class="font-display text-[15px] text-bright">
                        Unassigned
                    </div>
                    <p class="text-[12.5px] text-muted">
                        Sessions not yet placed in an arc.
                    </p>
                    <div class="mt-auto font-mono text-[10px] text-faint">
                        {{ unassignedSessions.length }}
                        session{{ unassignedSessions.length === 1 ? "" : "s" }} ›
                    </div>
                </button>

                <p
                    v-if="!arcs.length && !unassignedSessions.length"
                    class="col-span-full rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                >
                    No arcs yet. Add one above to start shaping your campaign's
                    spine.
                </p>
            </div>

            <!-- Selected arc: its sessions, and the arc's own controls -->
            <div
                v-if="arcs.length || unassignedSessions.length"
                class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]"
            >
                <div class="flex flex-col gap-2">
                    <div class="eyebrow-muted">
                        {{
                            selectedArcId === UNASSIGNED
                                ? "Unassigned sessions"
                                : `Sessions in ${selectedArc?.title ?? ""}`
                        }}
                    </div>
                    <div
                        v-for="s in sessionsForSelected"
                        :key="s.id"
                        class="panel overflow-hidden"
                        :class="
                            selectedSessionId === s.id ? 'ring-1 ring-amber' : ''
                        "
                    >
                        <div class="flex items-start gap-2 p-3">
                            <button
                                type="button"
                                class="shrink-0 rounded px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider"
                                :class="statusMeta(s.status).pill"
                                title="Change status"
                                @click="cycleStatus(s)"
                            >
                                {{ statusMeta(s.status).label }}
                            </button>
                            <button
                                type="button"
                                class="min-w-0 flex-1 text-left"
                                title="Open key moments"
                                @click="toggleSession(s)"
                            >
                                <div
                                    class="flex items-center gap-1.5 text-sm text-ink"
                                >
                                    <span>{{ s.title }}</span>
                                    <span
                                        v-if="s.is_private"
                                        class="rounded bg-amber/15 px-1 font-mono text-[9px] uppercase tracking-wider text-amber"
                                        >GM</span
                                    >
                                    <span
                                        v-if="s.beats?.length"
                                        class="font-mono text-[10px] text-faint"
                                        >· {{ s.beats.length }} moment{{
                                            s.beats.length === 1 ? "" : "s"
                                        }}</span
                                    >
                                </div>
                                <div
                                    v-if="s.summary"
                                    class="mt-0.5 line-clamp-2 text-[13px] text-muted"
                                >
                                    {{ s.summary }}
                                </div>
                                <div
                                    v-if="s.held_on"
                                    class="font-mono text-[10px] text-faint"
                                >
                                    {{ s.held_on }}
                                </div>
                            </button>
                            <div
                                class="flex shrink-0 flex-col items-end gap-1.5"
                            >
                                <select
                                    class="field !w-auto !py-1 text-[11px]"
                                    :value="s.arc_id ?? UNASSIGNED"
                                    @change="moveSession(s, $event.target.value)"
                                >
                                    <option :value="UNASSIGNED">
                                        Unassigned
                                    </option>
                                    <option
                                        v-for="a in arcs"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.title }}
                                    </option>
                                </select>
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="route('sessions.edit', [world.id, campaign.id, s.id])"
                                        class="text-xs text-muted hover:text-amber"
                                        >Edit ↗</Link
                                    >
                                    <Link
                                        :href="route('sessions.recap.show', [world.id, campaign.id, s.id])"
                                        class="text-xs text-muted hover:text-amber"
                                        >Recap ↗</Link
                                    >
                                    <button
                                        type="button"
                                        class="text-faint hover:text-red-400"
                                        title="Delete session"
                                        @click="removeSession(s)"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="!sessionsForSelected.length"
                        class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                    >
                        No sessions here yet.
                    </p>
                    <form
                        class="panel flex flex-wrap items-end gap-2 p-3"
                        @submit.prevent="addSession"
                    >
                        <input
                            v-model="sessionForm.title"
                            class="field flex-1 !py-2 text-sm"
                            :placeholder="
                                selectedArcId === UNASSIGNED
                                    ? 'New session title'
                                    : `Add a session to ${selectedArc?.title ?? ''}`
                            "
                        />
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="sessionForm.processing || !sessionForm.title"
                        >
                            Add
                        </button>
                    </form>
                </div>

                <!-- Right rail: the selected session's key moments, else arc controls -->
                <div class="flex flex-col gap-3">
                    <template v-if="selectedSession">
                        <div class="flex items-center justify-between">
                            <div class="eyebrow-muted">Session</div>
                            <button
                                type="button"
                                class="text-xs text-faint hover:text-ink"
                                @click="selectedSessionId = undefined"
                            >
                                ← Back to arc
                            </button>
                        </div>
                        <SessionBeats :session="selectedSession" />
                    </template>
                    <div
                        v-else-if="selectedArc"
                        class="panel flex flex-col gap-3 p-4"
                    >
                        <div class="flex items-center justify-between">
                            <div class="eyebrow-muted">Edit arc</div>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="rounded border border-edge3 px-2 py-0.5 text-xs text-muted hover:border-amber disabled:opacity-40"
                                    title="Move earlier"
                                    :disabled="arcIndex <= 0"
                                    @click="moveArc(-1)"
                                >
                                    ◀
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-edge3 px-2 py-0.5 text-xs text-muted hover:border-amber disabled:opacity-40"
                                    title="Move later"
                                    :disabled="arcIndex >= arcs.length - 1"
                                    @click="moveArc(1)"
                                >
                                    ▶
                                </button>
                            </div>
                        </div>
                        <input
                            v-model="editForm.title"
                            class="field !py-2 text-sm"
                            placeholder="Arc title"
                        />
                        <textarea
                            v-model="editForm.summary"
                            rows="3"
                            class="field !py-2 text-sm"
                            placeholder="A line on what this arc is about"
                        ></textarea>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="opt in STATUS_ORDER"
                                :key="opt"
                                type="button"
                                class="rounded-full border px-2.5 py-0.5 text-xs"
                                :class="
                                    editForm.status === opt
                                        ? statusMeta(opt).pill +
                                          ' border-transparent'
                                        : 'border-edge2 text-faint'
                                "
                                @click="editForm.status = opt"
                            >
                                {{ statusMeta(opt).label }}
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="btn-primary"
                                :disabled="editForm.processing || !editForm.title"
                                @click="saveArc"
                            >
                                Save arc
                            </button>
                            <span
                                v-if="editForm.recentlySuccessful"
                                class="text-xs text-teal"
                                >Saved.</span
                            >
                            <button
                                type="button"
                                class="ml-auto text-xs text-faint hover:text-red-400"
                                @click="deleteArc"
                            >
                                Delete arc
                            </button>
                        </div>
                    </div>
                    <div v-else class="panel p-4 text-sm text-muted">
                        These sessions aren't in an arc yet. Use the dropdown on
                        a session to place it, or add an arc above.
                    </div>
                </div>
            </div>
        </section>

        <!-- Players + rooms -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="flex flex-col gap-3">
                <div class="eyebrow-muted">Players</div>
                <div class="flex flex-col gap-2">
                    <div
                        v-for="p in players"
                        :key="p.id"
                        class="panel flex items-center justify-between px-3 py-2 text-sm"
                    >
                        <span class="text-ink">{{ p.name }}</span>
                        <span
                            class="font-mono text-[10px] uppercase tracking-wider text-faint"
                            >{{ p.role }}</span
                        >
                    </div>
                    <p
                        v-if="!players.length"
                        class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                    >
                        No players yet — invite them from Players &amp; invites.
                    </p>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div class="eyebrow-muted">Battle rooms</div>
                <div class="flex flex-col gap-2">
                    <Link
                        v-for="r in rooms"
                        :key="r.id"
                        :href="route('rooms.show', [campaign.id, r.id])"
                        class="panel flex items-center justify-between px-3 py-2 text-sm hover:border-amber"
                    >
                        <span class="text-ink">{{ r.name }}</span>
                        <span class="font-mono text-[10px] text-faint"
                            >{{ r.members_count }} players ·
                            {{ r.tokens_count }} tokens</span
                        >
                    </Link>
                    <p
                        v-if="!rooms.length"
                        class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                    >
                        No rooms yet — create one from Battle rooms.
                    </p>
                </div>
            </section>
        </div>
    </WorldLayout>
</template>
