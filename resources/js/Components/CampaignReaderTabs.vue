<script setup>
// The campaign sub-nav on the public reader: the campaign name + My Campaign / Characters / Sessions.
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    campaign: Object, // world head (for the world slug)
    selectedCampaign: Object, // { name, slug }
    active: { type: String, default: "overview" }, // overview | characters | sessions | schedule
});

const base = computed(
    () => `/w/${props.campaign.slug}/campaigns/${props.selectedCampaign.slug}`,
);
const tabs = computed(() => [
    { key: "overview", label: "My Campaign", href: base.value },
    {
        key: "characters",
        label: "Characters",
        href: `${base.value}/characters`,
    },
    { key: "sessions", label: "Sessions", href: `${base.value}/sessions` },
    { key: "schedule", label: "Schedule", href: `${base.value}/schedule` },
]);
</script>

<template>
    <div
        class="mb-8 flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-[#262a33] pb-3"
    >
        <Link
            :href="`/w/${campaign.slug}/campaigns`"
            class="font-display text-xl text-[#f5f1e8] hover:text-teal"
            >{{ selectedCampaign.name }}</Link
        >
        <nav
            class="flex flex-wrap gap-5 font-mono text-xs uppercase tracking-wider"
        >
            <Link
                v-for="t in tabs"
                :key="t.key"
                :href="t.href"
                class="hover:text-teal"
                :class="active === t.key ? 'text-amber' : 'text-[#9aa0ab]'"
            >
                {{ t.label }}
            </Link>
        </nav>
    </div>
</template>
