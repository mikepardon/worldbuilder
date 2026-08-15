<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import CharacterSheet from "@/Components/CharacterSheet.vue";
import DicePopover from "@/Components/DicePopover.vue";
import Modal from "@/Components/Modal.vue";
import { useDiceRoller } from "@/composables/useDiceRoller";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    me: Object,
    worlds: { type: Array, default: () => [] },
    playerCampaigns: { type: Array, default: () => [] },
    characters: { type: Array, default: () => [] },
    hasSandbox: { type: Boolean, default: false },
    canCreateWorld: { type: Boolean, default: true },
});

const initial = (name) => (name || "?").trim().charAt(0).toUpperCase() || "?";

/* ---- personal characters (create blank, or import from D&D Beyond) ---- */
const showAddChar = ref(false);
const charForm = useForm({ name: "", ddb_url: "" });
const addCharacter = () =>
    charForm.post(route("characters.personal.store"), {
        preserveScroll: true,
        onSuccess: () => {
            showAddChar.value = false;
            charForm.reset();
        },
    });
const removeCharacter = (character) => {
    if (confirm(`Delete “${character.name}”?`)) {
        router.delete(route("characters.destroy", character.id), {
            preserveScroll: true,
        });
    }
};

/* ---- seeded sandbox world ---- */
const cloningSandbox = ref(false);
const cloneSandbox = () => {
    cloningSandbox.value = true;
    router.post(
        route("sandbox.clone"),
        {},
        { onFinish: () => (cloningSandbox.value = false) },
    );
};

/* ---- read-only character sheet popup (D&D Beyond characters) ---- */
// Track by id so an in-place refresh (which reloads props) keeps the open sheet pointed at fresh data.
const openId = ref(null);
const openChar = computed(() =>
    props.characters.find((c) => c.id === openId.value),
);
const openSheet = (character) => {
    if (!character.is_ddb) return;
    openId.value = character.id;
};
const closeSheet = () => (openId.value = null);

const refreshing = ref(false);
const refreshChar = () => {
    if (!openChar.value) return;
    refreshing.value = true;
    router.post(
        route("characters.refresh", openChar.value.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (refreshing.value = false),
        },
    );
};

// Local dice rolls from the sheet's .dice-roll spans — a popover only, never written anywhere.
const { roll, handleClick: rollDiceClick } = useDiceRoller();
</script>

<template>
    <Head title="Your dashboard" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-8 flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    {{ me.name }}
                </div>
                <div
                    class="font-display text-[32px] leading-[1.05] text-bright"
                >
                    Your dashboard
                </div>
            </div>

            <!-- Seeded demo world: explore it read-only, or copy it into your own campaign -->
            <section
                v-if="hasSandbox"
                class="panel mb-8 flex flex-col gap-3 border-amber/30 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div class="eyebrow-muted mb-1">New here?</div>
                    <div class="font-display text-lg text-bright">
                        Explore the seeded world
                    </div>
                    <p class="mt-1 max-w-xl text-sm text-muted">
                        Tour a complete demo campaign — Saltmere &amp; the
                        Sundered Coast — from a player's perspective, then copy
                        it into your own world to play with every GM tool, or
                        make it your live campaign.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <a
                        :href="route('sandbox.view')"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border border-edge3 px-3 py-2 text-sm text-ink hover:border-amber"
                    >
                        View seeded world
                    </a>
                    <button
                        type="button"
                        :disabled="cloningSandbox"
                        class="rounded-md bg-amber px-3 py-2 text-sm font-semibold text-black hover:bg-amber/90 disabled:opacity-50"
                        @click="cloneSandbox"
                    >
                        {{
                            cloningSandbox
                                ? "Copying…"
                                : "Create campaign from it"
                        }}
                    </button>
                </div>
            </section>

            <!-- Campaigns you play in (someone else's world) -->
            <section v-if="playerCampaigns.length" class="mb-8">
                <div class="eyebrow-muted mb-2">Campaigns you play in</div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="c in playerCampaigns"
                        :key="c.url"
                        class="panel p-4"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <a
                                :href="c.url"
                                class="min-w-0 truncate font-display text-lg text-bright hover:text-amber"
                                >{{ c.name }}</a
                            >
                            <span class="shrink-0 text-xs text-faint">{{
                                c.world
                            }}</span>
                        </div>
                        <ul
                            v-if="c.recaps.length"
                            class="mt-2 flex flex-col gap-1"
                        >
                            <li v-for="r in c.recaps" :key="r.url">
                                <a
                                    :href="r.url"
                                    class="flex items-baseline justify-between gap-2 text-sm text-muted hover:text-teal"
                                >
                                    <span class="min-w-0 truncate">{{
                                        r.title
                                    }}</span>
                                    <span
                                        v-if="r.held_on"
                                        class="shrink-0 font-mono text-[10px] text-faint"
                                        >{{ r.held_on }}</span
                                    >
                                </a>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-xs text-faint">
                            No recaps yet.
                        </p>
                        <a
                            :href="`${c.url}/sessions`"
                            class="mt-2 inline-block text-xs text-amber hover:underline"
                            >All sessions →</a
                        >
                    </div>
                </div>
            </section>

            <div class="grid gap-8 md:grid-cols-2">
                <!-- Worlds -->
                <section>
                    <div class="mb-2 flex items-center justify-between">
                        <div class="eyebrow-muted">Your worlds</div>
                        <Link
                            v-if="canCreateWorld"
                            :href="route('worlds.create')"
                            class="rounded-md border border-edge3 px-2.5 py-1 text-xs text-muted hover:border-amber hover:text-amber"
                        >
                            + New world
                        </Link>
                        <span
                            v-else
                            class="font-mono text-[10px] text-faint"
                            title="Free accounts may own one world"
                            >1 world limit — upgrade for more</span
                        >
                    </div>

                    <div class="flex flex-col gap-2">
                        <div
                            v-for="c in worlds"
                            :key="c.id"
                            class="panel flex items-center justify-between gap-3 px-4 py-3"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-sm text-ink">
                                    {{ c.name }}
                                </div>
                                <span
                                    class="font-mono text-[10px] uppercase tracking-wider"
                                    :class="
                                        c.can_manage
                                            ? 'text-amber'
                                            : 'text-faint'
                                    "
                                    >{{ c.can_manage ? "GM" : "Player" }}</span
                                >
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <template v-if="c.can_manage">
                                    <Link
                                        :href="route('worlds.show', c.id)"
                                        class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink hover:border-amber"
                                        >Edit world</Link
                                    >
                                    <a
                                        :href="`/w/${c.slug}`"
                                        class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                                        >Open</a
                                    >
                                </template>
                                <a
                                    v-else
                                    :href="`/w/${c.slug}`"
                                    class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink hover:border-amber"
                                    >Open</a
                                >
                            </div>
                        </div>
                        <p
                            v-if="!worlds.length"
                            class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                        >
                            You're not in any worlds yet.
                        </p>
                    </div>
                </section>

                <!-- Characters -->
                <section>
                    <div class="mb-2 flex items-center justify-between">
                        <div class="eyebrow-muted">Your characters</div>
                        <button
                            type="button"
                            class="rounded-md border border-edge3 px-2.5 py-1 text-xs text-muted hover:border-amber hover:text-amber"
                            @click="showAddChar = !showAddChar"
                        >
                            + Add character
                        </button>
                    </div>

                    <form
                        v-if="showAddChar"
                        class="panel mb-2 flex flex-col gap-2 p-3"
                        @submit.prevent="addCharacter"
                    >
                        <input
                            v-model="charForm.name"
                            class="field !py-2 text-sm"
                            placeholder="Character name"
                        />
                        <div class="flex items-center gap-2">
                            <input
                                v-model="charForm.ddb_url"
                                class="field flex-1 !py-2 text-sm"
                                placeholder="…or paste a D&D Beyond character link"
                            />
                            <button
                                type="submit"
                                class="btn-primary"
                                :disabled="
                                    charForm.processing ||
                                    (!charForm.name && !charForm.ddb_url)
                                "
                            >
                                {{ charForm.ddb_url ? "Import" : "Add" }}
                            </button>
                        </div>
                        <p
                            v-if="charForm.errors.ddb_url"
                            class="text-sm text-red-400"
                        >
                            {{ charForm.errors.ddb_url }}
                        </p>
                    </form>

                    <div class="flex flex-col gap-2">
                        <div
                            v-for="c in characters"
                            :key="c.id"
                            class="panel flex items-center gap-3 px-4 py-3"
                            :class="
                                c.is_ddb
                                    ? 'cursor-pointer transition hover:border-teal/50'
                                    : ''
                            "
                            :role="c.is_ddb ? 'button' : undefined"
                            :tabindex="c.is_ddb ? 0 : undefined"
                            @click="openSheet(c)"
                            @keydown.enter="openSheet(c)"
                        >
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md bg-raised bg-cover bg-center font-display text-teal ring-1 ring-edge2"
                                :style="{
                                    backgroundImage: c.image_url
                                        ? `url(${c.image_url})`
                                        : 'none',
                                }"
                            >
                                <span v-if="!c.image_url">{{
                                    initial(c.name)
                                }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm text-ink">
                                    {{ c.name }}
                                </div>
                                <div class="font-mono text-[11px] text-faint">
                                    {{
                                        [
                                            c.level ? "Lv " + c.level : "",
                                            c.class,
                                        ]
                                            .filter(Boolean)
                                            .join(" · ")
                                    }}
                                    <span v-if="c.campaign"
                                        >· {{ c.campaign }}</span
                                    >
                                    <span v-else class="text-teal"
                                        >· Personal</span
                                    >
                                </div>
                            </div>
                            <span
                                v-if="c.is_ddb"
                                class="shrink-0 font-mono text-[9px] uppercase tracking-wider text-faint"
                                >Sheet →</span
                            >
                            <button
                                class="shrink-0 text-faint hover:text-red-400"
                                title="Delete character"
                                @click.stop="removeCharacter(c)"
                            >
                                ✕
                            </button>
                        </div>
                        <p
                            v-if="!characters.length"
                            class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                        >
                            No characters yet — add or import one above.
                        </p>
                    </div>
                </section>
            </div>
        </div>

        <!-- Read-only character sheet (D&D Beyond). No editing — view and re-sync only. -->
        <Modal :show="!!openChar" max-width="2xl" @close="closeSheet">
            <div v-if="openChar" class="bg-card">
                <div
                    class="flex items-center justify-between gap-3 border-b border-edge2 px-4 py-3"
                >
                    <div
                        class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
                    >
                        Character sheet · read only
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-ink hover:border-teal hover:text-teal disabled:opacity-50"
                            :disabled="refreshing"
                            @click="refreshChar"
                        >
                            {{
                                refreshing
                                    ? "Refreshing…"
                                    : "Refresh from D&D Beyond"
                            }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-2 py-1.5 text-muted hover:text-ink"
                            title="Close"
                            @click="closeSheet"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div
                    class="max-h-[75vh] overflow-y-auto p-4"
                    @click="rollDiceClick"
                >
                    <CharacterSheet
                        :name="openChar.name"
                        :image="openChar.image_url || ''"
                        :ac="openChar.ac"
                        :hp="openChar.hp"
                        :max-hp="openChar.max_hp"
                        :character="{
                            level: openChar.level,
                            class: openChar.class,
                            race: openChar.race,
                            stats: openChar.stats,
                            sheet: openChar.sheet,
                        }"
                        :can-edit="false"
                    />
                    <DicePopover :roll="roll" />
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
