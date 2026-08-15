<script setup>
import FeatureDetail from "@/Components/FeatureDetail.vue";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const sections = [
    {
        eyebrow: "Linked entries",
        title: "Everything is an entry, and everything connects",
        body: "Locations, people, factions, events, items and lore are all entries you can cross-reference. Mention a city inside a character's history and it becomes a link; rename the city and every mention follows. The more you write, the more your world holds itself together.",
        points: [
            "Purpose-built entry types for places, people, factions, timelines and lore",
            "Wiki-style links between any two entries",
            "Reference fields that can only point at entries that really exist",
            "Rename or move an entry and its links stay intact",
        ],
    },
    {
        eyebrow: "Structured, your way",
        title: "Custom fields and quick facts per world",
        body: "Every world defines the fields that matter to it — a ruler's allegiance, a location's population, a faction's sigil. Quick-facts sit at the top of each entry so the details you reach for mid-session are always a glance away.",
        points: [
            "Design the fields each entry type carries",
            "Multi-value fields, ordering and per-world quick facts",
            "Tags to slice your world however you think about it",
            "Accent colours and cover art to give places their own identity",
        ],
    },
    {
        eyebrow: "Findable",
        title: "Search and a relationship web, not a flat wiki",
        body: "Full-text search reaches every entry, tag and note in an instant. When you want the bigger picture, the relationship web shows how people, places and factions connect — so the shape of your world is something you can see, not just remember.",
        points: [
            "Instant full-text search across the whole world",
            "A visual web of how entries relate",
            "Timelines that keep your history in order",
            "Secrets you can hide inline until the table earns them",
        ],
    },
];

const highlight = {
    title: "Built for the middle of a session",
    body: "Worldbuilding isn't a one-off — it happens live, at the table. Everything is quick to edit, quick to find, and quick to reveal, so your prep keeps up with the story instead of holding it back.",
};
</script>

<template>
    <FeatureDetail
        :can-login="canLogin"
        :can-register="canRegister"
        current="marketing.features.worldbuilding"
        doc-title="Worldbuilding"
        eyebrow="Worldbuilding"
        title="A canon that holds together"
        intro="Capture your whole setting as linked, searchable entries — people, places, factions, timelines and lore — that stay consistent as your world grows."
        :sections="sections"
        :highlight="highlight"
    />
</template>
