<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";

const props = defineProps({
    world: Object,
    events: { type: Array, default: () => [] },
    webhooks: { type: Array, default: () => [] },
});

const form = useForm({ url: "", events: [] });
const add = () =>
    form.post(route("worlds.webhooks.store", props.world.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });

const toggle = (hook) =>
    router.put(
        route("webhooks.update", hook.id),
        { url: hook.url, events: hook.events, is_active: !hook.is_active },
        { preserveScroll: true },
    );

const remove = (hook) => {
    if (confirm("Delete this webhook? Deliveries to it will stop.")) {
        router.delete(route("webhooks.destroy", hook.id), {
            preserveScroll: true,
        });
    }
};

const copy = (text) => navigator.clipboard?.writeText(text);
const eventLabel = (key) =>
    props.events.find((e) => e.key === key)?.label ?? key;
</script>

<template>
    <Head title="Webhooks" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <div>
                <h1 class="font-display text-[28px] text-bright">Webhooks</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted">
                    Send a signed <code class="text-faint">POST</code> to your
                    own endpoint when things happen in this world — wire it into
                    Zapier, Make, n8n or your own service. Each delivery is
                    signed with the webhook's secret in an
                    <code class="text-faint">X-Worldbuilder-Signature</code>
                    (<code class="text-faint">sha256=…</code> HMAC of the body).
                </p>
            </div>

            <!-- Add -->
            <form class="panel space-y-3 p-4" @submit.prevent="add">
                <label class="block">
                    <span class="mb-1 block text-xs text-faint"
                        >Payload URL</span
                    >
                    <input
                        v-model="form.url"
                        type="url"
                        class="field"
                        placeholder="https://example.com/hooks/worldbuilder"
                    />
                    <span
                        v-if="form.errors.url"
                        class="mt-1 block text-xs text-red-400"
                        >{{ form.errors.url }}</span
                    >
                </label>
                <div>
                    <span class="mb-1 block text-xs text-faint">Events</span>
                    <div class="flex flex-col gap-1.5">
                        <label
                            v-for="e in events"
                            :key="e.key"
                            class="flex items-center gap-2 text-sm text-muted"
                        >
                            <input
                                v-model="form.events"
                                type="checkbox"
                                :value="e.key"
                            />
                            {{ e.label }}
                            <code class="text-[11px] text-faint">{{
                                e.key
                            }}</code>
                        </label>
                    </div>
                    <span
                        v-if="form.errors.events"
                        class="mt-1 block text-xs text-red-400"
                        >{{ form.errors.events }}</span
                    >
                </div>
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="
                        form.processing || !form.url || !form.events.length
                    "
                >
                    Add webhook
                </button>
            </form>

            <!-- List -->
            <div v-if="webhooks.length" class="flex flex-col gap-3">
                <div
                    v-for="hook in webhooks"
                    :key="hook.id"
                    class="panel space-y-3 p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span
                                    class="inline-block h-2 w-2 shrink-0 rounded-full"
                                    :class="
                                        hook.is_active
                                            ? 'bg-teal'
                                            : 'bg-[#4a4f5a]'
                                    "
                                ></span>
                                <span
                                    class="truncate font-mono text-[13px] text-ink"
                                    :title="hook.url"
                                    >{{ hook.url }}</span
                                >
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <span
                                    v-for="key in hook.events"
                                    :key="key"
                                    class="rounded-full border border-edge3 px-2 py-0.5 font-mono text-[10px] text-muted"
                                    >{{ eventLabel(key) }}</span
                                >
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="rounded border border-edge3 px-2 py-1 text-[11px] text-muted hover:border-teal hover:text-teal"
                                @click="toggle(hook)"
                            >
                                {{ hook.is_active ? "Pause" : "Resume" }}
                            </button>
                            <button
                                type="button"
                                class="rounded border border-edge3 px-2 py-1 text-[11px] text-muted hover:border-red-500/40 hover:text-red-300"
                                @click="remove(hook)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-faint">Signing secret</span>
                        <code
                            class="truncate rounded bg-raised px-2 py-1 font-mono text-[11px] text-muted"
                            >{{ hook.secret }}</code
                        >
                        <button
                            type="button"
                            class="shrink-0 rounded border border-edge3 px-2 py-1 text-[11px] text-muted hover:border-teal hover:text-teal"
                            @click="copy(hook.secret)"
                        >
                            Copy
                        </button>
                    </div>
                </div>
            </div>
            <p
                v-else
                class="rounded-lg border border-dashed border-edge3 p-8 text-center text-sm text-muted"
            >
                No webhooks yet.
            </p>
        </div>
    </WorldLayout>
</template>
