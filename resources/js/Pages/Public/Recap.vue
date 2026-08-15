<script setup>
import RecapReader from "@/Components/RecapReader.vue";
import { Head } from "@inertiajs/vue3";

defineProps({
    session: { type: Object, required: true },
    campaign: { type: Object, required: true },
    world: { type: Object, required: true },
    recap: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`${session.title} · Recap`" />

    <div class="min-h-screen bg-night text-ink">
        <header class="border-b border-edge">
            <div
                class="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 py-3"
            >
                <span class="font-display text-lg text-bright">{{
                    world.name
                }}</span>
                <span class="text-xs text-faint">{{ campaign.name }}</span>
                <span
                    class="ml-auto text-[11px] uppercase tracking-wide text-faint"
                    >Shared recap</span
                >
            </div>
        </header>

        <div class="mx-auto w-full max-w-6xl px-4 py-6">
            <h1 class="mb-5 font-display text-2xl text-bright">
                {{ session.title }}
            </h1>

            <RecapReader :recap="recap" />
        </div>
    </div>
</template>
