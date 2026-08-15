<script setup>
import { Link } from "@inertiajs/vue3";

// Cross-navigation between the five feature deep-dive pages. Pass `current` (the route name) to mute the
// page you're already on.
const { current = "" } = defineProps({
    current: { type: String, default: "" },
});

const features = [
    { label: "Worldbuilding", route: "marketing.features.worldbuilding" },
    { label: "Virtual tabletop", route: "marketing.features.vtt" },
    { label: "Compendium", route: "marketing.features.compendium" },
    { label: "Publishing", route: "marketing.features.publishing" },
    { label: "AI assistant", route: "marketing.features.ai" },
];
</script>

<template>
    <nav class="flex flex-wrap items-center justify-center gap-2">
        <Link
            v-for="feature in features"
            :key="feature.route"
            :href="route(feature.route)"
            class="rounded-full border px-4 py-1.5 text-sm transition"
            :class="
                feature.route === current
                    ? 'border-amber/60 bg-amber/10 text-amber'
                    : 'border-edge3 text-muted hover:border-teal hover:text-ink'
            "
        >
            {{ feature.label }}
        </Link>
    </nav>
</template>
