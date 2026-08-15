<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import { Head, Link } from "@inertiajs/vue3";

defineProps({
    campaign: Object,
    sections: Array,
    campaigns: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head :title="`Campaigns — ${campaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="campaigns"
    >
        <div class="mx-auto max-w-5xl px-6 py-14">
            <h1 class="font-display text-4xl text-[#f5f1e8]">Campaigns</h1>
            <div class="mt-1 text-sm text-[#9aa0ab]">
                {{ campaigns.length }}
                {{ campaigns.length === 1 ? "campaign" : "campaigns" }}
            </div>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <Link
                    v-for="c in campaigns"
                    :key="c.slug"
                    :href="`/w/${campaign.slug}/campaigns/${c.slug}`"
                    class="rounded-lg border border-[#262a33] bg-[#181b21] p-5 transition hover:border-[#6fbfc4]"
                >
                    <div class="flex items-center gap-2">
                        <div class="font-serif text-lg text-[#f3efe6]">
                            {{ c.name }}
                        </div>
                        <span
                            v-if="c.visibility !== 'public'"
                            class="rounded bg-amber/15 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-amber"
                            >{{
                                c.visibility === "hidden"
                                    ? "Unlisted"
                                    : "Private"
                            }}</span
                        >
                    </div>
                    <p
                        v-if="c.description"
                        class="mt-1 line-clamp-3 text-sm text-[#9aa0ab]"
                    >
                        {{ c.description }}
                    </p>
                    <div class="mt-3 font-mono text-[11px] text-[#6b7180]">
                        {{ c.session_count }}
                        {{ c.session_count === 1 ? "session" : "sessions" }}
                    </div>
                </Link>
                <div
                    v-if="!campaigns.length"
                    class="rounded-lg border border-dashed border-[#2e323c] p-10 text-center text-sm text-[#9aa0ab] sm:col-span-2"
                >
                    No campaigns to show yet.
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
