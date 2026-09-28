<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

defineProps({
    templates: { type: Array, default: () => [] },
});

const form = useForm({ name: '', description: '', starter: true });

const create = () => form.post(route('admin.rule-systems.store'), { onSuccess: () => form.reset() });
const destroy = (template) => {
    if (confirm(`Delete the “${template.name}” template?`)) {
        router.delete(route('admin.rule-systems.destroy', template.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Rule system templates · Admin" />

    <AdminLayout>
        <div class="mx-auto max-w-4xl p-6">
            <p class="eyebrow-muted">Admin</p>
            <h1 class="font-display text-2xl text-bright">Rule system templates</h1>
            <p class="mt-1 text-sm text-muted">
                Build starter TTRPG systems here. GMs can clone any template into their world and edit it freely.
            </p>

            <form class="panel mt-6 flex flex-wrap items-end gap-3 p-4" @submit.prevent="create">
                <label class="min-w-[200px] flex-1">
                    <span class="eyebrow-muted">New template name</span>
                    <input v-model="form.name" class="field mt-1" placeholder="e.g. Grim d100" required />
                </label>
                <label class="flex items-center gap-2 text-sm text-muted">
                    <input v-model="form.starter" type="checkbox" class="rounded border-edge3 bg-raised" />
                    Seed with the Ascendancy starter
                </label>
                <button class="btn-primary" :disabled="form.processing">Create</button>
            </form>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <div v-for="template in templates" :key="template.id" class="panel p-4">
                    <div class="flex items-start justify-between">
                        <Link :href="route('rule-systems.build', template.id)" class="font-display text-lg text-bright hover:text-amber">
                            {{ template.name }}
                        </Link>
                        <button class="text-xs text-faint hover:text-blood" @click="destroy(template)">Delete</button>
                    </div>
                    <p v-if="template.description" class="mt-1 line-clamp-2 text-sm text-muted">{{ template.description }}</p>
                    <p class="mt-3 font-mono text-[10px] uppercase tracking-wide text-faint">
                        {{ template.webs_count }} webs · {{ template.stats_count }} stats · {{ template.skills_count }} skills
                    </p>
                </div>
                <p v-if="!templates.length" class="text-sm text-faint">No templates yet — create one above.</p>
            </div>
        </div>
    </AdminLayout>
</template>
