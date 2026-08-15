<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import CampaignReaderTabs from "@/Components/CampaignReaderTabs.vue";
import { Head, Link } from "@inertiajs/vue3";

const props = defineProps({
    campaign: Object,
    sections: Array,
    selectedCampaign: Object,
    characters: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
});

const initial = (name) => (name || "?").trim().charAt(0).toUpperCase() || "?";
const charUrl = (c) =>
    `/w/${props.campaign.slug}/campaigns/${props.selectedCampaign.slug}/characters/${c.slug}`;
</script>

<template>
    <Head :title="`Characters — ${selectedCampaign.name}`" />

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
                active="characters"
            />

            <div
                v-if="characters.length"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="c in characters"
                    :key="c.slug"
                    :href="charUrl(c)"
                    class="flex items-center gap-3 rounded-lg border border-[#262a33] bg-[#181b21] p-4 transition hover:border-[#6fbfc4]"
                >
                    <span
                        class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-cover bg-center font-display text-lg text-[#6fbfc4] ring-1 ring-[#262a33]"
                        :style="{
                            backgroundImage: c.image_url
                                ? `url(${c.image_url})`
                                : 'none',
                        }"
                    >
                        <span v-if="!c.image_url">{{ initial(c.name) }}</span>
                    </span>
                    <div class="min-w-0">
                        <div
                            class="truncate font-serif text-[15px] text-[#f3efe6]"
                        >
                            {{ c.name }}
                        </div>
                        <div
                            class="truncate font-mono text-[11px] text-[#6b7180]"
                        >
                            <span v-if="c.level">Lv {{ c.level }}</span>
                            <span v-if="c.blurb">· {{ c.blurb }}</span>
                        </div>
                    </div>
                </Link>
            </div>
            <p
                v-else
                class="rounded-lg border border-dashed border-[#2e323c] p-10 text-center text-sm text-[#9aa0ab]"
            >
                No characters in this campaign yet.
            </p>
        </div>
    </PublicLayout>
</template>
