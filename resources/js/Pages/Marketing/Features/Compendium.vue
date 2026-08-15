<script setup>
import FeatureDetail from "@/Components/FeatureDetail.vue";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const sections = [
    {
        eyebrow: "The rules to hand",
        title: "Monsters, spells and items, searchable",
        body: "A built-in compendium of monsters, spells, items and stat blocks means the reference you need is a search away, not buried in a PDF. Filter by what you're running and pull up the exact block mid-encounter.",
        points: [
            "Searchable, filterable monsters, spells and items",
            "Full stat blocks ready to read at the table",
            "Import from open SRD sources so your reference stays current",
            "Your own homebrew alongside the standard content",
        ],
    },
    {
        eyebrow: "Woven in",
        title: "Embed a stat block anywhere it belongs",
        body: "Drop a monster into a location's entry, an item into a quest, a spell into an NPC's tactics. The block travels with the entry, so when you open that place mid-session everything you need is already there.",
        points: [
            "Embed stat blocks inside any entry",
            "Keep encounter details where the story keeps them",
            "One source of truth for every creature and item",
            "Edit once; every embed updates",
        ],
    },
    {
        eyebrow: "Straight to play",
        title: "From the page to the grid",
        body: "A creature in your compendium is one click from being a token on a battle map, stats and all. The gap between 'this monster exists in my notes' and 'this monster is on the board' disappears.",
        points: [
            "Drop compendium creatures onto maps as ready tokens",
            "Tokens carry the creature's stats and conditions",
            "Homebrew and SRD creatures behave the same",
            "No re-typing numbers between prep and play",
        ],
    },
];

const highlight = {
    title: "Reference that stays out of your way",
    body: "The best rules reference is the one you barely notice — it's just there when you reach for it. Everything in the compendium is built to be found fast and used without breaking the moment.",
};
</script>

<template>
    <FeatureDetail
        :can-login="canLogin"
        :can-register="canRegister"
        current="marketing.features.compendium"
        doc-title="Compendium"
        eyebrow="Compendium"
        title="Every stat block, one search away"
        intro="A built-in library of monsters, spells and items you can search, embed in any entry, and drop straight onto a battle map as a playable token."
        :sections="sections"
        :highlight="highlight"
    />
</template>
