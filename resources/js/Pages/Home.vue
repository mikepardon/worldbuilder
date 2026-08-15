<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MarketingShell from '@/Components/MarketingShell.vue';
import MarketingCta from '@/Components/MarketingCta.vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    publicWorlds: Array,
});

const features = [
    {
        eyebrow: 'Worldbuilding',
        title: 'Linked, searchable canon',
        body: 'People, places, factions, timelines and lore as entries that cross-reference each other and stay consistent as you write.',
        route: 'marketing.features.worldbuilding',
    },
    {
        eyebrow: 'Virtual tabletop',
        title: 'Maps, tokens & fog',
        body: 'A real-time room with battle maps, initiative and fog — sharing the same monsters and characters you already built.',
        route: 'marketing.features.vtt',
    },
    {
        eyebrow: 'Compendium',
        title: 'Stat blocks to hand',
        body: 'Monsters, spells and items you can search, embed in any entry, and drop onto a map as a playable token.',
        route: 'marketing.features.compendium',
    },
    {
        eyebrow: 'Publishing',
        title: 'A player-facing site',
        body: 'Publish a beautiful reader of exactly what players should see — with GM-only secrets until you reveal them.',
        route: 'marketing.features.publishing',
    },
    {
        eyebrow: 'AI assistant',
        title: 'Draft & recap',
        body: 'Draft entries in your world’s voice and turn session recordings into recaps — with clear, per-action credit costs.',
        route: 'marketing.features.ai',
    },
];

const steps = [
    { n: '01', title: 'Build your world', body: 'Capture locations, people, factions and lore as linked, searchable entries.' },
    { n: '02', title: 'Run campaigns in it', body: 'Plan sessions and drop into a live tabletop that shares your canon.' },
    { n: '03', title: 'Publish to your table', body: 'Share a player site of exactly what they should see — secrets stay yours.' },
];
</script>

<template>
    <Head title="Worldbuilder — build and share your campaign world" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <!-- Hero -->
        <section class="mx-auto max-w-4xl px-6 py-24 text-center">
            <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">For game masters</div>
            <h1 class="mt-4 font-display text-6xl leading-tight text-[#f5f1e8]">
                Build a world.<br />Share it with your table.
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Locations, people, factions, timelines and lore — organised, searchable, and published
                to a beautiful player-facing site. Keep your secrets GM-only until the moment they land.
            </p>
            <div class="mt-10 flex justify-center gap-3">
                <Link :href="route('register')" class="rounded-md bg-amber-600 px-6 py-3 font-medium text-white hover:bg-amber-700">
                    Create your world
                </Link>
                <Link :href="route('marketing.how')" class="rounded-md border border-[#2e323c] px-6 py-3 text-[#c8ccd3] hover:border-[#6fbfc4]">
                    How it works
                </Link>
            </div>
            <div class="mt-6 flex justify-center gap-6 text-sm text-[#6f7580]">
                <Link :href="route('marketing.features')" class="hover:text-[#c8ccd3]">Explore features</Link>
                <Link :href="route('marketing.examples')" class="hover:text-[#c8ccd3]">See examples</Link>
            </div>
        </section>

        <!-- What's inside -->
        <section class="border-t border-[#1e222a]">
            <div class="mx-auto max-w-6xl px-6 py-16">
                <div class="mb-8 text-center">
                    <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">What’s inside</div>
                    <h2 class="mt-3 font-display text-3xl text-[#f3efe6]">Your whole table, in one world</h2>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="feature in features"
                        :key="feature.route"
                        :href="route(feature.route)"
                        class="flex flex-col rounded-lg border border-[#262a33] bg-[#181b21] p-6 transition hover:border-[#6fbfc4]"
                    >
                        <div class="font-mono text-[10px] uppercase tracking-[0.2em] text-[#6fbfc4]">{{ feature.eyebrow }}</div>
                        <h3 class="mt-2 font-serif text-xl text-[#f3efe6]">{{ feature.title }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-relaxed text-[#9aa0ab]">{{ feature.body }}</p>
                        <span class="mt-4 text-xs text-[#6fbfc4]">Learn more →</span>
                    </Link>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section class="border-t border-[#1e222a]">
            <div class="mx-auto max-w-5xl px-6 py-16">
                <div class="mb-8 text-center">
                    <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">How it works</div>
                    <h2 class="mt-3 font-display text-3xl text-[#f3efe6]">From a blank page to a living world</h2>
                </div>
                <ol class="grid gap-5 md:grid-cols-3">
                    <li v-for="step in steps" :key="step.n" class="rounded-lg border border-[#262a33] bg-[#14171d] p-6">
                        <div class="font-mono text-sm text-[#6fbfc4]">{{ step.n }}</div>
                        <h3 class="mt-2 font-display text-xl text-[#f3efe6]">{{ step.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#9aa0ab]">{{ step.body }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <!-- One home instead of a pile of tabs -->
        <section class="border-t border-[#1e222a]">
            <div class="mx-auto max-w-4xl px-6 py-16 text-center">
                <h2 class="font-display text-3xl text-[#f5f1e8]">One home instead of a pile of tabs</h2>
                <p class="mx-auto mt-4 max-w-2xl text-[#b8bcc4]">
                    Most GMs stitch together a wiki, a documents folder and a separate virtual tabletop.
                    Worldbuilder is all of it in one place, so nothing drifts out of sync.
                </p>
                <Link :href="route('marketing.compare')" class="mt-6 inline-flex items-center gap-1 text-sm text-[#6fbfc4] hover:text-[#8fd3d7]">
                    See the comparison →
                </Link>
            </div>
        </section>

        <!-- Public worlds -->
        <section v-if="publicWorlds.length" class="border-t border-[#1e222a]">
            <div class="mx-auto max-w-6xl px-6 py-16">
                <div class="mb-6 flex items-end justify-between">
                    <h2 class="font-mono text-xs uppercase tracking-widest text-[#6f7580]">Worlds people are building</h2>
                    <Link :href="route('marketing.examples')" class="text-sm text-[#6fbfc4] hover:text-[#8fd3d7]">See all →</Link>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <a
                        v-for="w in publicWorlds"
                        :key="w.code"
                        :href="`/w/${w.slug}`"
                        class="rounded-lg border border-[#262a33] bg-[#181b21] p-5 transition hover:border-[#6fbfc4]"
                    >
                        <div class="font-serif text-xl text-[#f3efe6]">{{ w.name }}</div>
                        <p v-if="w.description" class="mt-2 line-clamp-3 text-sm text-[#9aa0ab]">{{ w.description }}</p>
                    </a>
                </div>
            </div>
        </section>

        <!-- Closing CTA -->
        <div class="border-t border-[#1e222a]">
            <MarketingCta
                :can-register="canRegister"
                title="Build a world. Share it with your table."
                text="Start free — no card required. Every account gets AI credits to try it out."
                secondary-label="See pricing"
                secondary-route="marketing.pricing"
            />
        </div>
    </MarketingShell>
</template>
