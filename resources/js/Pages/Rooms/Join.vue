<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, useForm } from "@inertiajs/vue3";

const props = defineProps({
    room: Object,
    canJoin: { type: Boolean, default: false },
});

const form = useForm({});
const join = () => form.post(route("rooms.join", props.room.code));
</script>

<template>
    <Head :title="`Join ${room.name}`" />

    <AuthenticatedLayout>
        <div
            class="mx-auto mt-16 max-w-md rounded-lg border border-edge2 bg-surface p-8 text-center"
        >
            <div
                class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
            >
                {{ room.campaign }}
            </div>
            <h1 class="mt-2 font-display text-[28px] text-bright">
                {{ room.name }}
            </h1>
            <template v-if="canJoin">
                <p class="mt-2 text-sm text-muted">
                    You've been invited to a live battle room. Join to add your
                    token and play.
                </p>
                <button
                    class="btn-primary mt-6 w-full justify-center"
                    :disabled="form.processing"
                    @click="join"
                >
                    {{ form.processing ? "Joining…" : "Join room" }}
                </button>
            </template>
            <p v-else class="mt-4 text-sm text-muted">
                This room is for players of
                <b class="text-ink">{{ room.campaign }}</b
                >. Ask your GM to add you to the world, then use this link
                again.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
