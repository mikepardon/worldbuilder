<script setup>
import WorldLayout from '@/Layouts/WorldLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    world: Object,
    campaign: Object,
    activeImport: { type: Object, default: undefined },
});

const importTypes = [
    { value: 'monster', label: 'Monsters' },
    { value: 'item', label: 'Items (magic items & gear)' },
    { value: 'spell', label: 'Spells' },
];

const form = useForm({ types: ['monster'], homebrew: true });

const toggleType = (value) => {
    form.types = form.types.includes(value)
        ? form.types.filter((t) => t !== value)
        : [...form.types, value];
};

const start = () => {
    form.post(route('ddb.import.start', props.campaign.id));
};

/* ---- live progress polling ---- */
const isRunning = computed(
    () => !!props.activeImport && (props.activeImport.status === 'queued' || props.activeImport.status === 'running'),
);

const statusLabel = computed(() => ({
    queued: 'Queued', running: 'Running', completed: 'Completed', failed: 'Failed',
}[props.activeImport?.status] ?? props.activeImport?.status));

const countEntries = computed(() => Object.entries(props.activeImport?.counts ?? {}));

let timer;
const poll = () => {
    if (!isRunning.value) return;
    router.reload({ only: ['activeImport'], preserveScroll: true });
};
const startPolling = () => { if (!timer) timer = setInterval(poll, 1500); };
const stopPolling = () => { clearInterval(timer); timer = undefined; };

onMounted(() => { if (isRunning.value) startPolling(); });
onBeforeUnmount(stopPolling);
watch(isRunning, (running) => (running ? startPolling() : stopPolling()));
</script>

<template>
    <Head title="D&D Beyond · Import" />

    <WorldLayout :world="world">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="flex flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign.name }}</div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">Import from D&D Beyond</div>
            </div>
            <div class="flex gap-2">
                <Link :href="route('ddb.settings', campaign.id)" class="btn-ghost">Keys &amp; settings</Link>
                <Link :href="route('compendium.index', campaign.id)" class="btn-ghost">Back to compendium</Link>
            </div>
        </div>

        <!-- No token yet -->
        <div v-if="!campaign.ddb_saved" class="panel flex items-center justify-between gap-4 border-l-2 border-l-amber p-5">
            <p class="text-sm text-muted">You need to save a D&D Beyond token before importing.</p>
            <Link :href="route('ddb.settings', campaign.id)" class="btn-primary">Set up keys</Link>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Start a new import -->
            <form class="panel flex flex-col gap-5 p-6" @submit.prevent="start">
                <div class="font-display text-[20px] text-bright">New import</div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-faint">What to import</label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="t in importTypes" :key="t.value" type="button"
                            class="rounded-md border px-3 py-1.5 text-sm transition"
                            :class="form.types.includes(t.value) ? 'border-amber/60 bg-amber/10 text-ink' : 'border-edge3 text-muted hover:text-ink'"
                            @click="toggleType(t.value)"
                        >{{ t.label }}</button>
                    </div>
                    <p v-if="form.errors.types" class="text-sm text-red-400">{{ form.errors.types }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-muted">
                    <input v-model="form.homebrew" type="checkbox" class="rounded border-edge3 bg-raised text-amber focus:ring-0" />
                    Include my homebrew monsters
                </label>

                <p class="text-[11px] leading-relaxed text-faint">
                    Imports run in the background and can take a few minutes for large accounts — images are
                    fetched once and shared, so re-imports are quicker. Entries arrive GM-only until you reveal them.
                </p>

                <button type="submit" class="btn-primary self-start" :disabled="form.processing || !form.types.length || !campaign.ddb_saved || isRunning">
                    {{ isRunning ? 'An import is running…' : (form.processing ? 'Starting…' : 'Start import') }}
                </button>
            </form>

            <!-- Progress -->
            <div v-if="activeImport" class="panel flex flex-col gap-4 p-6">
                <div class="flex items-center justify-between">
                    <div class="font-display text-[20px] text-bright">Latest import</div>
                    <span
                        class="rounded-full px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.1em]"
                        :class="{
                            'border border-edge3 text-faint': activeImport.status === 'queued',
                            'border border-[#3f5c2e] bg-[#1c2416] text-[#9dc47a]': activeImport.status === 'completed',
                            'border border-amber/50 bg-amber/10 text-amber': activeImport.status === 'running',
                            'border border-red-500/40 bg-red-500/10 text-red-400': activeImport.status === 'failed',
                        }"
                    >{{ statusLabel }}</span>
                </div>

                <p class="text-sm text-muted">{{ activeImport.message }}</p>

                <div v-if="activeImport.error" class="rounded-md border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-400">
                    {{ activeImport.error }}
                </div>

                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-md bg-raised p-3">
                        <div class="font-display text-[22px] text-bright">{{ activeImport.imported }}</div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.1em] text-faint">Entries</div>
                    </div>
                    <div class="rounded-md bg-raised p-3">
                        <div class="font-display text-[22px] text-bright">{{ activeImport.images }}</div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.1em] text-faint">Images</div>
                    </div>
                    <div class="rounded-md bg-raised p-3">
                        <div class="font-display text-[22px] text-bright">{{ countEntries.length }}</div>
                        <div class="font-mono text-[10px] uppercase tracking-[0.1em] text-faint">Types</div>
                    </div>
                </div>

                <div v-if="countEntries.length" class="flex flex-wrap gap-2">
                    <span v-for="[type, count] in countEntries" :key="type" class="rounded-full border border-edge3 px-2.5 py-0.5 text-xs text-muted">
                        {{ count }} {{ type }}
                    </span>
                </div>

                <!-- Running log -->
                <div v-if="activeImport.log?.length" class="max-h-52 overflow-y-auto rounded-md border border-edge2 bg-[#0f1115] p-3">
                    <div v-for="(entry, i) in activeImport.log" :key="i" class="font-mono text-[11px] leading-relaxed text-faint">
                        {{ entry.line }}
                    </div>
                </div>

                <Link
                    v-if="activeImport.status === 'completed'"
                    :href="route('compendium.index', campaign.id)"
                    class="btn-primary self-start"
                >View imported entries</Link>
            </div>
        </div>
    </WorldLayout>
</template>
