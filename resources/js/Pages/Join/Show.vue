<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    campaign: Object,
    alreadyIn: Boolean,
});

const join = () => router.post(route('join.store', props.campaign.code));
</script>

<template>
    <Head :title="`Join ${campaign.name}`" />

    <div class="flex min-h-screen items-center justify-center bg-[#0e1014] px-6 font-serif text-[#e8e3d9]">
        <div class="max-w-md text-center">
            <div class="font-mono text-xs uppercase tracking-[0.3em] text-[#6fbfc4]">An invitation</div>
            <h1 class="mt-3 font-display text-4xl text-[#f5f1e8]">{{ campaign.name }}</h1>
            <p v-if="campaign.description" class="mt-3 text-[#b8bcc4]">{{ campaign.description }}</p>

            <div class="mt-8">
                <a
                    v-if="alreadyIn"
                    :href="`/w/${campaign.slug}`"
                    class="inline-block rounded-md bg-amber-600 px-6 py-3 font-medium text-white hover:bg-amber-700"
                >
                    Enter the world →
                </a>
                <button
                    v-else
                    class="rounded-md bg-amber-600 px-6 py-3 font-medium text-white hover:bg-amber-700"
                    @click="join"
                >
                    Join as a player
                </button>
            </div>

            <Link href="/campaigns" class="mt-6 inline-block text-sm text-[#9aa0ab] hover:text-[#6fbfc4]">Back to your worlds</Link>
        </div>
    </div>
</template>
