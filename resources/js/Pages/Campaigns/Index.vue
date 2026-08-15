<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    world: Object,
    campaigns: { type: Array, default: () => [] },
});

const showCreate = ref(false);
const form = useForm({ name: "", description: "" });
const submit = () =>
    form.post(route("campaigns.store", props.world.id), {
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
</script>

<template>
    <Head :title="`Campaigns — ${world.name}`" />

    <WorldLayout :world="world">
        <div class="flex items-center justify-between">
            <div class="flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    {{ world.name }}
                </div>
                <div
                    class="font-display text-[30px] leading-[1.05] text-bright"
                >
                    Campaigns
                </div>
                <p class="max-w-2xl text-sm text-muted">
                    A campaign is a playthrough inside this world — its own
                    players, sessions and battle rooms, drawing on the world's
                    lore, compendium and maps.
                </p>
            </div>
            <button class="btn-primary" @click="showCreate = !showCreate">
                New campaign
            </button>
        </div>

        <form
            v-if="showCreate"
            class="panel flex flex-wrap items-end gap-2 p-4"
            @submit.prevent="submit"
        >
            <div class="flex-1">
                <label class="mb-1 block text-xs font-medium text-faint"
                    >Campaign name</label
                >
                <input
                    v-model="form.name"
                    class="field"
                    placeholder="The Salt Accord"
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
                Create
            </button>
            <button type="button" class="btn-ghost" @click="showCreate = false">
                Cancel
            </button>
        </form>

        <div class="grid gap-3 sm:grid-cols-2">
            <Link
                v-for="c in campaigns"
                :key="c.id"
                :href="route('campaigns.show', [world.id, c.id])"
                class="panel flex flex-col gap-2 p-4 transition hover:border-amber"
            >
                <div class="font-display text-lg text-bright">{{ c.name }}</div>
                <p v-if="c.description" class="line-clamp-2 text-sm text-muted">
                    {{ c.description }}
                </p>
                <div class="mt-auto font-mono text-[11px] text-faint">
                    {{ c.members_count }} players ·
                    {{ c.sessions_count }} sessions · {{ c.rooms_count }} rooms
                </div>
            </Link>
            <p
                v-if="!campaigns.length"
                class="rounded-lg border border-dashed border-edge3 p-8 text-center text-sm text-muted sm:col-span-2"
            >
                No campaigns yet. Create one to start play.
            </p>
        </div>
    </WorldLayout>
</template>
