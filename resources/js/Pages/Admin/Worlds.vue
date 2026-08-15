<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    worlds: Array,
    availableModels: { type: Array, default: () => [] },
    defaultModel: { type: String, default: '' },
});

/* ---- per-world AI model override ---- */
const setModel = (c, event) => {
    router.put(route('admin.worlds.ai-model', c.id), { ai_model: event.target.value || null }, { preserveScroll: true });
};

/* ---- AI budget editing ---- */
const editingBudget = ref(null); // campaign id being edited
const budgetValue = ref(0);
const savingBudget = ref(false);

const openBudget = (c) => {
    editingBudget.value = c.id;
    budgetValue.value = c.ai_generation_limit ?? 0;
};

const saveBudget = (c) => {
    savingBudget.value = true;
    router.put(route('admin.worlds.ai-budget', c.id), { ai_generation_limit: Number(budgetValue.value) || 0 }, {
        preserveScroll: true,
        onSuccess: () => { editingBudget.value = null; },
        onFinish: () => { savingBudget.value = false; },
    });
};

/* ---- D&D Beyond import access (per world) ---- */
const toggleDdb = (c) => {
    router.put(route('admin.worlds.ddb-access', c.id), { ddb_enabled: !c.ddb_enabled }, { preserveScroll: true });
};

const q = ref('');
const filter = ref('all');
const shown = computed(() =>
    props.worlds.filter((c) => {
        if (filter.value !== 'all' && c.visibility !== filter.value) return false;
        if (q.value.trim()) {
            const t = q.value.toLowerCase();
            return c.name.toLowerCase().includes(t) || (c.owner ?? '').toLowerCase().includes(t) || c.code.includes(t);
        }
        return true;
    }),
);
</script>

<template>
    <Head title="Worlds · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div class="font-display text-[32px] leading-[1.05] text-bright">Worlds</div>
            <div class="font-mono text-[11px] text-faint">{{ shown.length }} of {{ worlds.length }}</div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <input v-model="q" class="field max-w-[320px] flex-1" placeholder="Search name, owner or code…" />
            <div class="flex gap-1">
                <button v-for="f in [['all','All'],['public','Public'],['hidden','Unlisted'],['private','Private']]" :key="f[0]"
                    class="rounded-md px-3 py-1 text-sm" :class="filter === f[0] ? 'bg-raised text-bright' : 'text-muted hover:text-ink'"
                    @click="filter = f[0]">{{ f[1] }}</button>
            </div>
        </div>

        <div class="panel overflow-hidden">
            <table class="wb-table">
                <thead><tr><th>World</th><th>Owner</th><th>Visibility</th><th>Entries</th><th>AI budget</th><th>AI model</th><th>DDB import</th><th>Public link</th></tr></thead>
                <tbody>
                    <tr v-for="c in shown" :key="c.id">
                        <td class="text-ink">{{ c.name }}</td>
                        <td class="text-muted">{{ c.owner }}</td>
                        <td class="capitalize text-muted">{{ c.visibility }}</td>
                        <td class="text-muted">{{ c.documents_count }}</td>
                        <td>
                            <div v-if="editingBudget === c.id" class="flex items-center gap-1.5">
                                <input v-model.number="budgetValue" type="number" min="0" class="field !w-24 !py-1 text-sm" @keydown.enter="saveBudget(c)" />
                                <button class="btn-primary !py-1 !text-xs" :disabled="savingBudget" @click="saveBudget(c)">Save</button>
                                <button class="text-xs text-faint hover:text-ink" @click="editingBudget = null">✕</button>
                            </div>
                            <button v-else class="font-mono text-xs text-muted hover:text-amber" @click="openBudget(c)">
                                {{ c.ai_generations_used }} / {{ c.ai_generation_limit }} used
                            </button>
                        </td>
                        <td>
                            <select
                                class="field !w-40 !py-1 text-xs"
                                :value="c.ai_model ?? ''"
                                @change="setModel(c, $event)"
                            >
                                <option value="">Default ({{ defaultModel }})</option>
                                <option v-for="m in availableModels" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </td>
                        <td>
                            <button
                                class="rounded-full px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.1em] transition"
                                :class="c.ddb_enabled ? 'border border-[#3f5c2e] bg-[#1c2416] text-[#9dc47a]' : 'border border-edge3 text-faint hover:text-ink'"
                                @click="toggleDdb(c)"
                            >
                                {{ c.ddb_enabled ? 'Enabled' : 'Off' }}
                            </button>
                        </td>
                        <td>
                            <a v-if="c.public_url" :href="c.public_url" target="_blank" class="font-mono text-xs text-teal hover:underline">/w/{{ c.slug }}</a>
                            <span v-else class="text-faint">—</span>
                        </td>
                    </tr>
                    <tr v-if="!shown.length"><td colspan="8" class="py-10 text-center text-faint">No matching worlds.</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
