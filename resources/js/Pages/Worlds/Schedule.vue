<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    campaigns: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
});

const form = useForm({
    campaign_id: props.campaigns[0]?.id ?? null,
    title: "",
    starts_at: "",
    notes: "",
});
const add = () =>
    form.post(route("schedule.store", props.world.id), {
        preserveScroll: true,
        onSuccess: () => form.reset("title", "starts_at", "notes"),
    });
const remove = (event) => {
    if (confirm(`Delete “${event.title}”?`)) {
        router.delete(route("schedule.destroy", event.id), {
            preserveScroll: true,
        });
    }
};

const nowMs = Date.now();
const upcoming = computed(() =>
    props.events.filter((e) => Date.parse(e.starts_at) >= nowMs),
);
const past = computed(() =>
    props.events.filter((e) => Date.parse(e.starts_at) < nowMs).reverse(),
);

const fmt = (iso) =>
    new Date(iso).toLocaleString(undefined, {
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
    });
</script>

<template>
    <Head title="Schedule" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <div>
                <h1 class="font-display text-[28px] text-bright">Schedule</h1>
                <p class="mt-1 text-sm text-muted">
                    Plan real-world dates across this world's campaigns. Players
                    see only the upcoming ones.
                </p>
            </div>

            <!-- Add -->
            <form class="panel space-y-3 p-4" @submit.prevent="add">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Campaign</span
                        >
                        <select
                            v-model="form.campaign_id"
                            class="field text-sm"
                        >
                            <option
                                v-for="c in campaigns"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.name }}
                            </option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Date &amp; time</span
                        >
                        <input
                            v-model="form.starts_at"
                            type="datetime-local"
                            class="field text-sm"
                        />
                    </label>
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs text-faint">What's on</span>
                    <input
                        v-model="form.title"
                        class="field text-sm"
                        placeholder="Session 4 · The graveyard of the dead saint"
                    />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs text-faint"
                        >Notes (optional)</span
                    >
                    <textarea
                        v-model="form.notes"
                        rows="2"
                        class="field text-sm"
                        placeholder="7pm at Dave's, or the Discord voice channel"
                    />
                </label>
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="
                        form.processing ||
                        !form.campaign_id ||
                        !form.title ||
                        !form.starts_at
                    "
                >
                    Add to schedule
                </button>
            </form>

            <!-- Upcoming -->
            <section>
                <div class="eyebrow-muted mb-2">Upcoming</div>
                <div v-if="upcoming.length" class="flex flex-col gap-2">
                    <div
                        v-for="e in upcoming"
                        :key="e.id"
                        class="panel flex items-start gap-3 p-3"
                    >
                        <div
                            class="shrink-0 rounded-md bg-amber/10 px-2.5 py-1 text-center font-mono text-[11px] text-amber"
                        >
                            {{ fmt(e.starts_at) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm text-ink">{{ e.title }}</div>
                            <div class="font-mono text-[10px] text-faint">
                                {{ e.campaign }}
                            </div>
                            <p
                                v-if="e.notes"
                                class="mt-0.5 text-[13px] text-muted"
                            >
                                {{ e.notes }}
                            </p>
                        </div>
                        <button
                            class="shrink-0 text-faint hover:text-red-400"
                            title="Delete"
                            @click="remove(e)"
                        >
                            ✕
                        </button>
                    </div>
                </div>
                <p
                    v-else
                    class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                >
                    Nothing scheduled yet.
                </p>
            </section>

            <!-- Past -->
            <section v-if="past.length">
                <div class="eyebrow-muted mb-2">Past</div>
                <div class="flex flex-col gap-2 opacity-70">
                    <div
                        v-for="e in past"
                        :key="e.id"
                        class="panel flex items-center gap-3 p-3"
                    >
                        <div class="shrink-0 font-mono text-[11px] text-faint">
                            {{ fmt(e.starts_at) }}
                        </div>
                        <div class="min-w-0 flex-1 text-sm text-muted">
                            {{ e.title }}
                            <span class="font-mono text-[10px] text-faint"
                                >· {{ e.campaign }}</span
                            >
                        </div>
                        <button
                            class="shrink-0 text-faint hover:text-red-400"
                            title="Delete"
                            @click="remove(e)"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </WorldLayout>
</template>
