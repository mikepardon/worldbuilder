<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Markdown from "@/Components/Markdown.vue";
import { Head } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    session: { type: Object, required: true },
    campaign: { type: Object, required: true },
    world: { type: Object, required: true },
    isGm: { type: Boolean, default: false },
    attendees: { type: Array, default: () => [] },
    recap: { type: Object, default: null },
});

const tab = ref("stylised"); // stylised | analysis (GM only)

const TYPE_LABELS = {
    location: "Locations",
    npc: "NPCs",
    faction: "Factions",
    item: "Items",
    monster: "Monsters",
    spell: "Spells",
};
const TYPE_ORDER = ["location", "npc", "faction", "monster", "item", "spell"];

const entityGroups = computed(() => {
    const groups = {};
    for (const e of props.recap?.entities ?? [])
        (groups[e.type] ??= []).push(e);
    return TYPE_ORDER.filter((t) => groups[t]?.length).map((t) => ({
        type: t,
        label: TYPE_LABELS[t] ?? t,
        items: groups[t],
    }));
});
</script>

<template>
    <Head :title="`${session.title} · Session`" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl px-4 py-6">
            <!-- Header -->
            <div class="mb-1 text-xs uppercase tracking-wide text-faint">
                {{ world.name }} · {{ campaign.name }}
            </div>
            <h1 class="font-display text-2xl text-bright">
                {{ session.title }}
            </h1>
            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                <span v-if="session.held_on">Played {{ session.held_on }}</span>
                <span v-if="attendees.length"
                    >Present: {{ attendees.join(", ") }}</span
                >
            </div>

            <div v-if="!recap" class="panel mt-5 p-6 text-sm text-faint">
                The recap for this session isn’t ready yet.
            </div>

            <template v-else>
                <!-- GM tabs -->
                <div
                    v-if="isGm"
                    class="mt-5 flex gap-4 border-b border-edge text-sm"
                >
                    <button
                        class="-mb-px border-b-2 px-1 pb-2"
                        :class="
                            tab === 'stylised'
                                ? 'border-amber text-bright'
                                : 'border-transparent text-muted hover:text-ink'
                        "
                        @click="tab = 'stylised'"
                    >
                        Stylised
                    </button>
                    <button
                        class="-mb-px border-b-2 px-1 pb-2"
                        :class="
                            tab === 'analysis'
                                ? 'border-amber text-bright'
                                : 'border-transparent text-muted hover:text-ink'
                        "
                        @click="tab = 'analysis'"
                    >
                        Full analysis
                    </button>
                </div>

                <!-- Stylised recap (players see this; GM's default tab) -->
                <section
                    v-if="!isGm || tab === 'stylised'"
                    class="panel mt-5 p-5 text-muted"
                >
                    <Markdown
                        v-if="recap.recap_stylized"
                        :source="recap.recap_stylized"
                    />
                    <p v-else class="text-sm text-faint">
                        No stylised recap for this session.
                    </p>
                </section>

                <!-- Full analysis (GM only) -->
                <div v-else class="mt-5 space-y-5">
                    <section class="panel p-4 text-muted">
                        <h2 class="mb-2 font-display text-lg text-bright">
                            Recap
                        </h2>
                        <Markdown
                            v-if="recap.recap_full"
                            :source="recap.recap_full"
                        />
                    </section>
                    <section v-if="recap.moments?.length" class="panel p-4">
                        <h2 class="mb-3 font-display text-lg text-bright">
                            Memorable Moments
                        </h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="(m, i) in recap.moments"
                                :key="i"
                                class="rounded border border-edge2 bg-night/40 p-3"
                            >
                                <span
                                    class="mb-1 inline-block rounded bg-raised px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-amber"
                                    >{{ m.type }}</span
                                >
                                <p class="text-sm text-ink">
                                    <Markdown :source="m.description" inline />
                                </p>
                            </div>
                        </div>
                    </section>
                    <section v-if="recap.outline?.length" class="panel p-4">
                        <h2 class="mb-3 font-display text-lg text-bright">
                            Outline
                        </h2>
                        <ol class="space-y-2">
                            <li
                                v-for="(o, i) in recap.outline"
                                :key="i"
                                class="border-l-2 border-edge2 pl-3"
                            >
                                <div class="text-sm text-bright">
                                    {{ o.title }}
                                </div>
                                <div v-if="o.detail" class="text-sm text-muted">
                                    <Markdown :source="o.detail" inline />
                                </div>
                            </li>
                        </ol>
                    </section>
                    <section v-if="recap.next_steps?.length" class="panel p-4">
                        <h2 class="mb-3 font-display text-lg text-bright">
                            Next Steps
                        </h2>
                        <ul class="space-y-1.5">
                            <li
                                v-for="(step, i) in recap.next_steps"
                                :key="i"
                                class="flex gap-2 text-sm text-muted"
                            >
                                <span class="text-amber">•</span>
                                <span><Markdown :source="step" inline /></span>
                            </li>
                        </ul>
                    </section>
                    <section
                        v-for="group in entityGroups"
                        :key="group.type"
                        class="panel p-4"
                    >
                        <h2 class="mb-2 font-display text-base text-bright">
                            {{ group.label }}
                            <span class="text-xs text-faint"
                                >({{ group.items.length }})</span
                            >
                        </h2>
                        <ul class="space-y-2">
                            <li v-for="(e, i) in group.items" :key="i">
                                <div class="text-sm text-bright">
                                    {{ e.name }}
                                </div>
                                <div
                                    v-if="e.description"
                                    class="text-xs text-muted"
                                >
                                    {{ e.description }}
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
