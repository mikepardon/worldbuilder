<script setup>
// The reader's "My notes" widget (private per-entry notes with share/delete) plus any notes the reader
// has shared with the GM. Extracted so it can render both in the default sidebar and as a "notes" block.
import { router, useForm } from "@inertiajs/vue3";

const props = defineProps({
    entryId: { type: Number, required: true },
    myNotes: { type: Array, default: () => [] },
    sharedNotes: { type: Array, default: () => [] },
    // Only signed-in readers on a notes-enabled entry can write notes.
    canWrite: { type: Boolean, default: false },
});

const noteForm = useForm({ body: "" });
const addNote = () =>
    noteForm.post(route("notes.store", props.entryId), {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
const toggleShare = (id) =>
    router.patch(route("notes.share", id), {}, { preserveScroll: true });
const removeNote = (id) =>
    router.delete(route("notes.destroy", id), { preserveScroll: true });
</script>

<template>
    <div class="wb-notes flex flex-col gap-4">
        <!-- My notes -->
        <div
            v-if="canWrite"
            class="flex flex-col gap-2.5 rounded-lg border border-[#2f5457] bg-[#15252a] p-3.5"
        >
            <div class="flex items-center justify-between">
                <div
                    class="font-mono text-[10px] uppercase tracking-[0.16em] text-teal"
                >
                    My notes
                </div>
                <div
                    class="font-mono text-[10px] tracking-[0.1em] text-[#4e8f93]"
                >
                    {{ myNotes.length }}
                </div>
            </div>
            <div class="text-[13.5px] font-light leading-[1.5] text-[#8fb3b5]">
                Private to you unless you share. Kept per article.
            </div>

            <div
                v-for="n in myNotes"
                :key="n.id"
                class="flex flex-col gap-2 rounded-[7px] border border-[#2f5457] bg-[#122024] px-3 py-2.5"
            >
                <div
                    class="text-[15px] font-light leading-[1.6] text-[#e0e6e5]"
                >
                    {{ n.body }}
                </div>
                <div class="flex items-center gap-2.5">
                    <div
                        class="font-mono text-[10px] tracking-[0.08em] text-[#4e8f93]"
                    >
                        {{ n.when }}
                    </div>
                    <button
                        class="font-mono text-[10px] tracking-[0.08em]"
                        :class="n.shared ? 'text-teal' : 'text-[#4e8f93]'"
                        @click="toggleShare(n.id)"
                    >
                        {{ n.shared ? "SHARED WITH GM" : "SHARE" }}
                    </button>
                    <button
                        class="ml-auto text-[13px] text-[#4e8f93] hover:text-red-400"
                        @click="removeNote(n.id)"
                    >
                        Delete
                    </button>
                </div>
            </div>
            <div
                v-if="!myNotes.length"
                class="rounded-[7px] border border-dashed border-[#2f5457] p-3 text-[14px] font-light leading-[1.5] text-[#6f9799]"
            >
                No notes here yet. Write down what your character actually
                noticed.
            </div>

            <textarea
                v-model="noteForm.body"
                placeholder="e.g. she never blinked while the bell rang…"
                class="min-h-[74px] resize-y rounded-[7px] border border-[#2f5457] bg-[#101c20] px-3 py-2.5 text-[15px] font-light leading-[1.55] text-ink focus:border-teal focus:ring-0"
            ></textarea>
            <button
                :disabled="noteForm.processing || !noteForm.body.trim()"
                class="rounded-md bg-teal py-2 text-center font-display text-[15px] text-night transition hover:bg-[#8fd6da] disabled:opacity-50"
                @click="addNote"
            >
                Add note
            </button>
        </div>

        <!-- Shared with the GM (owner only) -->
        <div
            v-if="sharedNotes.length"
            class="flex flex-col gap-2.5 rounded-lg border border-edge2 bg-card p-3.5"
        >
            <div
                class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
            >
                Shared with the GM
            </div>
            <div
                v-for="n in sharedNotes"
                :key="n.id"
                class="flex flex-col gap-1 border-l-2 border-teal pl-3"
            >
                <div
                    class="text-[14.5px] font-light leading-[1.55] text-[#c8ccd3]"
                >
                    {{ n.body }}
                </div>
                <div
                    class="font-mono text-[10px] tracking-[0.08em] text-faint"
                >
                    {{ n.author }}
                </div>
            </div>
        </div>
    </div>
</template>
