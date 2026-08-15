<script setup>
import PublicLayout from "@/Layouts/PublicLayout.vue";
import RecapReader from "@/Components/RecapReader.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";

const props = defineProps({
    campaign: Object,
    sections: Array,
    selectedCampaign: Object,
    session: Object,
    recap: Object,
    viewer: { type: Object, default: () => ({}) },
    canNote: Boolean,
    notes: { type: Array, default: () => [] },
});

const noteForm = useForm({ body: "" });
const addNote = () =>
    noteForm.post(`/sessions/${props.session.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
const removeNote = (id) =>
    router.delete(`/session-notes/${id}`, { preserveScroll: true });
</script>

<template>
    <Head :title="`${session.title} — ${selectedCampaign.name}`" />

    <PublicLayout
        :campaign="campaign"
        :sections="sections"
        :viewer="viewer"
        active="campaigns"
    >
        <div class="mx-auto w-full max-w-6xl px-6 py-10">
            <Link
                :href="`/w/${campaign.slug}/campaigns/${selectedCampaign.slug}/sessions`"
                class="font-mono text-[10px] uppercase tracking-[0.2em] text-[#6fbfc4] hover:text-teal"
                >← {{ selectedCampaign.name }}</Link
            >
            <h1 class="mt-2 font-display text-4xl text-[#f5f1e8]">
                {{ session.title }}
            </h1>
            <div
                v-if="session.held_on"
                class="mb-6 mt-1 text-sm text-[#9aa0ab]"
            >
                Played {{ session.held_on }}
            </div>
            <div v-else class="mb-6"></div>

            <RecapReader :recap="recap" />

            <!-- My notes (players & GM only; private to the author) -->
            <section v-if="canNote" class="mt-8 max-w-3xl">
                <h2 class="mb-3 font-display text-xl text-[#f5f1e8]">
                    My notes
                </h2>
                <p class="mb-3 text-xs text-[#6b7180]">
                    Private to you — only you can see these.
                </p>

                <form class="mb-4" @submit.prevent="addNote">
                    <textarea
                        v-model="noteForm.body"
                        rows="3"
                        placeholder="Jot a note about this session…"
                        class="w-full rounded-lg border border-[#262a33] bg-[#181b21] p-3 text-sm text-ink placeholder:text-[#4a4f59] focus:border-[#6fbfc4] focus:outline-none"
                    ></textarea>
                    <div class="mt-2 flex justify-end">
                        <button
                            type="submit"
                            :disabled="noteForm.processing || !noteForm.body"
                            class="rounded border border-[#6fbfc4] px-3 py-1.5 text-sm text-[#6fbfc4] hover:bg-[#15252a] disabled:opacity-50"
                        >
                            {{ noteForm.processing ? "Saving…" : "Add note" }}
                        </button>
                    </div>
                </form>

                <ul class="space-y-2">
                    <li
                        v-for="note in notes"
                        :key="note.id"
                        class="rounded-lg border border-[#262a33] bg-[#181b21] p-3"
                    >
                        <p class="whitespace-pre-wrap text-sm text-[#c8ccd3]">
                            {{ note.body }}
                        </p>
                        <div
                            class="mt-2 flex items-center justify-between text-[11px] text-[#6b7180]"
                        >
                            <span>{{ note.when }}</span>
                            <button
                                class="hover:text-red-400"
                                @click="removeNote(note.id)"
                            >
                                Delete
                            </button>
                        </div>
                    </li>
                    <li v-if="!notes.length" class="text-xs text-[#6b7180]">
                        No notes yet.
                    </li>
                </ul>
            </section>
        </div>
    </PublicLayout>
</template>
