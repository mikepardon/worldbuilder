<script setup>
import RenderedContent from "@/Components/RenderedContent.vue";
import StatblockCard from "@/Components/StatblockCard.vue";
import PublicLayout from "@/Layouts/PublicLayout.vue";
import { Head, Link } from "@inertiajs/vue3";

defineProps({
    campaign: Object,
    sections: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => ({}) },
    item: Object,
    spells: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`${item.name} — ${campaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="compendium"
    >
        <div
            class="mx-auto px-6 py-14"
            :class="item.block ? 'max-w-4xl' : 'max-w-3xl'"
        >
            <Link
                :href="`/w/${campaign.slug}/compendium`"
                class="font-mono text-[11px] uppercase tracking-wider text-[#6fbfc4] hover:text-teal"
                >← Compendium</Link
            >

            <!-- Monster: the framed, two-column stat block stands on its own, description beneath it. -->
            <template v-if="item.block">
                <div class="mt-4">
                    <StatblockCard
                        :block="item.block"
                        :name="item.name"
                        :image="item.image_url"
                        :spells="spells"
                        framed
                        wide
                    />
                </div>
                <p
                    v-if="item.summary"
                    class="mt-5 text-[15px] italic text-[#9aa0ab]"
                >
                    {{ item.summary }}
                </p>
            </template>

            <!-- Everything else: the carded article with header and rendered content. -->
            <article
                v-else
                class="mt-4 overflow-hidden rounded-xl border border-edge2 bg-gradient-to-b from-[#181c23] to-[#14171d] shadow-lg"
            >
                <header class="border-b border-edge2 px-6 py-6 sm:px-8">
                    <div
                        class="font-mono text-[10px] uppercase tracking-widest text-[#6fbfc4]"
                    >
                        {{ item.typeLabel }}
                    </div>
                    <h1 class="mt-1 font-display text-3xl text-[#f5f1e8]">
                        {{ item.name }}
                    </h1>
                    <p
                        v-if="item.summary"
                        class="mt-2 text-[15px] italic text-[#9aa0ab]"
                    >
                        {{ item.summary }}
                    </p>
                </header>

                <div class="px-6 py-6 sm:px-8">
                    <RenderedContent
                        :content="item.document || ''"
                        :spells="spells"
                        :link-base="`/w/${campaign.slug}/`"
                    />
                </div>
            </article>
        </div>
    </PublicLayout>
</template>
