<script setup>
import FeatureNav from "@/Components/FeatureNav.vue";
import MarketingCta from "@/Components/MarketingCta.vue";
import MarketingShell from "@/Components/MarketingShell.vue";
import { Head } from "@inertiajs/vue3";

// Reusable body for a feature deep-dive page. Each page supplies its copy as data; the layout —
// hero, alternating capability rows, an optional highlight band, cross-nav and CTA — is shared.
const {
    canLogin = false,
    canRegister = false,
    current = "",
    docTitle = "",
    eyebrow = "",
    title = "",
    intro = "",
    sections = [],
    highlight = undefined,
} = defineProps({
    canLogin: { type: Boolean, default: false },
    canRegister: { type: Boolean, default: false },
    current: { type: String, default: "" },
    docTitle: { type: String, default: "" },
    eyebrow: { type: String, default: "" },
    title: { type: String, default: "" },
    intro: { type: String, default: "" },
    // [{ eyebrow, title, body, points: string[] }]
    sections: { type: Array, default: () => [] },
    // { title, body } — optional pull band between the rows and the CTA.
    highlight: { type: Object, default: undefined },
});
</script>

<template>
    <Head :title="`${docTitle} — Worldbuilder`" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 pb-8 pt-20 text-center">
            <div class="eyebrow tracking-[0.3em]">{{ eyebrow }}</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-bright">
                {{ title }}
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                {{ intro }}
            </p>
        </section>

        <section class="mx-auto max-w-5xl px-6 pb-6">
            <FeatureNav :current="current" />
        </section>

        <section
            v-for="(entry, index) in sections"
            :key="entry.title"
            class="border-t border-edge"
        >
            <div
                class="mx-auto grid max-w-5xl gap-8 px-6 py-16 md:grid-cols-2 md:items-center"
            >
                <div :class="index % 2 === 1 ? 'md:order-2' : ''">
                    <div class="eyebrow tracking-[0.3em]">{{ entry.eyebrow }}</div>
                    <h2 class="mt-4 font-display text-3xl text-[#f3efe6]">
                        {{ entry.title }}
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-[#b8bcc4]">
                        {{ entry.body }}
                    </p>
                </div>
                <ul
                    :class="index % 2 === 1 ? 'md:order-1' : ''"
                    class="space-y-3 rounded-xl border border-edge2 bg-[#14171d] p-6"
                >
                    <li
                        v-for="point in entry.points"
                        :key="point"
                        class="flex items-start gap-3 text-sm leading-relaxed text-[#c8ccd3]"
                    >
                        <span class="mt-0.5 text-teal" aria-hidden="true">✦</span>
                        <span>{{ point }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <section v-if="highlight" class="border-t border-edge">
            <div class="mx-auto max-w-3xl px-6 py-16 text-center">
                <h2 class="font-display text-2xl text-[#f3efe6]">
                    {{ highlight.title }}
                </h2>
                <p
                    class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-muted"
                >
                    {{ highlight.body }}
                </p>
            </div>
        </section>

        <div class="border-t border-edge">
            <MarketingCta
                :can-register="canRegister"
                secondary-label="Compare plans"
                secondary-route="marketing.pricing"
            />
        </div>
    </MarketingShell>
</template>
