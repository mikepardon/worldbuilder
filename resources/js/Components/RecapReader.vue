<script setup>
// Read-only presentation of a finished recap: the narrative variants, memorable moments, scene
// outline, next steps, entities and (when provided) the transcript. Shared by the public share-link
// page and the reader's session page so both stay identical, minus any editing.
import Markdown from "@/Components/Markdown.vue";
import { computed, ref } from "vue";

const props = defineProps({
    recap: { type: Object, required: true },
});

const TYPE_LABELS = {
    location: "Locations",
    npc: "NPCs",
    faction: "Factions",
    item: "Items",
    monster: "Monsters",
    spell: "Spells",
};
const TYPE_ORDER = ["location", "npc", "faction", "monster", "item", "spell"];

const variant = ref("full");
const mainTab = ref("analysis"); // analysis | transcript

const recapText = computed(
    () =>
        ({
            full: props.recap.recap_full,
            short: props.recap.recap_short,
            stylized: props.recap.recap_stylized,
        })[variant.value] ?? "",
);

const entityGroups = computed(() => {
    const groups = {};
    for (const e of props.recap.entities ?? []) (groups[e.type] ??= []).push(e);
    return TYPE_ORDER.filter((t) => groups[t]?.length).map((t) => ({
        type: t,
        label: TYPE_LABELS[t] ?? t,
        items: groups[t],
    }));
});
</script>

<template>
    <div>
        <div
            v-if="recap.rating"
            class="mb-3 flex items-center gap-0.5"
            :title="`Rated ${recap.rating}/5`"
        >
            <span
                v-for="n in 5"
                :key="n"
                class="text-lg leading-none"
                :class="n <= recap.rating ? 'text-amber' : 'text-edge3'"
                >★</span
            >
        </div>

        <p
            v-if="recap.recap_short"
            class="mb-6 max-w-3xl text-sm leading-relaxed text-muted"
        >
            {{ recap.recap_short }}
        </p>

        <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
            <div class="space-y-5">
                <!-- Tabs (only shown when there's a transcript to switch to) -->
                <div
                    v-if="recap.transcript"
                    class="flex gap-4 border-b border-edge text-sm"
                >
                    <button
                        class="-mb-px border-b-2 px-1 pb-2"
                        :class="
                            mainTab === 'analysis'
                                ? 'border-amber text-bright'
                                : 'border-transparent text-muted hover:text-ink'
                        "
                        @click="mainTab = 'analysis'"
                    >
                        Analysis
                    </button>
                    <button
                        class="-mb-px border-b-2 px-1 pb-2"
                        :class="
                            mainTab === 'transcript'
                                ? 'border-amber text-bright'
                                : 'border-transparent text-muted hover:text-ink'
                        "
                        @click="mainTab = 'transcript'"
                    >
                        Transcript
                    </button>
                </div>

                <template v-if="mainTab === 'analysis'">
                    <!-- Recap -->
                    <section class="panel p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h2 class="font-display text-lg text-bright">
                                Recap
                            </h2>
                            <div class="flex gap-1 text-xs">
                                <button
                                    v-for="v in ['full', 'short', 'stylized']"
                                    :key="v"
                                    class="rounded px-2 py-0.5 capitalize"
                                    :class="
                                        variant === v
                                            ? 'bg-raised text-bright'
                                            : 'text-muted hover:text-ink'
                                    "
                                    @click="variant = v"
                                >
                                    {{ v }}
                                </button>
                            </div>
                        </div>
                        <div class="text-muted">
                            <Markdown v-if="recapText" :source="recapText" />
                            <span v-else>—</span>
                        </div>
                    </section>

                    <!-- Memorable moments -->
                    <section v-if="recap.moments?.length" class="panel p-4">
                        <h2 class="mb-3 font-display text-lg text-bright">
                            Memorable Moments
                            <span class="text-sm text-faint"
                                >({{ recap.moments.length }})</span
                            >
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
                                <p
                                    v-if="m.context"
                                    class="mt-1 text-xs text-faint"
                                >
                                    <Markdown :source="m.context" inline />
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Outline -->
                    <section v-if="recap.outline?.length" class="panel p-4">
                        <h2 class="mb-3 font-display text-lg text-bright">
                            Outline
                        </h2>
                        <ol class="space-y-2">
                            <li
                                v-for="(s, i) in recap.outline"
                                :key="i"
                                class="border-l-2 border-edge2 pl-3"
                            >
                                <div class="text-sm text-bright">
                                    {{ s.title }}
                                </div>
                                <div v-if="s.detail" class="text-sm text-muted">
                                    <Markdown :source="s.detail" inline />
                                </div>
                            </li>
                        </ol>
                    </section>

                    <!-- Next steps -->
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
                </template>

                <!-- Transcript -->
                <section v-else class="panel p-4">
                    <h2 class="mb-2 font-display text-lg text-bright">
                        Transcript
                    </h2>
                    <pre
                        class="max-h-[70vh] overflow-y-auto whitespace-pre-wrap font-sans text-sm leading-relaxed text-muted"
                        >{{ recap.transcript || "—" }}</pre>
                </section>
            </div>

            <!-- Entity panels -->
            <aside v-if="entityGroups.length" class="space-y-4">
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
                            <a
                                v-if="e.url"
                                :href="e.url"
                                target="_blank"
                                rel="noopener"
                                class="text-sm text-bright hover:text-amber hover:underline"
                                >{{ e.name }} ↗</a
                            >
                            <div v-else class="text-sm text-bright">
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
            </aside>
        </div>
    </div>
</template>
