<script setup>
import WorldLayout from '@/Layouts/WorldLayout.vue';
import { captureError } from '@/monitoring';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    world: Object,
    campaign: Object,
    types: Array,
    items: Array,
    allTags: { type: Array, default: () => [] },
    typeOptions: Array,
});

/* ---- Type + tag filters ---- */
const filter = ref('all');
const tagFilter = ref(null);
const visible = computed(() =>
    props.items.filter((i) => {
        if (filter.value !== 'all' && i.item_type !== filter.value) return false;
        if (tagFilter.value && !(i.tags ?? []).includes(tagFilter.value)) return false;
        return true;
    }),
);

/* ---- Add a custom entry ---- */
const showNew = ref(false);
const form = useForm({ name: '', item_type: 'monster' });
const create = () => form.post(route('compendium.store', props.campaign.id), { onSuccess: () => form.reset() });

/* ---- Import from the global library ---- */
const showImport = ref(false);
const lookup = reactive({ item_type: 'monster', query: '' });
const searching = ref(false);
const searched = ref(false);
const searchError = ref(null);
const results = ref([]);
const selected = ref([]);
const importing = ref(false);

const selectable = computed(() => results.value.filter((r) => !r.exists));
const allSelected = computed(() => selectable.value.length > 0 && selectable.value.every((r) => selected.value.includes(r.id)));

const openImport = () => {
    showImport.value = true;
    results.value = [];
    selected.value = [];
    searched.value = false;
    searchError.value = null;
    runSearch();
};

const runSearch = async () => {
    searching.value = true;
    searchError.value = null;
    selected.value = [];
    try {
        const url = new URL(route('compendium.browse', props.campaign.id), window.location.origin);
        url.searchParams.set('item_type', lookup.item_type);
        if (lookup.query.trim()) url.searchParams.set('query', lookup.query);
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) throw new Error('Lookup failed');
        results.value = (await res.json()).results ?? [];
        selected.value = results.value.filter((r) => !r.exists).map((r) => r.id);
    } catch (e) {
        captureError(e);
        searchError.value = 'The library lookup failed. Try again in a moment.';
        results.value = [];
    } finally {
        searching.value = false;
        searched.value = true;
    }
};

const toggle = (id) => {
    const i = selected.value.indexOf(id);
    if (i === -1) selected.value.push(id);
    else selected.value.splice(i, 1);
};

const toggleAll = () => {
    selected.value = allSelected.value ? [] : selectable.value.map((r) => r.id);
};

/* ---- Clone into an editable custom copy ---- */
const cloning = ref(null);
const cloneItem = (id) => {
    cloning.value = id;
    router.post(
        route('compendium.clone', id),
        {},
        { onFinish: () => { cloning.value = null; } },
    );
};

const importSelected = () => {
    if (!selected.value.length) return;
    importing.value = true;
    router.post(
        route('compendium.import', props.campaign.id),
        { ids: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => { showImport.value = false; },
            onFinish: () => { importing.value = false; },
        },
    );
};

/* ---- Import monsters from CritterDB (pasted JSON export, or a published-bestiary URL) ---- */
const showCritter = ref(false);
const critterMode = ref('paste');
const critterForm = useForm({ mode: 'paste', json: '', url: '' });

const openCritter = () => {
    critterForm.reset();
    critterForm.clearErrors();
    critterMode.value = 'paste';
    showCritter.value = true;
};

const setCritterMode = (mode) => {
    critterMode.value = mode;
    critterForm.clearErrors();
};

const submitCritter = () => {
    critterForm.mode = critterMode.value;
    critterForm.post(route('compendium.critterdb', props.campaign.id), {
        onSuccess: () => { showCritter.value = false; },
    });
};

</script>

<template>
    <Head title="Compendium" />

    <WorldLayout :world="world">
        <div class="flex flex-wrap items-end justify-between gap-x-5 gap-y-3">
            <div class="flex min-w-0 flex-col gap-1.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign.name }}</div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">Compendium</div>
            </div>
            <div class="flex flex-wrap gap-2.5">
                <button class="btn-ghost" @click="openImport">Import from library</button>
                <button class="btn-ghost" @click="openCritter">Import from CritterDB</button>
                <Link v-if="campaign.ddb_enabled" class="btn-ghost" :href="route('ddb.import', campaign.id)">Import from D&D Beyond</Link>
                <button class="btn-primary" @click="showNew = !showNew">+ Add entry</button>
            </div>
        </div>

        <p class="max-w-2xl text-sm text-muted">
            Your world's own spells, monsters, items and rules. Import ready-made entries from the global
            library, or write your own. Imported entries are read-only — clone one to make a custom, moddable copy.
        </p>

        <!-- Add custom -->
        <form v-if="showNew" class="panel flex flex-wrap items-end gap-2 p-4" @submit.prevent="create">
            <div class="flex-1">
                <label class="mb-1 block text-xs font-medium text-faint">Name</label>
                <input v-model="form.name" class="field" placeholder="Goblin Cutthroat" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-faint">Type</label>
                <select v-model="form.item_type" class="field">
                    <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>
            <button type="submit" :disabled="form.processing" class="btn-primary">Create</button>
        </form>

        <!-- Type tabs -->
        <div class="flex flex-wrap gap-1">
            <button class="rounded-md px-3 py-1 text-sm" :class="filter === 'all' ? 'bg-raised text-bright' : 'text-muted hover:text-ink'" @click="filter = 'all'">
                All ({{ items.length }})
            </button>
            <button
                v-for="t in types"
                :key="t.type"
                class="rounded-md px-3 py-1 text-sm"
                :class="filter === t.type ? 'bg-raised text-bright' : 'text-muted hover:text-ink'"
                @click="filter = t.type"
            >
                {{ t.plural }} ({{ t.count }})
            </button>
        </div>

        <!-- Tag filter -->
        <div v-if="allTags.length" class="flex flex-wrap items-center gap-1.5">
            <span class="font-mono text-[10px] uppercase tracking-[0.14em] text-faint">Tags</span>
            <button
                v-for="tag in allTags"
                :key="tag"
                class="rounded-full border px-2.5 py-0.5 text-xs transition"
                :class="tagFilter === tag ? 'border-teal bg-teal/10 text-teal' : 'border-edge2 text-muted hover:border-teal/50'"
                @click="tagFilter = tagFilter === tag ? null : tag"
            >
                {{ tag }}
            </button>
        </div>

        <div class="panel overflow-x-auto">
            <table class="wb-table">
                <thead>
                    <tr><th>Name</th><th>Type</th><th>Tags</th><th>Source</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="i in visible" :key="i.id" class="group cursor-pointer hover:bg-raised" @click="router.get(route('compendium.edit', i.id))">
                        <td class="font-medium text-ink">
                            <span class="flex items-center gap-3">
                                <img
                                    v-if="i.image_url" :src="i.image_url" :alt="i.name" loading="lazy"
                                    class="h-9 w-9 shrink-0 rounded-md object-cover ring-1 ring-edge2"
                                />
                                <span v-else class="h-9 w-9 shrink-0" aria-hidden="true"></span>
                                <span>{{ i.name }}</span>
                                <span v-if="i.locked" class="font-mono text-[10px] text-faint" title="Imported — read-only">🔒</span>
                            </span>
                        </td>
                        <td class="text-muted">{{ i.typeLabel }}</td>
                        <td>
                            <span v-for="tag in i.tags" :key="tag" class="mr-1 inline-block rounded-full bg-raised px-2 py-0.5 text-[11px] text-muted">{{ tag }}</span>
                        </td>
                        <td class="text-faint">{{ i.source }}</td>
                        <td>
                            <span v-if="!i.is_active" class="badge-gm">Inactive</span>
                            <span v-else :class="i.is_private ? 'badge-gm' : 'badge-players'">{{ i.is_private ? 'GM only' : 'Players' }}</span>
                        </td>
                        <td class="text-right">
                            <button
                                class="rounded-md border border-edge2 px-2.5 py-1 text-xs text-muted opacity-0 transition hover:border-teal/50 hover:text-teal group-hover:opacity-100 disabled:opacity-40"
                                :disabled="cloning === i.id"
                                :title="i.locked ? 'Clone into an editable custom copy' : 'Duplicate this entry'"
                                @click.stop="cloneItem(i.id)"
                            >
                                {{ cloning === i.id ? 'Cloning…' : 'Clone' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!visible.length"><td colspan="6" class="py-10 text-center text-faint">Nothing here yet. Add an entry to get started.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Import-from-library modal -->
        <div v-if="showImport" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 p-4 py-10" @click.self="showImport = false">
            <div class="flex w-full max-w-2xl flex-col gap-4 rounded-[12px] border border-edge2 bg-[#14161b] p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div class="font-display text-[22px] text-bright">Import from the global library</div>
                    <button class="text-faint hover:text-ink" @click="showImport = false">✕</button>
                </div>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="runSearch">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-faint">Type</label>
                        <select v-model="lookup.item_type" class="field" @change="runSearch">
                            <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="mb-1 block text-xs font-medium text-faint">Filter by name</label>
                        <input v-model="lookup.query" class="field" placeholder="goblin, fireball, longsword…" autofocus />
                    </div>
                    <button type="submit" :disabled="searching" class="btn-ghost">
                        {{ searching ? 'Searching…' : 'Search' }}
                    </button>
                </form>

                <p v-if="searchError" class="text-sm text-red-400">{{ searchError }}</p>

                <!-- Results -->
                <div v-if="results.length" class="flex flex-col gap-2">
                    <div class="flex items-center justify-between px-1">
                        <button class="font-mono text-[11px] uppercase tracking-[0.1em] text-teal hover:underline" @click="toggleAll">
                            {{ allSelected ? 'Clear all' : 'Select all' }}
                        </button>
                        <span class="font-mono text-[11px] text-faint">{{ selected.length }} selected · {{ results.length }} found</span>
                    </div>

                    <div class="max-h-[46vh] overflow-y-auto rounded-[8px] border border-edge2">
                        <label
                            v-for="r in results"
                            :key="r.id"
                            class="flex items-start gap-3 border-b border-edge px-3 py-2.5 last:border-0"
                            :class="r.exists ? 'opacity-45' : 'cursor-pointer hover:bg-[#1a1d24]'"
                        >
                            <input
                                type="checkbox"
                                class="mt-1 rounded border-edge3 bg-raised text-amber focus:ring-0"
                                :checked="selected.includes(r.id)"
                                :disabled="r.exists"
                                @change="toggle(r.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-display text-[15px] text-bright">{{ r.name }}</span>
                                    <span v-if="r.meta" class="rounded-full border border-edge3 bg-[#1e222a] px-2 py-0.5 font-mono text-[9.5px] tracking-[0.08em] text-[#b8bcc4]">{{ r.meta }}</span>
                                    <span v-if="r.exists" class="font-mono text-[9.5px] uppercase tracking-[0.1em] text-faint">in compendium</span>
                                </div>
                                <div v-if="r.summary" class="truncate text-[13px] font-light text-muted">{{ r.summary }}</div>
                            </div>
                        </label>
                    </div>
                </div>

                <p v-else-if="searched && !searching" class="rounded-[8px] border border-dashed border-edge3 px-4 py-8 text-center text-sm text-muted">
                    Nothing in the library for “{{ lookup.query || lookup.item_type }}”. An admin curates the global library.
                </p>

                <div class="flex justify-end gap-2 border-t border-edge2 pt-4">
                    <button class="btn-ghost" @click="showImport = false">Cancel</button>
                    <button class="btn-primary" :disabled="!selected.length || importing" @click="importSelected">
                        {{ importing ? 'Importing…' : `Import ${selected.length || ''}`.trim() }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Import-from-CritterDB modal -->
        <div v-if="showCritter" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 p-4 py-10" @click.self="showCritter = false">
            <form class="flex w-full max-w-2xl flex-col gap-4 rounded-[12px] border border-edge2 bg-[#14161b] p-6 shadow-2xl" @submit.prevent="submitCritter">
                <div class="flex items-center justify-between">
                    <div class="font-display text-[22px] text-bright">Import monsters from CritterDB</div>
                    <button type="button" class="text-faint hover:text-ink" @click="showCritter = false">✕</button>
                </div>

                <p class="text-sm text-muted">
                    Bring homebrew creatures in from <a href="https://critterdb.com" target="_blank" class="text-teal hover:underline">CritterDB</a>.
                    They import as editable custom monsters, GM-only until you reveal them.
                </p>

                <div class="flex gap-0.5 rounded-full border border-edge3 bg-[#1a1d24] p-[3px] text-center font-mono text-[10.5px] tracking-[0.1em]">
                    <button type="button" class="flex-1 rounded-full px-3 py-1.5" :class="critterMode === 'paste' ? 'bg-amber text-night' : 'text-muted'" @click="setCritterMode('paste')">PASTE JSON</button>
                    <button type="button" class="flex-1 rounded-full px-3 py-1.5" :class="critterMode === 'url' ? 'bg-amber text-night' : 'text-muted'" @click="setCritterMode('url')">FROM URL</button>
                </div>

                <div v-if="critterMode === 'paste'" class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-faint">
                        Paste a creature or bestiary JSON export. In CritterDB, open a bestiary and use <b class="text-ink">Export → JSON</b>.
                    </label>
                    <textarea v-model="critterForm.json" rows="9" class="field font-mono text-[12.5px]" placeholder='[{ "name": "Ampeel", "stats": { … } }]' />
                    <p v-if="critterForm.errors.json" class="text-sm text-red-400">{{ critterForm.errors.json }}</p>
                </div>

                <div v-else class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-faint">Published-bestiary link</label>
                    <input v-model="critterForm.url" class="field" placeholder="https://critterdb.com/#/publishedbestiary/view/…" />
                    <p class="text-[11px] text-faint">Only <b class="text-ink">published</b> (public) bestiaries can be fetched. CritterDB is often offline; if it fails, paste the JSON instead.</p>
                    <p v-if="critterForm.errors.url" class="text-sm text-red-400">{{ critterForm.errors.url }}</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-edge2 pt-4">
                    <button type="button" class="btn-ghost" @click="showCritter = false">Cancel</button>
                    <button type="submit" class="btn-primary" :disabled="critterForm.processing">
                        {{ critterForm.processing ? 'Importing…' : 'Import' }}
                    </button>
                </div>
            </form>
        </div>
    </WorldLayout>
</template>
