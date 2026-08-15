<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MarketingShell from '@/Components/MarketingShell.vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    publicWorlds: Array,
});
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
                <Link :href="route('marketing.faq')" class="hover:text-[#c8ccd3]">Read the FAQ</Link>
            </div>
        </section>

        <!-- Public worlds -->
        <section v-if="publicWorlds.length" class="mx-auto max-w-6xl px-6 pb-24">
            <h2 class="mb-6 font-mono text-xs uppercase tracking-widest text-[#6f7580]">Worlds people are building</h2>
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
        </section>
    </MarketingShell>
</template>
