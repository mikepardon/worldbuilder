<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import CampaignReaderTabs from "@/Components/CampaignReaderTabs.vue";
import CharacterSheet from "@/Components/CharacterSheet.vue";
import DicePopover from "@/Components/DicePopover.vue";
import { useDiceRoller } from "@/composables/useDiceRoller";
import { Head, Link } from "@inertiajs/vue3";

const props = defineProps({
    campaign: Object,
    sections: Array,
    selectedCampaign: Object,
    character: Object,
    viewer: { type: Object, default: () => ({}) },
});

const charactersUrl = `/w/${props.campaign.slug}/campaigns/${props.selectedCampaign.slug}/characters`;

// Local dice rolls from the sheet's clickable rolls — a popover only, nothing persisted.
const { roll, handleClick: rollDiceClick } = useDiceRoller();
</script>

<template>
    <Head :title="`${character.name} — ${selectedCampaign.name}`" />

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

            <Link
                :href="charactersUrl"
                class="font-mono text-[10px] uppercase tracking-[0.2em] text-[#6fbfc4] hover:text-teal"
                >← Characters</Link
            >

            <div class="mt-4" @click="rollDiceClick">
                <CharacterSheet
                    :name="character.name"
                    :image="character.image_url || ''"
                    :ac="character.ac"
                    :hp="character.hp"
                    :max-hp="character.max_hp"
                    :character="{
                        level: character.level,
                        class: character.class,
                        race: character.race,
                        stats: character.stats,
                        sheet: character.sheet,
                    }"
                    :can-edit="false"
                />
                <DicePopover :roll="roll" />
            </div>
        </div>
    </PublicLayout>
</template>
