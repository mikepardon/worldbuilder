<script setup>
import FeatureDetail from "@/Components/FeatureDetail.vue";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const sections = [
    {
        eyebrow: "A collaborator",
        title: "Draft and expand entries in your world's voice",
        body: "Ask for a new NPC, expand a stub into a full location, or rewrite an entry — the assistant works from your existing canon, so what it writes fits the world you've already built. You stay the author; it just keeps up.",
        points: [
            "Draft new entries or expand rough stubs",
            "Grounded in your world's existing tone and facts",
            "Targeted edits — it changes only what you ask",
            "Generators for names, places, encounters and more",
        ],
    },
    {
        eyebrow: "After the session",
        title: "Turn a recording into a recap",
        body: "Upload the session audio and get back a polished recap, the memorable moments, a scene outline, and the people and places that appeared — ready to publish to your players while it's still fresh.",
        points: [
            "Upload session audio; get a written recap",
            "Memorable moments and a scene outline, automatically",
            "The entities that came up, linked to your world",
            "Publish the recap to your players in a click",
        ],
    },
    {
        eyebrow: "Fair by design",
        title: "You always know what it costs",
        body: "Every AI action shows its credit cost up front, and light work costs less than heavy work — an NPC is a single credit; a full recap is priced by the length of the recording. Your free daily credits are always spent before anything you've paid for.",
        points: [
            "Clear per-action credit cost, shown before you run it",
            "Light entries cost little; recaps scale with length",
            "Free credits every day, plus a signup bonus to start",
            "Daily free credits are always used before paid ones",
        ],
    },
];

const highlight = {
    title: "AI that helps you write, not decide",
    body: "The assistant is there to take the busywork off your plate — the tenth tavern name, the write-up you're too tired to type — so you can spend your energy on the calls only a game master can make.",
};
</script>

<template>
    <FeatureDetail
        :can-login="canLogin"
        :can-register="canRegister"
        current="marketing.features.ai"
        doc-title="AI assistant"
        eyebrow="AI assistant"
        title="A worldbuilding assistant at your side"
        intro="Draft entries, run generators and turn session recordings into polished recaps — all grounded in your canon, all priced clearly so you're never surprised."
        :sections="sections"
        :highlight="highlight"
    />
</template>
