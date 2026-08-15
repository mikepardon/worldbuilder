<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    world: Object,
    campaigns: { type: Array, default: () => [] },
    filter: { type: Number, default: null },
    sessions: { type: Array, default: () => [] },
    members: { type: Object, default: () => ({}) },
});

const rows = ref(props.sessions.map((s) => ({ ...s })));
const editingId = ref(null);
const draftDate = ref("");
const draftAttendees = ref([]);
const saving = ref(false);

const STATUS = {
    done: { label: "Recap ready", class: "bg-emerald-500/15 text-emerald-300" },
    analyzing: { label: "Analysing", class: "bg-amber/15 text-amber" },
    transcribing: { label: "Transcribing", class: "bg-amber/15 text-amber" },
    queued: { label: "Queued", class: "bg-raised text-muted" },
    failed: { label: "Failed", class: "bg-red-500/15 text-red-300" },
};

function startEdit(session) {
    editingId.value = session.id;
    draftDate.value = session.held_on ?? "";
    draftAttendees.value = [...session.attendee_ids];
}

function toggleAttendee(id) {
    const i = draftAttendees.value.indexOf(id);
    if (i === -1) draftAttendees.value.push(id);
    else draftAttendees.value.splice(i, 1);
}

async function saveDetails(session) {
    saving.value = true;
    try {
        const res = await window.axios.put(
            route("sessions.details", session.id),
            {
                held_on: draftDate.value || null,
                attendee_ids: draftAttendees.value,
            },
        );
        const memberList = props.members[session.campaign_id] ?? [];
        session.held_on = res.data.held_on;
        session.attendee_ids = res.data.attendee_ids;
        session.attendees = memberList.filter((m) =>
            res.data.attendee_ids.includes(m.id),
        );
        editingId.value = null;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Sessions" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-5xl">
            <h1 class="mb-4 font-display text-[28px] text-bright">Sessions</h1>

            <!-- Campaign filter -->
            <div v-if="campaigns.length > 1" class="mb-4 flex flex-wrap gap-1">
                <Link
                    :href="route('worlds.sessions', world.id)"
                    class="rounded px-2.5 py-1 text-xs transition"
                    :class="
                        filter === null
                            ? 'bg-amber/10 text-amber'
                            : 'text-muted hover:text-ink'
                    "
                >
                    All campaigns
                </Link>
                <Link
                    v-for="c in campaigns"
                    :key="c.id"
                    :href="
                        route('worlds.sessions', {
                            world: world.id,
                            campaign: c.id,
                        })
                    "
                    class="rounded px-2.5 py-1 text-xs transition"
                    :class="
                        filter === c.id
                            ? 'bg-amber/10 text-amber'
                            : 'text-muted hover:text-ink'
                    "
                >
                    {{ c.name }}
                </Link>
            </div>

            <p v-if="!rows.length" class="panel p-6 text-sm text-faint">
                No sessions yet.
            </p>

            <div class="space-y-3">
                <div v-for="s in rows" :key="s.id" class="panel p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            :href="s.view_url"
                            class="font-display text-lg text-bright hover:text-amber"
                            >{{ s.title }}</Link
                        >
                        <span
                            v-if="campaigns.length > 1"
                            class="rounded bg-raised px-1.5 py-0.5 text-[10px] text-faint"
                            >{{ s.campaign_name }}</span
                        >
                        <span
                            v-if="s.recap_status"
                            class="rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide"
                            :class="
                                STATUS[s.recap_status]?.class ??
                                'bg-raised text-muted'
                            "
                            >{{
                                STATUS[s.recap_status]?.label ?? s.recap_status
                            }}</span
                        >
                        <span class="ml-auto text-xs text-faint">{{
                            s.held_on ?? "no date"
                        }}</span>
                    </div>

                    <p
                        v-if="s.attendees.length"
                        class="mt-1 text-xs text-muted"
                    >
                        Attended:
                        {{ s.attendees.map((a) => a.name).join(", ") }}
                    </p>

                    <div
                        class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px]"
                    >
                        <Link
                            :href="s.view_url"
                            class="text-amber hover:underline"
                            >Session page</Link
                        >
                        <Link
                            :href="s.recap_url"
                            class="text-faint hover:text-ink"
                            >Recap</Link
                        >
                        <Link
                            :href="s.edit_url"
                            class="text-faint hover:text-ink"
                            >Prep editor</Link
                        >
                        <button
                            class="text-faint hover:text-ink"
                            @click="startEdit(s)"
                        >
                            Edit date &amp; attendees
                        </button>
                    </div>

                    <!-- Inline edit: date + attendees -->
                    <div
                        v-if="editingId === s.id"
                        class="mt-3 space-y-3 rounded border border-edge2 bg-night/40 p-3"
                    >
                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Date played</span
                            >
                            <input
                                v-model="draftDate"
                                type="date"
                                class="rounded border border-edge2 bg-night/60 p-1.5 text-sm text-ink"
                            />
                        </label>
                        <div>
                            <span class="mb-1 block text-xs text-faint"
                                >Players who attended</span
                            >
                            <div
                                v-if="(members[s.campaign_id] ?? []).length"
                                class="flex flex-wrap gap-3"
                            >
                                <label
                                    v-for="m in members[s.campaign_id]"
                                    :key="m.id"
                                    class="flex items-center gap-1.5 text-sm text-ink"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="draftAttendees.includes(m.id)"
                                        @change="toggleAttendee(m.id)"
                                    />
                                    {{ m.name }}
                                </label>
                            </div>
                            <p v-else class="text-xs text-faint">
                                No players have joined this campaign yet.
                            </p>
                        </div>
                        <div class="flex gap-2 text-xs">
                            <button
                                class="rounded bg-amber/90 px-2.5 py-1 font-medium text-night hover:bg-amber disabled:opacity-60"
                                :disabled="saving"
                                @click="saveDetails(s)"
                            >
                                {{ saving ? "Saving…" : "Save" }}
                            </button>
                            <button
                                class="text-faint hover:text-ink"
                                @click="editingId = null"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
