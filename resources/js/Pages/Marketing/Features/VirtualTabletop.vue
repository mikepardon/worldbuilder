<script setup>
import FeatureDetail from "@/Components/FeatureDetail.vue";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const sections = [
    {
        eyebrow: "Battle maps",
        title: "Maps, tokens and fog that share your canon",
        body: "Drop a map, place tokens and pull the fog back as the party explores. Because the room lives inside your world, the monster you drop onto the grid is the same stat block you wrote in your compendium — no re-entering anything.",
        points: [
            "Upload battle maps and place tokens for anyone at the table",
            "Fog of war you reveal as the party moves",
            "Monster and character tokens drawn straight from your world",
            "Initiative and combat tracking built in",
        ],
    },
    {
        eyebrow: "Live together",
        title: "A real-time room for the whole table",
        body: "Everyone shares one board in real time. Move a token and it moves for everyone; reveal a scene and the table sees it at once. Handouts, drawings and a shared journal keep the group on the same page — literally.",
        points: [
            "Real-time board shared across the table",
            "Scenes you can prepare and switch between mid-session",
            "Handouts, drawings and a shared journal",
            "In-room chat with private GM whispers",
        ],
    },
    {
        eyebrow: "At the table",
        title: "Characters that come from the sheet",
        body: "Import player characters straight from D&D Beyond so their tokens carry real stats, and keep them in sync as they level. Your players show up as themselves, not as blank markers you have to reconcile by hand.",
        points: [
            "Import player characters from D&D Beyond",
            "Tokens carry the character's real sheet",
            "Conditions and hit points tracked on the token",
            "Refresh a sheet when it changes at the source",
        ],
    },
];

const highlight = {
    title: "One tool, from prep to play",
    body: "The tabletop isn't a separate app bolted on — it's the same world you built, brought to the grid. Nothing is duplicated, nothing drifts out of sync, and everything you reveal is a decision you made on purpose.",
};
</script>

<template>
    <FeatureDetail
        :can-login="canLogin"
        :can-register="canRegister"
        current="marketing.features.vtt"
        doc-title="Virtual tabletop"
        eyebrow="Virtual tabletop"
        title="Run the session inside your world"
        intro="Battle maps, tokens, fog and initiative in a real-time room that shares the same canon, compendium and characters you've already built."
        :sections="sections"
        :highlight="highlight"
    />
</template>
