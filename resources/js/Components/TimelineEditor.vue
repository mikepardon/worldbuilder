<script setup>
import EntryPicker from "@/Components/EntryPicker.vue";
import { Link, router } from "@inertiajs/vue3";
import { reactive, ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    document: Object,
    entries: { type: Array, default: () => [] }, // linkable entries in this world
    viewUrl: { type: String, default: null },
});

// A timeline entry is one Age: its title is the Age name; its events live in data.events.
const form = reactive({
    title: props.document.title,
    summary: props.document.summary ?? "",
    is_private: props.document.is_private,
    span: props.document.data?.span ?? "",
    events: [...(props.document.data?.events ?? [])].map((event) => ({
        when: event.when ?? "",
        title: event.title ?? "",
        detail: event.detail ?? "",
        link: event.link ?? null,
    })),
    tags: [...(props.document.tags ?? [])],
});

const saved = ref(true);
let timer = null;
const save = () => {
    router.put(
        route("documents.update", props.document.id),
        {
            title: form.title,
            summary: form.summary,
            is_private: form.is_private,
            tags: form.tags,
            data: {
                ...(props.document.data || {}),
                span: form.span,
                events: form.events,
            },
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (saved.value = true),
        },
    );
};
watch(
    form,
    () => {
        saved.value = false;
        clearTimeout(timer);
        timer = setTimeout(save, 1000);
    },
    { deep: true },
);

const addEvent = () =>
    form.events.push({ when: "", title: "", detail: "", link: null });
const removeEvent = (index) => form.events.splice(index, 1);
const moveEvent = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= form.events.length) {
        return;
    }
    const list = form.events;
    [list[index], list[target]] = [list[target], list[index]];
};
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Editor top bar -->
        <div
            class="flex items-center gap-3 border-b border-edge bg-surface px-6 py-2.5"
        >
            <Link
                :href="route('worlds.show', campaign.id)"
                class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                >← Back</Link
            >
            <div class="font-mono text-[12px] text-faint">Age / timeline</div>
            <div class="ml-auto flex items-center gap-3">
                <span class="font-mono text-[11px] text-faint">{{
                    saved ? "✓ Saved" : "Saving…"
                }}</span>
                <a
                    v-if="viewUrl"
                    :href="viewUrl"
                    target="_blank"
                    class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted transition hover:border-teal hover:text-teal"
                    >View ↗</a
                >
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto w-full max-w-3xl space-y-6 px-6 py-8">
                <!-- Age identity -->
                <section class="space-y-3">
                    <label class="block">
                        <span class="mb-1 block text-xs text-faint"
                            >Age name</span
                        >
                        <input
                            v-model="form.title"
                            class="field font-display text-xl"
                            placeholder="The First Age"
                        />
                    </label>
                    <div class="grid gap-3 sm:grid-cols-[200px_1fr]">
                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Date span</span
                            >
                            <input
                                v-model="form.span"
                                class="field text-sm"
                                placeholder="~0 – ~1200 DR"
                            />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Summary</span
                            >
                            <input
                                v-model="form.summary"
                                class="field text-sm"
                                placeholder="What defines this age…"
                            />
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input v-model="form.is_private" type="checkbox" />
                        Private (GM only)
                    </label>
                </section>

                <!-- Events -->
                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="eyebrow-muted">Events</div>
                        <button class="btn-primary" @click="addEvent">
                            + Add event
                        </button>
                    </div>

                    <p
                        v-if="!form.events.length"
                        class="rounded-lg border border-dashed border-edge3 p-6 text-center text-sm text-muted"
                    >
                        No events yet. Add the moments that mark this age.
                    </p>

                    <div
                        v-for="(event, index) in form.events"
                        :key="index"
                        class="panel space-y-3 p-4"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                v-model="event.when"
                                class="field w-[140px] font-mono text-sm"
                                placeholder="1204 DR"
                            />
                            <input
                                v-model="event.title"
                                class="field flex-1 text-sm"
                                placeholder="The Sundering"
                            />
                            <div class="ml-auto flex items-center gap-1">
                                <button
                                    class="rounded border border-edge3 px-1.5 text-xs text-faint hover:text-ink"
                                    title="Move up"
                                    @click="moveEvent(index, -1)"
                                >
                                    ▲
                                </button>
                                <button
                                    class="rounded border border-edge3 px-1.5 text-xs text-faint hover:text-ink"
                                    title="Move down"
                                    @click="moveEvent(index, 1)"
                                >
                                    ▼
                                </button>
                                <button
                                    class="rounded border border-edge3 px-1.5 text-xs text-faint hover:text-blood"
                                    title="Remove"
                                    @click="removeEvent(index)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                        <textarea
                            v-model="event.detail"
                            rows="2"
                            class="field text-sm"
                            placeholder="What happened…"
                        />
                        <div>
                            <span class="mb-1 block text-xs text-faint"
                                >Linked entry (optional)</span
                            >
                            <EntryPicker
                                :model-value="event.link"
                                :entries="entries"
                                placeholder="Link a person, place, faction…"
                                @update:model-value="event.link = $event"
                            />
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
