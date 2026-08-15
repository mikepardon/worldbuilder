<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    campaign: Object,
    sections: Array,
    viewer: { type: Object, default: () => ({}) },
    maps: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Maps — ${campaign.name}`" />

    <PublicLayout :campaign="campaign" :sections="sections" :viewer="viewer" active="maps">
        <div class="mx-auto max-w-5xl px-6 py-10">
            <h1 class="mb-6 font-display text-[34px] text-bright">Maps</h1>

            <div v-if="maps.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="m in maps" :key="m.slug" :href="`/w/${campaign.slug}/maps/${m.slug}`"
                    class="group overflow-hidden rounded-lg border border-edge2 bg-surface transition hover:border-teal/40"
                >
                    <div class="aspect-[16/10] w-full overflow-hidden bg-raised">
                        <img v-if="m.image_url" :src="m.image_url" :alt="m.name" class="h-full w-full object-cover transition group-hover:scale-[1.02]" />
                        <div v-else class="flex h-full w-full items-center justify-center text-faint">No image</div>
                    </div>
                    <div class="px-4 py-3 font-display text-[18px] text-ink">{{ m.name }}</div>
                </Link>
            </div>
            <p v-else class="text-muted">No maps have been published for this world yet.</p>
        </div>
    </PublicLayout>
</template>
