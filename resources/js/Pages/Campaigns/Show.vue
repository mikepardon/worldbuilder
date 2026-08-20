<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";

const props = defineProps({
    world: Object,
    campaign: Object,
    players: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
});

const sessionForm = useForm({ title: "", summary: "" });
const addSession = () =>
    sessionForm.post(route("sessions.store", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => sessionForm.reset(),
    });
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

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Sessions -->
            <section class="flex flex-col gap-3">
                <div class="eyebrow-muted">Sessions</div>
                <form
                    class="panel flex flex-wrap items-end gap-2 p-3"
                    @submit.prevent="addSession"
                >
                    <input
                        v-model="sessionForm.title"
                        class="field flex-1 !py-2 text-sm"
                        placeholder="Session title"
                    />
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="sessionForm.processing || !sessionForm.title"
                    >
                        Add
                    </button>
                </form>
                <div class="flex flex-col gap-2">
                    <div
                        v-for="s in sessions"
                        :key="s.id"
                        class="panel overflow-hidden"
                    >
                        <div class="flex items-start justify-between gap-2 p-3">
                            <Link
                                :href="route('sessions.edit', [world.id, campaign.id, s.id])"
                                class="min-w-0 flex-1 text-left"
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
                            </Link>
                            <div class="flex shrink-0 items-center gap-2">
                                <Link
                                    :href="route('sessions.edit', [world.id, campaign.id, s.id])"
                                    class="text-xs text-muted hover:text-amber"
                                    >Open ↗</Link
                                >
                                <button
                                    class="text-faint hover:text-red-400"
                                    title="Delete session"
                                    @click="removeSession(s)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="!sessions.length"
                        class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                    >
                        No sessions logged yet.
                    </p>
                </div>
            </section>

            <!-- Players + rooms -->
            <section class="flex flex-col gap-6">
                <div class="flex flex-col gap-3">
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
                            No players yet — invite them from Players &amp;
                            invites.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
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
                </div>
            </section>
        </div>
    </WorldLayout>
</template>
