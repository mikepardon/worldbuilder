<script setup>
import { Link } from "@inertiajs/vue3";

// The closing call-to-action shared across marketing pages. `secondaryRoute` is a Ziggy route name.
const {
    title = "Start building your world",
    text = "Create your first world for free — no card required.",
    secondaryLabel = "How it works",
    secondaryRoute = "marketing.how",
    canRegister = false,
} = defineProps({
    title: { type: String, default: "Start building your world" },
    text: {
        type: String,
        default: "Create your first world for free — no card required.",
    },
    secondaryLabel: { type: String, default: "How it works" },
    secondaryRoute: { type: String, default: "marketing.how" },
    canRegister: { type: Boolean, default: false },
});
</script>

<template>
    <section class="mx-auto max-w-4xl px-6 py-24 text-center">
        <h2 class="font-display text-3xl text-bright">{{ title }}</h2>
        <p class="mx-auto mt-3 max-w-xl text-[#b8bcc4]">{{ text }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <Link
                v-if="canRegister"
                :href="route('register')"
                class="rounded-md bg-amber-600 px-6 py-3 font-medium text-white hover:bg-amber-700"
            >
                Create your world
            </Link>
            <Link
                :href="route(secondaryRoute)"
                class="rounded-md border border-edge3 px-6 py-3 text-[#c8ccd3] hover:border-teal"
            >
                {{ secondaryLabel }}
            </Link>
        </div>
    </section>
</template>
