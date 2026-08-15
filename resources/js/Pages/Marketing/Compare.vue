<script setup>
import MarketingShell from "@/Components/MarketingShell.vue";
import MarketingCta from "@/Components/MarketingCta.vue";
import { Head } from "@inertiajs/vue3";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

// yes = fully covered, partial = possible but manual/limited, no = not really.
const columns = ["Worldbuilder", "A general wiki", "Docs + a separate VTT"];

const rows = [
    { label: "Linked, searchable worldbuilding", values: ["yes", "yes", "partial"] },
    { label: "Player-facing site with GM-only secrets", values: ["yes", "partial", "no"] },
    { label: "Built-in battle maps, tokens & fog", values: ["yes", "no", "yes"] },
    { label: "Compendium you can embed and drop on maps", values: ["yes", "no", "partial"] },
    { label: "AI drafting grounded in your own canon", values: ["yes", "no", "no"] },
    { label: "Session recordings turned into recaps", values: ["yes", "no", "no"] },
    { label: "Import characters from D&D Beyond", values: ["yes", "no", "partial"] },
    { label: "Your world on a custom domain", values: ["yes", "partial", "no"] },
    { label: "One tool from prep to play", values: ["yes", "no", "no"] },
];

const symbol = { yes: "✓", partial: "~", no: "·" };
const tone = {
    yes: "text-teal",
    partial: "text-amber",
    no: "text-faint",
};
</script>

<template>
    <Head title="Compare — Worldbuilder" />

    <MarketingShell :can-login="canLogin" :can-register="canRegister">
        <section class="mx-auto max-w-4xl px-6 py-20 text-center">
            <div class="eyebrow tracking-[0.3em]">Compare</div>
            <h1 class="mt-4 font-display text-5xl leading-tight text-bright">
                One home instead of a pile of tabs
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg font-light text-[#b8bcc4]">
                Most GMs stitch together a wiki, a documents folder and a separate virtual tabletop.
                Worldbuilder is all of it in one place, so nothing drifts out of sync.
            </p>
        </section>

        <section class="mx-auto max-w-4xl px-6 pb-8">
            <div class="overflow-x-auto rounded-xl border border-edge2">
                <table class="w-full min-w-[560px] text-left text-sm">
                    <thead class="bg-surface text-xs uppercase tracking-wider text-faint">
                        <tr>
                            <th class="px-4 py-3 font-medium">Capability</th>
                            <th
                                v-for="(column, index) in columns"
                                :key="column"
                                class="px-4 py-3 text-center font-medium"
                                :class="index === 0 ? 'text-amber' : ''"
                            >
                                {{ column }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.label"
                            class="border-t border-edge"
                        >
                            <td class="px-4 py-3 text-[#c8ccd3]">{{ row.label }}</td>
                            <td
                                v-for="(value, index) in row.values"
                                :key="index"
                                class="px-4 py-3 text-center text-base"
                                :class="tone[value]"
                            >
                                <span :aria-label="value">{{ symbol[value] }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-center font-mono text-[10px] uppercase tracking-widest text-faint">
                ✓ built in &nbsp;·&nbsp; ~ possible but manual &nbsp;·&nbsp; · not really
            </p>
        </section>

        <div class="border-t border-edge">
            <MarketingCta
                :can-register="canRegister"
                title="Trade the pile of tabs for one world"
                text="Start free — bring your setting, your table and your reference together."
                secondary-label="See pricing"
                secondary-route="marketing.pricing"
            />
        </div>
    </MarketingShell>
</template>
