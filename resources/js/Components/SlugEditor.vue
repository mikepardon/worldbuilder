<script setup>
import { router } from "@inertiajs/vue3";
import { nextTick, ref } from "vue";

const props = defineProps({
    documentId: { type: [Number, String], required: true },
    slug: { type: String, default: "" },
});

const open = ref(false);
const value = ref(props.slug);
const error = ref("");
const saving = ref(false);
const input = ref(null);

const start = async () => {
    value.value = props.slug;
    error.value = "";
    open.value = true;
    await nextTick();
    input.value?.focus();
    input.value?.select();
};

const save = () => {
    saving.value = true;
    error.value = "";
    router.put(
        route("documents.slug", props.documentId),
        { slug: value.value },
        {
            // Keep the editor mounted so unsaved content isn't lost; props still refresh.
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                error.value = errors.slug || "Couldn't rename.";
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="relative flex-shrink-0">
        <button
            type="button"
            class="text-muted hover:text-amber"
            title="Edit URL"
            @click="start"
        >
            <svg
                viewBox="0 0 24 24"
                class="h-4 w-4"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path
                    d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"
                />
                <path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z" />
            </svg>
        </button>

        <div v-if="open" class="fixed inset-0 z-20" @click="open = false" />
        <div
            v-if="open"
            class="absolute left-0 top-full z-30 mt-1 w-72 rounded-lg border border-edge3 bg-surface p-3 shadow-lg"
        >
            <div
                class="mb-1 font-mono text-[10px] uppercase tracking-[0.16em] text-faint"
            >
                URL slug
            </div>
            <form class="flex items-center gap-2" @submit.prevent="save">
                <input
                    ref="input"
                    v-model="value"
                    class="field flex-1 text-sm"
                    placeholder="the-slug"
                    @keydown.esc="open = false"
                />
                <button
                    type="submit"
                    class="btn-primary shrink-0"
                    :disabled="saving"
                >
                    Save
                </button>
            </form>
            <p v-if="error" class="mt-1.5 text-[13px] text-red-400">
                {{ error }}
            </p>
            <p v-else class="mt-1.5 text-[11px] text-faint">
                Letters, numbers and hyphens. Changing this breaks old links.
            </p>
        </div>
    </div>
</template>
