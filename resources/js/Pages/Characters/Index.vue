<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import UppyUploader from "@/Components/UppyUploader.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    isGm: Boolean,
    me: Object,
    characters: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    attachable: { type: Array, default: () => [] },
    ddbReady: { type: Boolean, default: false },
});

/* ---- attach / detach a personal character ---- */
const attachForm = useForm({ character_id: null });
const attach = () =>
    attachForm.post(route("characters.attach", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => attachForm.reset(),
    });
const detach = (c) => {
    if (
        confirm(
            `Remove ${c.name} from this campaign? It stays in your personal characters.`,
        )
    )
        router.put(
            route("characters.detach", c.id),
            {},
            { preserveScroll: true, onSuccess: () => (selected.value = null) },
        );
};

/* ---- pull the linked D&D Beyond campaign's roster ---- */
const pull = useForm({});
const pullDdb = () =>
    pull.post(route("characters.pull", props.campaign.id), {
        preserveScroll: true,
    });

const initial = (name) => (name || "?").trim().charAt(0).toUpperCase() || "?";
const subtitle = (c) =>
    [c.level ? `Level ${c.level}` : "", c.race, c.class]
        .filter(Boolean)
        .join(" · ");

/* ---- create / import ---- */
const add = useForm({ name: "", ddb_url: "", owner_user_id: null });
const submitAdd = () =>
    add.post(route("characters.store", props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => add.reset(),
    });

/* ---- edit ---- */
const ABILITIES = ["str", "dex", "con", "int", "wis", "cha"];
const abilityMod = (score) => {
    const value = Number(score);
    if (!Number.isFinite(value)) return "";
    const mod = Math.floor((value - 10) / 2);
    return `${mod >= 0 ? "+" : ""}${mod}`;
};
const selected = ref(null);
const openEdit = (c) => {
    selected.value = { ...c, stats: { ...(c.stats || {}) } };
};
const save = () => {
    const c = selected.value;
    router.put(
        route("characters.update", c.id),
        {
            name: c.name,
            level: c.level,
            class: c.class,
            race: c.race,
            speed: c.speed,
            passive_perception: c.passive_perception,
            ac: c.ac,
            hp: c.hp,
            max_hp: c.max_hp,
            stats: c.stats,
            notes: c.notes,
            ddb_url: c.ddb_url,
            owner_user_id: c.owner_id,
        },
        { preserveScroll: true, onSuccess: () => (selected.value = null) },
    );
};
const remove = (c) => {
    if (confirm(`Delete ${c.name}?`))
        router.delete(route("characters.destroy", c.id), {
            preserveScroll: true,
            onSuccess: () => (selected.value = null),
        });
};
const refresh = (c) =>
    router.post(
        route("characters.refresh", c.id),
        {},
        { preserveScroll: true },
    );

const portraitUppy = ref(null);
const openPortrait = () => portraitUppy.value?.open();
const onPortraitDone = () => router.reload({ preserveScroll: true });
</script>

<template>
    <Head title="Characters" />

    <WorldLayout :world="world">
        <div class="flex items-end justify-between gap-3">
            <div class="flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    {{ campaign.name }}
                </div>
                <div
                    class="font-display text-[32px] leading-[1.05] text-bright"
                >
                    Characters
                </div>
            </div>
            <button
                v-if="ddbReady"
                class="btn-ghost shrink-0"
                :disabled="pull.processing"
                @click="pullDdb"
            >
                {{ pull.processing ? "Syncing…" : "Sync from D&D Beyond" }}
            </button>
        </div>
        <p v-if="pull.errors.ddb" class="mt-2 text-sm text-red-400">
            {{ pull.errors.ddb }}
        </p>

        <div class="max-w-4xl">
            <p class="mb-4 text-sm text-muted">
                The party roster. Link a character to D&D Beyond to pull its
                portrait and stats. Characters can be @-mentioned in your lore
                and session notes, and dropped onto a battle room.
            </p>

            <!-- Add / import -->
            <form class="panel mb-6 p-4" @submit.prevent="submitAdd">
                <div class="eyebrow-muted mb-2">Add a character</div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="flex flex-1 flex-col gap-1 text-xs text-faint"
                        >Name
                        <input
                            v-model="add.name"
                            class="field"
                            placeholder="Aria Windborne"
                    /></label>
                    <label
                        class="flex flex-[2] flex-col gap-1 text-xs text-faint"
                        >D&D Beyond link (optional)
                        <input
                            v-model="add.ddb_url"
                            class="field"
                            placeholder="https://www.dndbeyond.com/characters/…"
                    /></label>
                    <label
                        v-if="isGm"
                        class="flex flex-col gap-1 text-xs text-faint"
                        >Owner
                        <select v-model="add.owner_user_id" class="field">
                            <option :value="null">— Unassigned —</option>
                            <option
                                v-for="p in members"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="add.processing"
                    >
                        {{ add.processing ? "Adding…" : "Add" }}
                    </button>
                </div>
                <p v-if="add.errors.ddb_url" class="mt-2 text-sm text-red-400">
                    {{ add.errors.ddb_url }}
                </p>
            </form>

            <!-- Attach one of your personal characters -->
            <form
                v-if="attachable.length"
                class="panel mb-6 p-4"
                @submit.prevent="attach"
            >
                <div class="eyebrow-muted mb-2">Add one of your characters</div>
                <div class="flex flex-wrap items-end gap-2">
                    <select
                        v-model="attachForm.character_id"
                        class="field flex-1"
                    >
                        <option :value="null">
                            — Choose a personal character —
                        </option>
                        <option
                            v-for="a in attachable"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.name }}
                        </option>
                    </select>
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="
                            attachForm.processing || !attachForm.character_id
                        "
                    >
                        Add to campaign
                    </button>
                </div>
            </form>

            <!-- Roster -->
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="c in characters"
                    :key="c.id"
                    class="panel flex items-start gap-3 p-4"
                >
                    <span
                        class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-raised bg-cover bg-center font-display text-lg text-teal ring-1 ring-edge2"
                        :style="{
                            backgroundImage: c.image_url
                                ? `url(${c.image_url})`
                                : 'none',
                        }"
                    >
                        <span v-if="!c.image_url">{{ initial(c.name) }}</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[15px] text-ink">
                            {{ c.name }}
                        </div>
                        <div class="font-mono text-[11px] text-faint">
                            {{ subtitle(c) || "No details yet" }}
                        </div>
                        <div
                            v-if="c.ddb_unreadable"
                            class="mt-1 rounded border border-amber/40 bg-amber/10 px-1.5 py-0.5 text-[11px] text-amber"
                            title="Ask this player to set their D&D Beyond character to public, or share it into the campaign, then sync again."
                        >
                            ⚠ Can't read from D&D Beyond
                        </div>
                        <div
                            class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 font-mono text-[11px] text-muted"
                        >
                            <span v-if="c.ac !== null">AC {{ c.ac }}</span>
                            <span v-if="c.max_hp"
                                >HP {{ c.hp ?? "?" }}/{{ c.max_hp }}</span
                            >
                            <span v-if="c.speed">{{ c.speed }} ft</span>
                            <span v-if="c.passive_perception"
                                >PP {{ c.passive_perception }}</span
                            >
                            <span v-if="c.owner_name" class="text-teal"
                                >· {{ c.owner_name }}</span
                            >
                            <a
                                v-if="c.ddb_url"
                                :href="c.ddb_url"
                                target="_blank"
                                rel="noopener"
                                class="text-amber hover:underline"
                                @click.stop
                                >D&D Beyond ↗</a
                            >
                        </div>
                    </div>
                    <button
                        v-if="c.can_edit"
                        class="shrink-0 text-sm text-faint hover:text-amber"
                        @click="openEdit(c)"
                    >
                        Edit
                    </button>
                </div>
                <p
                    v-if="!characters.length"
                    class="rounded-lg border border-dashed border-edge3 p-8 text-center text-sm text-muted sm:col-span-2"
                >
                    No characters yet. Add one above, or import from D&D Beyond.
                </p>
            </div>
        </div>

        <!-- Edit modal -->
        <div
            v-if="selected"
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/60 p-4 py-[6vh]"
            @click.self="selected = null"
        >
            <div
                class="w-full max-w-lg rounded-xl border border-edge2 bg-surface p-5 shadow-2xl"
            >
                <div class="mb-4 flex items-center justify-between">
                    <div class="font-display text-lg text-bright">
                        Edit character
                    </div>
                    <button
                        class="text-faint hover:text-ink"
                        @click="selected = null"
                    >
                        ✕
                    </button>
                </div>

                <div class="flex gap-3">
                    <button
                        type="button"
                        class="flex shrink-0 cursor-pointer flex-col items-center gap-1"
                        title="Change portrait"
                        @click="openPortrait"
                    >
                        <span
                            class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-md bg-raised bg-cover bg-center font-display text-teal ring-1 ring-edge2"
                            :style="{
                                backgroundImage: selected.image_url
                                    ? `url(${selected.image_url})`
                                    : 'none',
                            }"
                        >
                            <span v-if="!selected.image_url">{{
                                initial(selected.name)
                            }}</span>
                        </span>
                        <span class="text-[10px] text-faint">Portrait</span>
                    </button>
                    <UppyUploader
                        :key="selected.id"
                        ref="portraitUppy"
                        modal
                        :endpoint="route('characters.image', selected.id)"
                        :allowed-file-types="['image/*']"
                        :max-file-size-mb="50"
                        :max-number-of-files="1"
                        @complete="onPortraitDone"
                    />
                    <label class="flex flex-1 flex-col gap-1 text-xs text-faint"
                        >Name
                        <input
                            v-model="selected.name"
                            class="field !py-2 text-sm"
                        />
                        <div v-if="isGm" class="mt-2">
                            Owner
                            <select
                                v-model="selected.owner_id"
                                class="field !py-2 text-sm"
                            >
                                <option :value="null">— Unassigned —</option>
                                <option
                                    v-for="p in members"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}
                                </option>
                            </select>
                        </div>
                    </label>
                </div>

                <div class="mt-3 grid grid-cols-3 gap-2">
                    <label class="flex flex-col gap-1 text-xs text-faint"
                        >Level
                        <input
                            v-model="selected.level"
                            type="number"
                            class="field !py-2 text-sm"
                    /></label>
                    <label
                        class="col-span-2 flex flex-col gap-1 text-xs text-faint"
                        >Class
                        <input
                            v-model="selected.class"
                            class="field !py-2 text-sm"
                    /></label>
                    <label class="flex flex-col gap-1 text-xs text-faint"
                        >Race
                        <input
                            v-model="selected.race"
                            class="field !py-2 text-sm"
                    /></label>
                    <label class="flex flex-col gap-1 text-xs text-faint"
                        >AC
                        <input
                            v-model="selected.ac"
                            type="number"
                            class="field !py-2 text-sm"
                    /></label>
                    <label class="flex flex-col gap-1 text-xs text-faint"
                        >HP
                        <div class="flex items-center gap-1">
                            <input
                                v-model="selected.hp"
                                type="number"
                                class="field !py-2 text-sm"
                            /><span class="text-faint">/</span>
                            <input
                                v-model="selected.max_hp"
                                type="number"
                                class="field !py-2 text-sm"
                            />
                        </div>
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-faint"
                        >Speed (ft)
                        <input
                            v-model="selected.speed"
                            type="number"
                            class="field !py-2 text-sm"
                    /></label>
                    <label
                        class="col-span-2 flex flex-col gap-1 text-xs text-faint"
                        >Passive Perception
                        <input
                            v-model="selected.passive_perception"
                            type="number"
                            class="field !py-2 text-sm"
                    /></label>
                </div>

                <!-- Ability scores -->
                <div class="mt-3">
                    <div class="eyebrow-muted mb-1">Ability scores</div>
                    <div class="grid grid-cols-6 gap-1.5">
                        <label
                            v-for="a in ABILITIES"
                            :key="a"
                            class="flex flex-col items-center gap-1 text-[10px] uppercase tracking-wide text-faint"
                        >
                            {{ a }}
                            <input
                                v-model.number="selected.stats[a]"
                                type="number"
                                class="field !px-1 !py-1.5 text-center text-sm"
                            />
                            <span class="font-mono text-[10px] text-muted">{{
                                abilityMod(selected.stats[a])
                            }}</span>
                        </label>
                    </div>
                </div>

                <label class="mt-3 flex flex-col gap-1 text-xs text-faint"
                    >D&D Beyond link
                    <div class="flex items-center gap-2">
                        <input
                            v-model="selected.ddb_url"
                            class="field !py-2 text-sm"
                            placeholder="https://www.dndbeyond.com/characters/…"
                        />
                        <a
                            v-if="selected.ddb_url"
                            :href="selected.ddb_url"
                            target="_blank"
                            rel="noopener"
                            class="btn-ghost shrink-0 !px-3 !py-2 !text-xs"
                            >Open ↗</a
                        >
                        <button
                            v-if="selected.ddb_url"
                            type="button"
                            class="btn-ghost shrink-0 !px-3 !py-2 !text-xs"
                            @click="refresh(selected)"
                        >
                            Refresh
                        </button>
                    </div>
                </label>

                <label class="mt-3 flex flex-col gap-1 text-xs text-faint"
                    >Notes
                    <textarea
                        v-model="selected.notes"
                        rows="3"
                        class="field !py-2 text-sm"
                    ></textarea>
                </label>

                <div
                    class="mt-4 flex items-center justify-between border-t border-edge2 pt-3"
                >
                    <div class="flex gap-3">
                        <button
                            class="text-sm text-faint hover:text-red-400"
                            @click="remove(selected)"
                        >
                            Delete
                        </button>
                        <button
                            class="text-sm text-faint hover:text-ink"
                            title="Keep the character but remove it from this campaign"
                            @click="detach(selected)"
                        >
                            Remove from campaign
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <button
                            class="text-sm text-muted hover:text-ink"
                            @click="selected = null"
                        >
                            Cancel
                        </button>
                        <button class="btn-primary" @click="save">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
