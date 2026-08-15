<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";

const props = defineProps({
    world: Object,
    campaign: Object,
    owner: Object,
    members: { type: Array, default: () => [] },
});

const ROLES = [
    { value: "editor", label: "Co-author" },
    { value: "moderator", label: "Moderator" },
];

const form = useForm({ email: "", role: "editor" });
const invite = () =>
    form.post(route("worlds.members.store", props.world.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });

const setRole = (member, role) =>
    router.put(
        route("worlds.members.update", [props.world.id, member.id]),
        { role },
        { preserveScroll: true },
    );

const remove = (member) => {
    if (!window.confirm(`Remove ${member.name || member.email}?`)) return;
    router.delete(
        route("worlds.members.destroy", [props.world.id, member.id]),
        {
            preserveScroll: true,
        },
    );
};

const initial = (name) => (name || "?").trim().charAt(0).toUpperCase() || "?";
</script>

<template>
    <Head title="Collaborators" />

    <WorldLayout :world="world">
        <div class="max-w-2xl">
            <div
                class="mb-1 font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
            >
                {{ campaign.name }}
            </div>
            <h1 class="font-display text-[28px] leading-[1.05] text-bright">
                Collaborators
            </h1>
            <p class="mt-2 text-sm text-muted">
                Invite other accounts to help with this world.
                <b class="text-ink">Co-authors</b> edit its lore (entries,
                compendium, maps). <b class="text-ink">Moderators</b> run its
                play (campaigns, sessions, rooms) but don't edit lore. Only the
                owner changes settings or removes collaborators.
            </p>

            <!-- Invite -->
            <form
                class="panel mt-6 flex flex-wrap items-end gap-3 p-4"
                @submit.prevent="invite"
            >
                <label class="min-w-[220px] flex-1">
                    <span class="mb-1 block text-sm text-muted"
                        >Invite by account email</span
                    >
                    <input
                        v-model="form.email"
                        type="email"
                        class="field"
                        placeholder="editor@example.com"
                        autocomplete="off"
                    />
                </label>
                <label>
                    <span class="mb-1 block text-sm text-muted">Role</span>
                    <select v-model="form.role" class="field !w-auto">
                        <option
                            v-for="r in ROLES"
                            :key="r.value"
                            :value="r.value"
                        >
                            {{ r.label }}
                        </option>
                    </select>
                </label>
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="form.processing || !form.email"
                >
                    Add
                </button>
            </form>
            <p v-if="form.errors.email" class="mt-1 text-sm text-red-400">
                {{ form.errors.email }}
            </p>

            <!-- People -->
            <div class="mt-6 flex flex-col gap-2">
                <div class="eyebrow-muted">People with access</div>

                <div class="panel flex items-center gap-3 px-4 py-3">
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-raised font-display text-amber ring-1 ring-edge2"
                    >
                        {{ initial(owner.name) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm text-ink">
                            {{ owner.name }}
                        </div>
                        <div class="truncate font-mono text-[11px] text-faint">
                            {{ owner.email }}
                        </div>
                    </div>
                    <span
                        class="font-mono text-[10px] uppercase tracking-wider text-amber"
                        >Owner</span
                    >
                </div>

                <div
                    v-for="m in members"
                    :key="m.id"
                    class="panel flex items-center gap-3 px-4 py-3"
                >
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-raised font-display text-teal ring-1 ring-edge2"
                    >
                        {{ initial(m.name) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm text-ink">
                            {{ m.name }}
                        </div>
                        <div class="truncate font-mono text-[11px] text-faint">
                            {{ m.email }}
                        </div>
                    </div>
                    <select
                        :value="m.role"
                        class="field !w-auto !py-1.5 text-sm"
                        :aria-label="`Role for ${m.name}`"
                        @change="setRole(m, $event.target.value)"
                    >
                        <option
                            v-for="r in ROLES"
                            :key="r.value"
                            :value="r.value"
                        >
                            {{ r.label }}
                        </option>
                    </select>
                    <button
                        class="shrink-0 text-faint hover:text-red-400"
                        title="Remove collaborator"
                        @click="remove(m)"
                    >
                        ✕
                    </button>
                </div>

                <p
                    v-if="!members.length"
                    class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                >
                    No co-authors yet. Invite someone by their account email
                    above.
                </p>
            </div>
        </div>
    </WorldLayout>
</template>
