<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import CampaignReaderTabs from "@/Components/CampaignReaderTabs.vue";
import { formatReaderDate } from "@/lib/readerDate";
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    campaign: Object,
    sections: Array,
    selectedCampaign: Object,
    sessions: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
});

// Most recent first; reveal the first 10, then 10 more each time "Read more" is pressed.
const shown = ref(10);
const visible = computed(() => props.sessions.slice(0, shown.value));
const hasMore = computed(() => props.sessions.length > shown.value);
const revealMore = () => (shown.value += 10);

const sessionUrl = (session) =>
    `/w/${props.campaign.slug}/campaigns/${props.selectedCampaign.slug}/sessions/${session.slug}`;
</script>

<template>
    <Head :title="`${selectedCampaign.name} — ${campaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="campaigns"
    >
        <div class="mx-auto w-full max-w-4xl px-6 py-12">
            <CampaignReaderTabs
                :campaign="campaign"
                :selected-campaign="selectedCampaign"
                active="sessions"
            />

            <p
                v-if="!sessions.length"
                class="mt-10 rounded-lg border border-dashed border-[#2e323c] p-10 text-center text-sm text-[#9aa0ab]"
            >
                No session recaps to show yet.
            </p>

            <!-- Timeline -->
            <div v-else class="relative mt-10">
                <!-- Spine -->
                <div
                    class="absolute bottom-0 top-0 w-0.5 bg-[#2b303a] max-sm:left-3 sm:left-1/2 sm:-translate-x-1/2"
                ></div>

                <div class="flex flex-col gap-8">
                    <div
                        v-for="(s, i) in visible"
                        :key="s.slug"
                        class="relative sm:grid sm:grid-cols-2 sm:gap-x-10"
                    >
                        <!-- Node -->
                        <span
                            class="absolute top-6 z-10 h-3.5 w-3.5 rounded-full border-2 border-night bg-[#6fbfc4] max-sm:left-3 max-sm:-translate-x-1/2 sm:left-1/2 sm:-translate-x-1/2"
                        ></span>

                        <!-- Tile: alternate sides on desktop; stacked (right of the spine) on mobile -->
                        <Link
                            :href="sessionUrl(s)"
                            class="group block rounded-lg border border-[#262a33] bg-[#181b21] p-5 transition hover:border-[#6fbfc4] max-sm:ml-9"
                            :class="
                                i % 2 === 0
                                    ? 'sm:col-start-1 sm:mr-5 sm:text-right'
                                    : 'sm:col-start-2 sm:ml-5'
                            "
                        >
                            <div
                                class="font-mono text-[11px] uppercase tracking-[0.14em] text-[#6b7180]"
                            >
                                <span
                                    v-if="campaign.numberSessions"
                                    class="text-teal"
                                    >Session {{ sessions.length - i }}</span
                                >
                                <span
                                    v-if="campaign.numberSessions && s.held_on"
                                    >·</span
                                >
                                <span v-if="s.held_on">{{
                                    formatReaderDate(
                                        s.held_on,
                                        campaign.dateFormat,
                                    )
                                }}</span>
                            </div>
                            <h3
                                class="mt-1 font-display text-xl text-[#f3efe6] group-hover:text-teal"
                            >
                                {{ s.title }}
                            </h3>
                            <p
                                v-if="s.summary"
                                class="mt-2 line-clamp-4 text-sm leading-relaxed text-[#9aa0ab]"
                            >
                                {{ s.summary }}
                            </p>
                            <span
                                class="mt-3 inline-block font-mono text-[11px] uppercase tracking-[0.12em] text-[#6fbfc4]"
                                >Read the recap →</span
                            >
                        </Link>
                    </div>
                </div>

                <!-- Read more -->
                <div v-if="hasMore" class="mt-10 flex justify-center">
                    <button
                        class="flex h-24 w-24 flex-col items-center justify-center rounded-full border border-[#2f5457] bg-[#15252a] text-center font-mono text-[11px] uppercase tracking-[0.12em] text-teal transition hover:bg-[#1b3035]"
                        @click="revealMore"
                    >
                        Read<br />more
                    </button>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
