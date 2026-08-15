<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    rooms: { type: Array, default: () => [] },
});

const form = useForm({ name: "" });
const copied = ref(null);

const joinUrl = (code) => `${window.location.origin}/rooms/join/${code}`;
const copyLink = (code) => {
    navigator.clipboard.writeText(joinUrl(code));
    copied.value = code;
    setTimeout(() => (copied.value = null), 1500);
};
</script>

<template>
    <Head title="Battle rooms" />

    <WorldLayout :world="world">
        <div class="flex flex-col gap-1.5">
            <div
                class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
            >
                {{ campaign.name }}
            </div>
            <div class="font-display text-[32px] leading-[1.05] text-bright">
                Battle rooms
            </div>
            <p class="max-w-2xl text-sm text-muted">
                Live tabletop rooms for combat. Create a room, share its join
                link, and players drop and move their own tokens while you run
                initiative.
            </p>
        </div>

        <form
            class="panel flex flex-wrap items-end gap-2 p-4"
            @submit.prevent="
                form.post(route('rooms.store', campaign.id), {
                    onSuccess: () => form.reset(),
                })
            "
        >
            <div class="flex-1">
                <label class="mb-1 block text-xs font-medium text-faint"
                    >New room</label
                >
                <input
                    v-model="form.name"
                    class="field"
                    placeholder="The Ambush at Saltmere"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">
                    {{ form.errors.name }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing || !form.name"
                class="btn-primary"
            >
                Create room
            </button>
        </form>

        <div v-if="rooms.length" class="flex flex-col gap-2">
            <div
                v-for="r in rooms"
                :key="r.id"
                class="panel flex flex-wrap items-center gap-3 p-4"
            >
                <div class="min-w-0 flex-1">
                    <Link
                        :href="route('rooms.show', [campaign.id, r.id])"
                        class="font-display text-lg text-bright hover:text-amber"
                        >{{ r.name }}</Link
                    >
                    <div class="font-mono text-[11px] text-faint">
                        {{ r.members_count }} players ·
                        {{ r.tokens_count }} tokens · code {{ r.code }}
                    </div>
                </div>
                <button
                    class="rounded-md border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-teal"
                    @click="copyLink(r.code)"
                >
                    {{ copied === r.code ? "Copied!" : "Copy join link" }}
                </button>
                <Link
                    :href="route('rooms.show', [campaign.id, r.id])"
                    class="btn-primary"
                    >Open</Link
                >
            </div>
        </div>
        <p v-else class="text-sm text-faint">
            No rooms yet. Create one above to run a live encounter.
        </p>
    </WorldLayout>
</template>
