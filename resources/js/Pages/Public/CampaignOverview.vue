<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import CampaignReaderTabs from "@/Components/CampaignReaderTabs.vue";
import { formatReaderDate } from "@/lib/readerDate";
import { Head, Link } from "@inertiajs/vue3";

const props = defineProps({
    campaign: Object,
    sections: Array,
    selectedCampaign: Object,
    stats: Object,
    lastSession: { type: Object, default: null },
    upcoming: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
});

const fmt = (iso) =>
    new Date(iso).toLocaleString(undefined, {
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
    });

const metrics = [
    { key: "sessions", label: "Sessions" },
    { key: "characters", label: "Characters" },
];
const sessionsUrl = `/w/${props.campaign.slug}/campaigns/${props.selectedCampaign.slug}/sessions`;
const charactersUrl = `/w/${props.campaign.slug}/campaigns/${props.selectedCampaign.slug}/characters`;
</script>

<template>
    <Head :title="`${selectedCampaign.name} — ${campaign.name}`" />

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
                active="overview"
            />

            <p
                v-if="selectedCampaign.description"
                class="max-w-2xl text-[15px] leading-relaxed text-[#c8ccd3]"
            >
                {{ selectedCampaign.description }}
            </p>

            <!-- Upcoming dates (players see these; not the full calendar) -->
            <div v-if="upcoming.length" class="mt-6">
                <div
                    class="mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
                >
                    Upcoming
                </div>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="(e, i) in upcoming"
                        :key="i"
                        class="flex items-start gap-3 rounded-lg border border-[#262a33] bg-[#181b21] p-3"
                    >
                        <div
                            class="shrink-0 rounded-md border border-teal/40 px-2.5 py-1 text-center font-mono text-[11px] text-teal"
                        >
                            {{ fmt(e.starts_at) }}
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm text-[#f3efe6]">
                                {{ e.title }}
                            </div>
                            <p
                                v-if="e.notes"
                                class="mt-0.5 text-[13px] text-[#9aa0ab]"
                            >
                                {{ e.notes }}
                            </p>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Metrics -->
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <Link
                    v-for="m in metrics"
                    :key="m.key"
                    :href="m.key === 'sessions' ? sessionsUrl : charactersUrl"
                    class="rounded-lg border border-[#262a33] bg-[#181b21] p-4 transition hover:border-[#6fbfc4]"
                >
                    <div
                        class="font-display text-[30px] leading-none text-[#f3efe6]"
                    >
                        {{ stats[m.key] }}
                    </div>
                    <div
                        class="mt-1.5 font-mono text-[10px] uppercase tracking-[0.14em] text-[#6b7180]"
                    >
                        {{ m.label }}
                    </div>
                </Link>
                <div
                    v-if="stats.game_system"
                    class="rounded-lg border border-[#262a33] bg-[#181b21] p-4"
                >
                    <div class="font-display text-lg text-[#f3efe6]">
                        {{ stats.game_system }}
                    </div>
                    <div
                        class="mt-1.5 font-mono text-[10px] uppercase tracking-[0.14em] text-[#6b7180]"
                    >
                        Game system
                    </div>
                </div>
            </div>

            <!-- Latest session -->
            <div v-if="lastSession" class="mt-8">
                <div
                    class="mb-2 font-mono text-[10px] uppercase tracking-[0.16em] text-[#6fbfc4]"
                >
                    Latest session
                </div>
                <Link
                    :href="lastSession.url"
                    class="block rounded-lg border border-[#262a33] bg-[#181b21] p-5 transition hover:border-[#6fbfc4]"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="font-display text-xl text-[#f3efe6]">
                            {{ lastSession.title }}
                        </div>
                        <span
                            v-if="lastSession.held_on"
                            class="shrink-0 font-mono text-[11px] text-[#6b7180]"
                            >{{
                                formatReaderDate(
                                    lastSession.held_on,
                                    campaign.dateFormat,
                                )
                            }}</span
                        >
                    </div>
                    <p
                        v-if="lastSession.summary"
                        class="mt-2 line-clamp-3 text-sm text-[#9aa0ab]"
                    >
                        {{ lastSession.summary }}
                    </p>
                </Link>
            </div>
        </div>
    </PublicLayout>
</template>
