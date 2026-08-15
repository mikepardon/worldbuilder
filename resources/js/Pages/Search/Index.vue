<script setup>
import WorldLayout from '@/Layouts/WorldLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    world: Object,
    campaign: Object,
    q: { type: String, default: '' },
    entries: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const query = ref(props.q);
const submit = () => {
    router.get(route('search.index', props.campaign.id), { q: query.value }, { preserveState: true, preserveScroll: true });
};

const total = () => props.entries.length + props.items.length;
</script>

<template>
    <Head title="Search" />

    <WorldLayout :world="world">
        <div class="flex flex-col gap-1.5">
            <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign.name }}</div>
            <div class="font-display text-[32px] leading-[1.05] text-bright">Search</div>
        </div>

        <form class="flex gap-2" @submit.prevent="submit">
            <input
                v-model="query" class="field flex-1" autofocus
                placeholder="Search entries and the compendium…"
            />
            <button type="submit" class="btn-primary">Search</button>
        </form>

        <p v-if="q" class="font-mono text-[11px] tracking-[0.08em] text-faint">
            {{ total() }} {{ total() === 1 ? 'result' : 'results' }} for “{{ q }}”
        </p>

        <!-- Entries -->
        <div v-if="entries.length" class="flex flex-col gap-2">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint">Entries ({{ entries.length }})</div>
            <Link
                v-for="e in entries" :key="'d' + e.id"
                :href="route('documents.edit', e.id)"
                class="panel flex items-center gap-3 px-4 py-3 transition hover:border-teal/40"
            >
                <div class="min-w-0 flex-1">
                    <div class="truncate text-ink">{{ e.title }}</div>
                    <div v-if="e.summary" class="truncate text-[13px] text-muted">{{ e.summary }}</div>
                </div>
                <span class="shrink-0 font-mono text-[10px] uppercase tracking-[0.08em] text-faint">{{ e.kindLabel }}</span>
                <span v-if="e.is_private" class="badge-gm shrink-0">GM only</span>
            </Link>
        </div>

        <!-- Compendium -->
        <div v-if="items.length" class="flex flex-col gap-2">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-faint">Compendium ({{ items.length }})</div>
            <Link
                v-for="i in items" :key="'c' + i.id"
                :href="route('compendium.edit', i.id)"
                class="panel flex items-center gap-3 px-4 py-3 transition hover:border-teal/40"
            >
                <div class="min-w-0 flex-1">
                    <div class="truncate text-ink">{{ i.name }}</div>
                    <div v-if="i.summary" class="truncate text-[13px] text-muted">{{ i.summary }}</div>
                </div>
                <span class="shrink-0 font-mono text-[10px] uppercase tracking-[0.08em] text-faint">{{ i.typeLabel }}</span>
                <span class="shrink-0 text-[11px] text-faint">{{ i.source }}</span>
            </Link>
        </div>

        <p v-if="q && !total()" class="text-sm text-faint">Nothing matches “{{ q }}”. Try a different term.</p>
        <p v-else-if="!q" class="text-sm text-faint">Search across every entry and compendium item in this world.</p>
    </WorldLayout>
</template>
