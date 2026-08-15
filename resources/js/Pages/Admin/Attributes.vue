<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    attributes: Array,
    kindOptions: Array,
    typeOptions: Array,
});

const editingId = ref(null);
const showForm = ref(false);

const form = useForm({
    label: '', key: '', type: 'text', options: [], kinds: [], ref_kinds: [],
    required: false, visible: true, help: '', placeholder: '', sort_order: 0,
});

// Bind the newline-per-option textarea to the options array.
const optionsText = computed({
    get: () => (form.options ?? []).join('\n'),
    set: (v) => { form.options = v.split('\n').map((s) => s.trim()).filter(Boolean); },
});

const openNew = () => {
    editingId.value = null;
    form.defaults({ label: '', key: '', type: 'text', options: [], kinds: [], ref_kinds: [], required: false, visible: true, help: '', placeholder: '', sort_order: 0 });
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (a) => {
    editingId.value = a.id;
    form.clearErrors();
    Object.assign(form, {
        label: a.label, key: a.key, type: a.type,
        options: a.options ?? [], kinds: a.kinds ?? [], ref_kinds: a.ref_kinds ?? [],
        required: a.required, visible: a.visible, help: a.help ?? '', placeholder: a.placeholder ?? '', sort_order: a.sort_order ?? 0,
    });
    showForm.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editingId.value) form.put(route('admin.attributes.update', editingId.value), opts);
    else form.post(route('admin.attributes.store'), opts);
};

const remove = (a) => {
    if (confirm(`Delete the “${a.label}” attribute?`)) {
        router.delete(route('admin.attributes.destroy', a.id), { preserveScroll: true });
        if (editingId.value === a.id) showForm.value = false;
    }
};
</script>

<template>
    <Head title="Global attributes · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div>
                <div class="font-display text-[32px] leading-[1.05] text-bright">Global attributes</div>
                <div class="mt-1 max-w-2xl text-sm text-muted">Platform-wide custom fields, curated centrally and offered to every world for the document kinds you choose.</div>
            </div>
            <button class="btn-primary" @click="openNew">+ New attribute</button>
        </div>

        <!-- Editor -->
        <form v-if="showForm" class="panel grid gap-4 p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-faint">Label</label>
                    <input v-model="form.label" class="field" placeholder="Region" />
                    <p v-if="form.errors.label" class="mt-1 text-xs text-red-400">{{ form.errors.label }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-faint">Key <span class="text-faint">(optional)</span></label>
                    <input v-model="form.key" class="field font-mono" placeholder="auto from label" />
                    <p v-if="form.errors.key" class="mt-1 text-xs text-red-400">{{ form.errors.key }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-faint">Type</label>
                    <select v-model="form.type" class="field capitalize">
                        <option v-for="t in typeOptions" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-faint">Sort order</label>
                    <input v-model.number="form.sort_order" type="number" class="field" />
                </div>
            </div>

            <div v-if="form.type === 'select'">
                <label class="mb-1 block text-xs font-medium text-faint">Options <span class="text-faint">(one per line)</span></label>
                <textarea v-model="optionsText" rows="3" class="field font-mono" placeholder="Coastal&#10;Highland&#10;Underdark" />
            </div>

            <div v-if="form.type === 'reference'">
                <label class="mb-1 block text-xs font-medium text-faint">Can point to <span class="text-faint">(entry kinds; empty = any)</span></label>
                <div class="flex flex-wrap gap-2">
                    <label v-for="k in kindOptions" :key="k" class="flex items-center gap-1.5 rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted">
                        <input type="checkbox" :value="k" v-model="form.ref_kinds" class="rounded border-edge3 bg-raised text-amber focus:ring-0" />
                        <span class="capitalize">{{ k }}</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-faint">Applies to</label>
                <div class="flex flex-wrap gap-2">
                    <label v-for="k in kindOptions" :key="k" class="flex items-center gap-1.5 rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted">
                        <input type="checkbox" :value="k" v-model="form.kinds" class="rounded border-edge3 bg-raised text-amber focus:ring-0" />
                        <span class="capitalize">{{ k }}</span>
                    </label>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-faint">Placeholder</label>
                    <input v-model="form.placeholder" class="field" placeholder="Example value shown in the empty field." />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-faint">Helper text</label>
                    <input v-model="form.help" class="field" placeholder="Shown under the field in the editor." />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-5">
                <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" v-model="form.required" class="rounded border-edge3 bg-raised text-amber focus:ring-0" /> Required</label>
                <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" v-model="form.visible" class="rounded border-edge3 bg-raised text-amber focus:ring-0" /> Visible to worlds</label>
                <div class="ml-auto flex gap-2">
                    <button type="button" class="btn-ghost" @click="showForm = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="btn-primary">{{ editingId ? 'Save changes' : 'Create' }}</button>
                </div>
            </div>
        </form>

        <!-- List -->
        <div class="panel overflow-hidden">
            <table class="wb-table">
                <thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Applies to</th><th>Flags</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="a in attributes" :key="a.id">
                        <td class="text-ink">{{ a.label }}</td>
                        <td class="font-mono text-xs text-faint">{{ a.key }}</td>
                        <td class="capitalize text-muted">{{ a.type }}</td>
                        <td class="text-muted">{{ (a.kinds ?? []).join(', ') || '—' }}</td>
                        <td class="text-xs">
                            <span v-if="a.required" class="badge-gm mr-1">required</span>
                            <span :class="a.visible ? 'badge-players' : 'text-faint'">{{ a.visible ? 'visible' : 'hidden' }}</span>
                        </td>
                        <td class="text-right">
                            <button class="text-xs text-teal hover:underline" @click="openEdit(a)">Edit</button>
                            <button class="ml-3 text-xs text-muted hover:text-blood" @click="remove(a)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!attributes.length"><td colspan="6" class="py-10 text-center text-faint">No global attributes yet.</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
