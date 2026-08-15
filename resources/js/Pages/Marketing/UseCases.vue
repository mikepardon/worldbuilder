<script setup>
import MarketingShell from "@/Components/MarketingShell.vue";
import MarketingCta from "@/Components/MarketingCta.vue";
import { Head, Link } from "@inertiajs/vue3";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const personas = [
    {
        eyebrow: "The homebrewer",
        title: "Building a world from nothing",
        body: "You have a map, a handful of names and a hundred half-ideas. Worldbuilder gives them a home that grows with you — entries that link as you write, an AI collaborator for the blank-page moments, and search so you never lose the thread you pulled three sessions ago.",
        link: { label: "See worldbuilding", route: "marketing.features.worldbuilding" },
    },
    {
        eyebrow: "The campaign runner",
        title: "Running a long campaign for the same table",
        body: "Years of canon, a cast of dozens and players who remember everything. Keep every thread straight, publish what the party has earned, and turn each session's recording into a recap they can reread between games.",
        link: { label: "See publishing", route: "marketing.features.publishing" },
    },
    {
        eyebrow: "The multi-table GM",
        title: "Juggling several games at once",
        body: "One setting, many campaigns — each with its own players and pace, all sharing the same world. Reuse your lore instead of rebuilding it, and drop into a virtual tabletop for whichever table meets tonight.",
        link: { label: "See the tabletop", route: "marketing.features.vtt" },
    },
    {
        eyebrow: "The new GM",
        title: "Running your first game",
        body: "You don't need a system for note-taking before session one. Start with the free demo world, lean on generators and the compendium when you're stuck, and let the tools carry the admin so you can focus on the table.",
        link: { label: "How it works", route: "marketing.how" },
    },
];
</script>

<template>
    <Head title="Use cases — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="eyebrow tracking-[0.3em]">Use cases</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-bright">
                However you run your table
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Worldbuilder fits the way you already play — whether you're inventing a world from
                scratch, running a years-long campaign, or sitting down to GM for the very first time.
            </p>
        </section>

        <section class="mx-auto max-w-5xl px-6 pb-16">
            <div class="grid gap-5 md:grid-cols-2">
                <div
                    v-for="persona in personas"
                    :key="persona.title"
                    class="flex flex-col rounded-xl border border-edge2 bg-card p-6"
                >
                    <div class="eyebrow tracking-[0.3em]">{{ persona.eyebrow }}</div>
                    <h2 class="mt-3 font-display text-2xl text-[#f3efe6]">{{ persona.title }}</h2>
                    <p class="mt-3 flex-1 text-sm leading-relaxed text-muted">{{ persona.body }}</p>
                    <Link
                        :href="route(persona.link.route)"
                        class="mt-5 inline-flex items-center gap-1 text-sm text-teal hover:text-[#8fd3d7]"
                    >
                        {{ persona.link.label }} →
                    </Link>
                </div>
            </div>
        </section>

        <div class="border-t border-edge">
            <MarketingCta
                :can-register="canRegister"
                title="Find your way in"
                text="Start with the free demo world, or build your own from the first entry."
                secondary-label="Browse examples"
                secondary-route="marketing.examples"
            />
        </div>
    </MarketingShell>
</template>
