<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    source: Object,
    items: Array,
});

const q = ref('');
const shown = computed(() => {
    if (!q.value.trim()) return props.items;
    const t = q.value.toLowerCase();
    return props.items.filter((i) => i.name.toLowerCase().includes(t) || (i.summary ?? '').toLowerCase().includes(t));
});
</script>

<template>
    <Head :title="`${source.name} · Admin`" />

    <AdminLayout>
        <div class="flex items-center gap-3">
            <Link :href="route('admin.compendium.index')" class="text-sm text-muted hover:text-amber">← Compendium</Link>
        </div>
        <div class="flex items-end justify-between gap-4">
            <div class="font-display text-[32px] leading-[1.05] text-bright">{{ source.name }}</div>
            <div class="font-mono text-[11px] text-faint">{{ shown.length }} of {{ items.length }} stored</div>
        </div>

        <input v-model="q" class="field max-w-[360px]" placeholder="Search stored records…" />

        <div class="panel overflow-hidden">
            <table class="wb-table">
                <thead><tr><th>Name</th><th>Details</th><th>Summary</th><th>v</th></tr></thead>
                <tbody>
                    <tr v-for="i in shown" :key="i.id" class="cursor-pointer hover:bg-raised" @click="router.get(route('admin.compendium.items.edit', i.id))">
                        <td class="font-medium text-ink hover:text-amber">{{ i.name }}</td>
                        <td class="font-mono text-xs text-muted">{{ i.meta }}</td>
                        <td class="max-w-[420px] truncate text-faint">{{ i.summary }}</td>
                        <td class="text-faint">{{ i.version }}</td>
                    </tr>
                    <tr v-if="!shown.length"><td colspan="4" class="py-10 text-center text-faint">Nothing stored yet — run an import.</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
