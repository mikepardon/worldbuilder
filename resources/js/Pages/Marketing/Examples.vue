<script setup>
import MarketingShell from "@/Components/MarketingShell.vue";
import MarketingCta from "@/Components/MarketingCta.vue";
import { Head } from "@inertiajs/vue3";

const { worlds = [] } = defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    worlds: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Examples — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="eyebrow tracking-[0.3em]">Examples</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-bright">
                Worlds people are building
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Every world below is a live, published player site — exactly what your table would see.
                Wander in and get a feel for what you can make.
            </p>
        </section>

        <section v-if="worlds.length" class="mx-auto max-w-6xl px-6 pb-16">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <a
                    v-for="world in worlds"
                    :key="world.slug"
                    :href="`/w/${world.slug}`"
                    class="flex flex-col rounded-lg border border-edge2 bg-card p-5 transition hover:border-teal"
                >
                    <div class="font-serif text-xl text-[#f3efe6]">{{ world.name }}</div>
                    <p
                        v-if="world.description"
                        class="mt-2 line-clamp-4 flex-1 text-sm leading-relaxed text-muted"
                    >
                        {{ world.description }}
                    </p>
                    <span class="mt-4 text-xs text-teal">Read as players →</span>
                </a>
            </div>
        </section>

        <section v-else class="mx-auto max-w-3xl px-6 pb-16">
            <div class="rounded-xl border border-edge2 bg-[#14171d] p-8 text-center">
                <p class="text-sm leading-relaxed text-muted">
                    No public worlds to show just yet — yours could be the first. Build a world and
                    publish it to share it here.
                </p>
            </div>
        </section>

        <div class="border-t border-edge">
            <MarketingCta
                :can-register="canRegister"
                title="Build a world worth showing off"
                text="Start free and publish when you're ready — your world could be on this page."
                secondary-label="See the features"
                secondary-route="marketing.features"
            />
        </div>
    </MarketingShell>
</template>
