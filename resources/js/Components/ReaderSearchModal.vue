<script setup>
// Reader-side live search, shown as an overlay. Parents open it via the exposed open() method.
// Mirrors the GM WorldSearchModal but hits the public endpoint and only ever surfaces entries the
// current viewer is allowed to see (the server scopes by visibility, scheduling and hide-from-search).
import { router } from "@inertiajs/vue3";
import { nextTick, ref } from "vue";

const props = defineProps({
    worldSlug: { type: String, required: true },
});

const isOpen = ref(false);
const q = ref("");
const groups = ref([]);
const loading = ref(false);
const input = ref(null);
let timer;

const open = async () => {
    isOpen.value = true;
    await nextTick();
    input.value?.focus();
};
const close = () => {
    isOpen.value = false;
    q.value = "";
    groups.value = [];
    clearTimeout(timer);
};

const onInput = () => {
    clearTimeout(timer);
    const term = q.value.trim();
    if (term.length < 2) {
        groups.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = setTimeout(async () => {
        try {
            const res = await window.axios.get(
                route("public.search", props.worldSlug),
                { params: { q: term } },
            );
            groups.value = res.data.groups;
        } finally {
            loading.value = false;
        }
    }, 200);
};

const go = (url) => {
    close();
    router.visit(url);
};

defineExpose({ open });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 bg-black/70"
            @click.self="close"
            @keydown.esc="close"
        >
            <div class="border-b border-edge2 bg-surface shadow-2xl">
                <div
                    class="mx-auto flex max-w-5xl items-center gap-3 px-6 py-4"
                >
                    <svg
                        class="h-5 w-5 shrink-0 text-muted"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                    <input
                        ref="input"
                        v-model="q"
                        type="text"
                        placeholder="Search this world…"
                        class="flex-1 border-0 bg-transparent text-lg text-bright placeholder:text-faint focus:outline-none focus:ring-0"
                        @input="onInput"
                        @keydown.esc="close"
                    />
                    <button
                        class="shrink-0 text-faint hover:text-ink"
                        title="Close (Esc)"
                        @click="close"
                    >
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div
                    v-if="q.trim().length >= 2"
                    class="mx-auto max-w-5xl px-6 pb-6"
                >
                    <div
                        v-if="groups.length"
                        class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div v-for="g in groups" :key="g.label">
                            <div class="mb-2 font-display text-lg text-bright">
                                {{ g.label }}
                            </div>
                            <ul class="flex flex-col gap-0.5">
                                <li v-for="(it, i) in g.items" :key="i">
                                    <button
                                        class="flex w-full items-baseline gap-2 rounded px-2 py-1.5 text-left text-sm text-muted hover:bg-raised hover:text-ink"
                                        @click="go(it.url)"
                                    >
                                        <span class="text-teal">→</span>
                                        <span class="min-w-0 flex-1 truncate">{{
                                            it.title
                                        }}</span>
                                        <span
                                            class="shrink-0 font-mono text-[10px] uppercase tracking-wide text-faint"
                                            >{{ it.sub }}</span
                                        >
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p
                        v-else-if="!loading"
                        class="py-6 text-center text-sm text-faint"
                    >
                        Nothing matches “{{ q.trim() }}”.
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
