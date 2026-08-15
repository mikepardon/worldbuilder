<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MarketingShell from '@/Components/MarketingShell.vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const groups = [
    {
        heading: 'Getting started',
        items: [
            {
                question: 'Do I need to pay to try it?',
                answer: 'No. You can create a world, run a campaign and publish a player site for free. Paid plans add more worlds, more AI credits, more storage and custom domains — but nothing is locked behind a card to get going.',
            },
            {
                question: 'Which game systems does it support?',
                answer: 'Worldbuilder is system-agnostic for lore, locations, factions and timelines, so it fits any tabletop setting. The built-in compendium and battle maps lean towards D&D 5e-style play, including importing characters from D&D Beyond.',
            },
            {
                question: 'Can I move an existing world in?',
                answer: 'You can build entries manually or lean on the AI assistant to expand rough notes into full entries — the fastest way to turn a pile of notes into a structured world.',
            },
        ],
    },
    {
        heading: 'Worlds, campaigns and players',
        items: [
            {
                question: 'What is the difference between a world and a campaign?',
                answer: 'A world is your setting — its places, people, factions and lore. A campaign is a story you run inside that world. One world can host many campaigns, all sharing the same canon, so your groups never drift out of sync.',
            },
            {
                question: 'How do players see my world?',
                answer: 'You publish a read-only player site. You control exactly what is visible: anything marked GM-only stays hidden until you choose to reveal it, so there are no accidental spoilers.',
            },
            {
                question: 'Can my players edit anything?',
                answer: 'The published site is read-only for players. Editing stays with the GM (and any collaborators you invite), so your canon is never changed out from under you.',
            },
        ],
    },
    {
        heading: 'AI and pricing',
        items: [
            {
                question: 'How does the AI assistant work?',
                answer: 'It drafts and expands entries and turns session notes into recaps, working from your world’s existing canon. Every account gets free AI credits each day, and paid plans add more.',
            },
            {
                question: 'What happens when I run out of daily credits?',
                answer: 'Everything except the AI assistant keeps working as normal. Your credits reset the next day, and paid plans raise the daily allowance.',
            },
            {
                question: 'Can I use my own domain?',
                answer: 'Yes — paid plans let you publish the player site on a custom domain, so your world lives at an address that feels like its own rather than a shared one.',
            },
        ],
    },
];
</script>

<template>
    <Head title="FAQ — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">FAQ</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-[#f5f1e8]">
                Questions, answered
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                The things game masters ask most before they start building. If yours isn’t here,
                the quickest way to find out is to create a free world and try it.
            </p>
        </section>

        <section class="mx-auto max-w-3xl px-6 pb-8">
            <div v-for="group in groups" :key="group.heading" class="mb-12">
                <h2 class="mb-4 font-mono text-xs uppercase tracking-widest text-[#6f7580]">{{ group.heading }}</h2>
                <dl class="space-y-3">
                    <details
                        v-for="item in group.items"
                        :key="item.question"
                        class="group rounded-lg border border-[#262a33] bg-[#181b21] p-5"
                    >
                        <summary class="flex cursor-pointer items-center justify-between gap-4 font-serif text-lg text-[#f3efe6] marker:content-['']">
                            {{ item.question }}
                            <span class="text-[#6fbfc4] transition group-open:rotate-45" aria-hidden="true">+</span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-[#9aa0ab]">{{ item.answer }}</p>
                    </details>
                </dl>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-6 pb-24 text-center">
            <h2 class="font-display text-3xl text-[#f5f1e8]">Still curious?</h2>
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
