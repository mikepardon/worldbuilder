<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MarketingShell from '@/Components/MarketingShell.vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const steps = [
    {
        eyebrow: 'Step one',
        title: 'Build your world',
        body: 'Capture locations, people, factions, timelines and lore as linked entries. Everything is searchable and cross-referenced, so the more you write, the more your world holds together.',
    },
    {
        eyebrow: 'Step two',
        title: 'Run campaigns inside it',
        body: 'A world can host many campaigns. Invite your players, plan sessions, and drop into battle maps with tokens, fog and a live virtual tabletop — all sharing the same canon.',
    },
    {
        eyebrow: 'Step three',
        title: 'Publish to your table',
        body: 'Share a beautiful player-facing site of exactly what your players should see. GM-only secrets stay hidden until you choose to reveal them — no spoilers, no separate wiki to maintain.',
    },
];

const extras = [
    {
        title: 'AI worldbuilding assistant',
        body: 'Draft an entry, expand a stub, or turn rough session notes into a polished recap. Every account gets free AI credits each day, with more available on paid plans.',
    },
    {
        title: 'Compendium built in',
        body: 'Monsters, spells, items and more — searchable, filterable, and ready to embed in any entry or drop onto a battle map as a stat block.',
    },
    {
        title: 'Import from D&D Beyond',
        body: 'Pull player characters straight from D&D Beyond so their sheets stay in sync with what happens at the table.',
    },
    {
        title: 'Your world, your domain',
        body: 'Paid plans can publish the player site on a custom domain, so your world lives at an address that feels like its own.',
    },
];
</script>

<template>
    <Head title="How it works — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">How it works</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-[#f5f1e8]">
                From a blank page to a living world
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Worldbuilder gives you one home for your setting, your campaigns and the site your players read.
                Here is how the pieces fit together.
            </p>
        </section>

        <section class="mx-auto max-w-5xl px-6 pb-8">
            <ol class="grid gap-5 md:grid-cols-3">
                <li
                    v-for="step in steps"
                    :key="step.title"
                    class="rounded-lg border border-[#262a33] bg-[#181b21] p-6"
                >
                    <div class="font-mono text-xs uppercase tracking-widest text-[#6fbfc4]">{{ step.eyebrow }}</div>
                    <h2 class="mt-3 font-display text-2xl text-[#f3efe6]">{{ step.title }}</h2>
                    <p class="mt-3 text-sm leading-relaxed text-[#9aa0ab]">{{ step.body }}</p>
                </li>
            </ol>
        </section>

        <section class="mx-auto max-w-5xl px-6 py-16">
            <h2 class="mb-6 font-mono text-xs uppercase tracking-widest text-[#6f7580]">Everything else you get</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div
                    v-for="extra in extras"
                    :key="extra.title"
                    class="rounded-lg border border-[#262a33] bg-[#14171d] p-5"
                >
                    <h3 class="font-serif text-lg text-[#f3efe6]">{{ extra.title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-[#9aa0ab]">{{ extra.body }}</p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-6 pb-24 text-center">
            <h2 class="font-display text-3xl text-[#f5f1e8]">Ready to start building?</h2>
            <p class="mx-auto mt-3 max-w-xl text-[#b8bcc4]">
                Create your first world for free — no card required.
            </p>
            <div class="mt-8 flex justify-center gap-3">
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="rounded-md bg-amber-600 px-6 py-3 font-medium text-white hover:bg-amber-700"
                >
                    Create your world
                </Link>
                <Link
                    :href="route('marketing.pricing')"
                    class="rounded-md border border-[#2e323c] px-6 py-3 text-[#c8ccd3] hover:border-[#6fbfc4]"
                >
                    See pricing
                </Link>
            </div>
        </section>
    </MarketingShell>
</template>
