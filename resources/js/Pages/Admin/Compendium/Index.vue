<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    total: Number,
    sources: Array,
    runs: Array,
});

const importing = ref([]);

const runImport = (source) => {
    importing.value.push(source.id);
    router.post(route('admin.compendium.import', source.id), {}, {
        preserveScroll: true,
        onFinish: () => { importing.value = importing.value.filter((id) => id !== source.id); },
    });
};

const when = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const statusCls = (s) => ({ complete: 'text-teal', running: 'text-amber', failed: 'text-red-400' })[s] ?? 'text-faint';
</script>

<template>
    <Head title="Compendium · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">Compendium library</div>
                <div class="mt-1 text-sm text-muted">{{ total }} SRD records stored. Imports pull from the Open5e SRD on demand.</div>
            </div>
        </div>

        <!-- Sources -->
        <div class="panel overflow-hidden">
            <table class="wb-table">
                <thead><tr><th>Source</th><th>Type</th><th>Records</th><th>Last run</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="s in sources" :key="s.id">
                        <td>
                            <Link :href="route('admin.compendium.show', s.id)" class="font-medium text-ink hover:text-amber">{{ s.name }}</Link>
                        </td>
                        <td class="text-muted">{{ s.item_type }}</td>
                        <td class="text-muted">{{ s.items_count }}</td>
                        <td class="text-faint">{{ when(s.last_run_at) }}</td>
                        <td class="text-right">
                            <button class="btn-ghost !py-1 !text-xs" :disabled="importing.includes(s.id)" @click="runImport(s)">
                                {{ importing.includes(s.id) ? 'Importing…' : (s.items_count ? 'Update' : 'Import') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Recent runs -->
        <div>
            <h3 class="mb-3 font-mono text-[9.5px] uppercase tracking-[0.16em] text-faint">Recent runs</h3>
            <div class="panel overflow-hidden">
                <table class="wb-table">
                    <thead><tr><th>Source</th><th>Status</th><th>Added</th><th>Updated</th><th>Unchanged</th><th>When</th></tr></thead>
                    <tbody>
                        <tr v-for="r in runs" :key="r.id">
                            <td class="text-ink">{{ r.source }}</td>
                            <td class="capitalize" :class="statusCls(r.status)">{{ r.status }}<span v-if="r.error" class="block text-xs text-faint">{{ r.error }}</span></td>
                            <td class="text-muted">{{ r.added }}</td>
                            <td class="text-muted">{{ r.updated }}</td>
                            <td class="text-faint">{{ r.unchanged }}</td>
                            <td class="text-faint">{{ when(r.finished_at || r.created_at) }}</td>
                        </tr>
                        <tr v-if="!runs.length"><td colspan="6" class="py-8 text-center text-faint">No imports run yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
