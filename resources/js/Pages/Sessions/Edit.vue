<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import BrewEditor from "@/Components/BrewEditor.vue";
import { Head, Link } from "@inertiajs/vue3";

const props = defineProps({
    world: { type: Object, required: true },
    campaign: Object,
    document: Object,
    entries: { type: Array, default: () => [] },
    compendium: { type: Array, default: () => [] },
    characters: { type: Array, default: () => [] },
    embeds: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    allTags: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="document.title" />

    <WorldLayout :world="world" flush>
        <BrewEditor
            :campaign="campaign"
            :document="document"
            :entries="entries"
            :compendium="compendium"
            :characters="characters"
            :embeds="embeds"
            :spells="spells"
            :all-tags="allTags"
            update-route="sessions.update"
            ai-route="sessions.ai"
            back-route="campaigns.show"
            :back-params="[campaign.world_id, campaign.id]"
            :write-up-tools="false"
        />
        <Link
            :href="route('sessions.recap.show', [world.id, campaign.id, props.document.id])"
            class="fixed bottom-4 right-4 z-40 flex items-center gap-1.5 rounded border border-edge3 bg-surface px-3 py-1.5 text-xs text-muted shadow-lg hover:text-ink"
            title="The post-play recap and analysis for this session"
        >
            <svg
                viewBox="0 0 24 24"
                class="h-4 w-4"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path
                    d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"
                />
                <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                <line x1="12" y1="19" x2="12" y2="23" />
                <line x1="8" y1="23" x2="16" y2="23" />
            </svg>
            <span>Recap</span>
        </Link>
    </WorldLayout>
</template>
