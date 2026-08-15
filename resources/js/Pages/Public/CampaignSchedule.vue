<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import CampaignReaderTabs from "@/Components/CampaignReaderTabs.vue";
import { Head, router } from "@inertiajs/vue3";
import { computed, reactive } from "vue";

const props = defineProps({
    campaign: Object,
    sections: Array,
    selectedCampaign: Object,
    events: { type: Array, default: () => [] },
    canRespond: { type: Boolean, default: false },
    viewer: { type: Object, default: () => ({}) },
});

const STATUS_META = {
    attending: {
        label: "Can attend",
        active: "border-emerald-500/70 bg-emerald-500/10 text-emerald-300",
        dot: "bg-emerald-400",
    },
    tentative: {
        label: "Tentative",
        active: "border-amber-500/70 bg-amber-500/10 text-amber-300",
        dot: "bg-amber-400",
    },
    declined: {
        label: "Won't attend",
        active: "border-red-500/70 bg-red-500/10 text-red-300",
        dot: "bg-red-400",
    },
};
const STATUS_KEYS = ["attending", "tentative", "declined"];

const nowMs = Date.now();
const upcoming = computed(() =>
    props.events.filter((e) => Date.parse(e.starts_at) >= nowMs),
);
const past = computed(() =>
    props.events.filter((e) => Date.parse(e.starts_at) < nowMs).reverse(),
);

// Local note drafts, seeded from the server so a member's saved note is editable in place.
const notes = reactive({});
props.events.forEach((e) => {
    notes[e.id] = e.my_note ?? "";
});

const respond = (event, status) => {
    router.post(
        route("schedule.respond", event.id),
        { status, note: notes[event.id] },
        { preserveScroll: true, preserveState: true },
    );
};
const saveNote = (event) => {
    // A note only means anything once a status is chosen (the backend requires one).
    if (!event.my_status) {
        return;
    }
    respond(event, event.my_status);
};

const fmt = (iso) =>
    new Date(iso).toLocaleString(undefined, {
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
    });

const rollCall = (event) =>
    STATUS_KEYS.map((key) => ({
        key,
        label: STATUS_META[key].label,
        dot: STATUS_META[key].dot,
        people: event.responses.filter((r) => r.status === key),
    })).filter((group) => group.people.length > 0);
</script>

<template>
    <Head :title="`Schedule — ${selectedCampaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="campaigns"
    >
        <div class="mx-auto w-full max-w-4xl px-6 py-10">
            <CampaignReaderTabs
                :campaign="campaign"
                :selected-campaign="selectedCampaign"
                active="schedule"
            />

            <!-- Upcoming -->
            <div class="mb-2 flex items-center justify-between gap-3">
                <div
                    class="font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
                >
                    Upcoming
                </div>
                <a
                    :href="`/w/${campaign.slug}/campaigns/${selectedCampaign.slug}/schedule.ics`"
                    class="font-mono text-[10px] uppercase tracking-[0.12em] text-[#9aa0ab] hover:text-teal"
                    title="Subscribe in your calendar app"
                >
                    Subscribe (iCal)
                </a>
            </div>
            <div v-if="upcoming.length" class="flex flex-col gap-3">
                <div
                    v-for="e in upcoming"
                    :key="e.id"
                    class="rounded-lg border border-[#262a33] bg-[#181b21] p-4"
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <div
                            class="shrink-0 rounded-md border border-teal/40 px-2.5 py-1 text-center font-mono text-[11px] text-teal"
                        >
                            {{ fmt(e.starts_at) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[15px] text-[#f3efe6]">
                                {{ e.title }}
                            </div>
                            <p
                                v-if="e.notes"
                                class="mt-0.5 text-[13px] text-[#9aa0ab]"
                            >
                                {{ e.notes }}
                            </p>
                        </div>
                    </div>

                    <!-- RSVP controls (members + GM only) -->
                    <div v-if="canRespond" class="mt-4">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="key in STATUS_KEYS"
                                :key="key"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm transition"
                                :class="
                                    e.my_status === key
                                        ? STATUS_META[key].active
                                        : 'border-[#2b303a] text-[#9aa0ab] hover:border-[#3a4150]'
                                "
                                @click="respond(e, key)"
                            >
                                {{ STATUS_META[key].label }}
                            </button>
                        </div>
                        <input
                            v-model="notes[e.id]"
                            class="mt-2 w-full rounded-md border border-[#2b303a] bg-[#12151a] px-3 py-2 text-sm text-[#e6e2d8] placeholder:text-[#5b616c] focus:border-teal focus:outline-none"
                            placeholder="Add a note (e.g. can only make the first hour)"
                            @blur="saveNote(e)"
                            @keydown.enter="saveNote(e)"
                        />

                        <!-- Who's coming -->
                        <div
                            v-if="e.responses.length"
                            class="mt-3 flex flex-col gap-1.5 border-t border-[#23272f] pt-3"
                        >
                            <div
                                v-for="group in rollCall(e)"
                                :key="group.key"
                                class="flex flex-wrap items-baseline gap-x-2 text-[13px]"
                            >
                                <span class="flex items-center gap-1.5">
                                    <span
                                        class="inline-block h-2 w-2 rounded-full"
                                        :class="group.dot"
                                    ></span>
                                    <span class="text-[#8b909b]">{{
                                        group.label
                                    }}</span>
                                </span>
                                <span class="text-[#c8ccd3]">{{
                                    group.people.map((p) => p.name).join(", ")
                                }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <p
                v-else
                class="rounded-lg border border-dashed border-[#2b303a] p-8 text-center text-sm text-[#9aa0ab]"
            >
                No sessions on the calendar yet.
            </p>

            <!-- Past -->
            <template v-if="past.length">
                <div
                    class="mb-2 mt-8 font-mono text-[10px] uppercase tracking-[0.16em] text-[#6b7180]"
                >
                    Past
                </div>
                <div class="flex flex-col gap-2 opacity-75">
                    <div
                        v-for="e in past"
                        :key="e.id"
                        class="flex items-center gap-3 rounded-lg border border-[#23272f] bg-[#15181d] p-3"
                    >
                        <div
                            class="shrink-0 font-mono text-[11px] text-[#6b7180]"
                        >
                            {{ fmt(e.starts_at) }}
                        </div>
                        <div class="min-w-0 flex-1 text-sm text-[#9aa0ab]">
                            {{ e.title }}
                        </div>
                        <span
                            v-if="e.my_status"
                            class="shrink-0 font-mono text-[10px] uppercase tracking-wider"
                            :class="{
                                'text-emerald-400/80':
                                    e.my_status === 'attending',
                                'text-amber-400/80':
                                    e.my_status === 'tentative',
                                'text-red-400/70': e.my_status === 'declined',
                            }"
                            >{{ STATUS_META[e.my_status].label }}</span
                        >
                    </div>
                </div>
            </template>
        </div>
    </PublicLayout>
</template>
