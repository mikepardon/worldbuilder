<script setup>
import FeatureDetail from "@/Components/FeatureDetail.vue";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const sections = [
    {
        eyebrow: "Player-facing",
        title: "A beautiful site your players actually read",
        body: "Publish your world as a clean, searchable reader made for players, not a wall of GM notes. It's fast, it's browsable, and it looks like it belongs to your setting — a place your table will want to visit between sessions.",
        points: [
            "A polished, searchable reader for your players",
            "Custom layouts and per-world quick facts",
            "Table of contents, related entries and slug aliases",
            "Comments so your table can talk back",
        ],
    },
    {
        eyebrow: "Your secrets, your call",
        title: "GM-only until the moment they land",
        body: "You decide what players see, entry by entry and secret by secret. The twist you've been building toward stays hidden until you publish it — no accidental spoilers, no second private wiki to maintain in parallel.",
        points: [
            "Per-entry visibility: GM-only until you reveal it",
            "Hide secrets inline within an otherwise-public entry",
            "Schedule a reveal to publish exactly when you want",
            "One world, one source — no duplicate player wiki",
        ],
    },
    {
        eyebrow: "Your address",
        title: "Publish on your own domain",
        body: "On a paid plan, your world can live at a custom domain that feels like part of the setting rather than a page on someone else's site. It's your world; it should have an address to match.",
        points: [
            "Bring a custom domain on paid plans",
            "An address that feels like your world's own",
            "Share one link your whole table can bookmark",
            "A reader that works on phones at the table",
        ],
    },
];

const highlight = {
    title: "Publishing is a decision, not a chore",
    body: "Because the player site is generated from the same world you build in, sharing it is a switch you flip — not an export you babysit. Write once; reveal when the story's ready.",
};
</script>

<template>
    <FeatureDetail
        :can-login="canLogin"
        :can-register="canRegister"
        current="marketing.features.publishing"
        doc-title="Publishing"
        eyebrow="Publishing"
        title="Share it with your table"
        intro="Publish a beautiful, player-facing site of exactly what your players should see — with GM-only secrets that stay hidden until you choose to reveal them."
        :sections="sections"
        :highlight="highlight"
    />
</template>
