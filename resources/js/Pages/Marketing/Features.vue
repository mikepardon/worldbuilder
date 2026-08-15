<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MarketingShell from '@/Components/MarketingShell.vue';
import MarketingCta from '@/Components/MarketingCta.vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const sections = [
    {
        eyebrow: 'The world',
        title: 'A canon that holds together',
        body: 'Every location, person, faction, event and scrap of lore lives as a linked entry. Cross-reference anything, and Worldbuilder keeps the connections in step — rename a city and every mention follows.',
        route: 'marketing.features.worldbuilding',
        points: [
            'Locations, people, factions, timelines and lore as first-class entry types',
            'Custom fields and quick-facts tailored to each world',
            'A relationship web you can browse visually, not just a flat wiki',
            'Full-text search across every entry, tag and note',
        ],
    },
    {
        eyebrow: 'The table',
        title: 'Run campaigns without leaving your world',
        body: 'One world can host many campaigns, all drawing on the same canon. Plan sessions, track what your players have uncovered, and drop into a live virtual tabletop when the dice come out.',
        route: 'marketing.features.vtt',
        points: [
            'Battle maps with tokens, fog of war and initiative tracking',
            'Real-time rooms so your table shares one board',
            'Session planning and recaps tied back to the world',
            'Import player characters straight from D&D Beyond',
        ],
    },
    {
        eyebrow: 'The rules',
        title: 'A compendium built in',
        body: 'Monsters, spells, items and stat blocks are searchable, filterable and ready to use. Embed a monster in an entry, or drop it onto a battle map as a live token — no flipping through PDFs.',
        route: 'marketing.features.compendium',
        points: [
            'Searchable compendium of monsters, spells and items',
            'Import from open SRD sources so your reference stays current',
            'Embed stat blocks anywhere in your world',
            'Drop creatures onto maps as ready-to-run tokens',
        ],
    },
    {
        eyebrow: 'The reveal',
        title: 'Publish exactly what your players should see',
        body: 'Every world can publish a beautiful, read-only player site. You decide what is visible and what stays GM-only, so secrets hold until the moment you choose to reveal them.',
        route: 'marketing.features.publishing',
        points: [
            'Per-entry visibility — GM-only until you publish',
            'A polished, searchable reader for your players',
            'Custom reader layouts and per-world quick facts',
            'Custom domains on paid plans so your world gets its own address',
        ],
    },
    {
        eyebrow: 'The assistant',
        title: 'AI that helps you write, not decide',
        body: 'Draft an entry, expand a stub, or turn rough session notes into a polished recap. The assistant works from your canon and stays out of the way — every account gets free credits each day.',
        route: 'marketing.features.ai',
        points: [
            'Draft and expand entries in your world’s voice',
            'Turn raw session notes into shareable recaps',
            'Free daily AI credits on every account',
            'More credits and headroom on paid plans',
        ],
    },
];
</script>

<template>
    <Head title="Features — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">Features</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-[#f5f1e8]">
                Everything your world needs, in one place
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Worldbuilder brings your setting, your campaigns, your reference material and your player site
                together — so nothing drifts out of canon and nothing gets lost between tools.
            </p>
        </section>

        <section
            v-for="(feature, index) in sections"
            :key="feature.title"
            class="border-t border-[#1e222a]"
        >
            <div class="mx-auto grid max-w-5xl gap-8 px-6 py-16 md:grid-cols-2 md:items-center">
                <div :class="index % 2 === 1 ? 'md:order-2' : ''">
                    <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">{{ feature.eyebrow }}</div>
                    <h2 class="mt-4 font-display text-3xl text-[#f3efe6]">{{ feature.title }}</h2>
                    <p class="mt-4 text-base leading-relaxed text-[#b8bcc4]">{{ feature.body }}</p>
                    <Link :href="route(feature.route)" class="mt-5 inline-flex items-center gap-1 text-sm text-[#6fbfc4] hover:text-[#8fd3d7]">
                        Explore {{ feature.eyebrow.replace('The ', '') }} →
                    </Link>
                </div>
                <ul :class="index % 2 === 1 ? 'md:order-1' : ''" class="space-y-3 rounded-xl border border-[#262a33] bg-[#14171d] p-6">
                    <li
                        v-for="point in feature.points"
                        :key="point"
                        class="flex items-start gap-3 text-sm leading-relaxed text-[#c8ccd3]"
                    >
                        <span class="mt-0.5 text-[#6fbfc4]" aria-hidden="true">✦</span>
                        <span>{{ point }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <div class="border-t border-[#1e222a]">
            <MarketingCta
                :can-register="canRegister"
                title="See it with your own world"
                text="Start free — build a world, invite your table, and publish when you are ready."
                secondary-label="See use cases"
                secondary-route="marketing.use-cases"
            />
        </div>
    </MarketingShell>
</template>
