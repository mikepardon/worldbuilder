<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

defineProps({
    templates: { type: Array, default: () => [] },
});

const form = useForm({ display_name: '' });

const create = () => form.post(route('admin.mail.templates.store'), { onSuccess: () => form.reset() });
const destroy = (template) => {
    if (confirm(`Delete "${template.display_name}"? This cannot be undone.`)) {
        router.delete(route('admin.mail.templates.destroy', template.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Email templates · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="eyebrow-muted">Email</p>
                <h1 class="font-display text-2xl text-bright">Templates</h1>
            </div>
            <div class="flex gap-3">
                <Link :href="route('admin.mail.subscribers.index')" class="btn-secondary text-sm">Subscribers</Link>
                <Link :href="route('admin.mail.campaigns.index')" class="btn-secondary text-sm">Campaigns</Link>
            </div>
        </div>

        <form class="panel flex flex-wrap items-end gap-3 p-4" @submit.prevent="create">
            <label class="min-w-[220px] flex-1">
                <span class="eyebrow-muted">New template</span>
                <input v-model="form.display_name" class="field mt-1" placeholder="e.g. Welcome email" required />
            </label>
            <button class="btn-primary" :disabled="form.processing">Create</button>
        </form>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="template in templates" :key="template.id" class="panel flex flex-col gap-3 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <Link :href="route('admin.mail.templates.edit', template.id)" class="font-display text-lg leading-tight text-bright hover:text-amber">
                            {{ template.display_name }}
                        </Link>
                        <p class="mt-0.5 font-mono text-[11px] text-faint">{{ template.reference }}</p>
                    </div>
                    <button class="shrink-0 text-xs text-faint hover:text-blood" @click="destroy(template)">Delete</button>
                </div>
                <p class="line-clamp-1 text-sm text-muted">{{ template.subject }}</p>
                <div class="mt-auto flex items-center justify-between">
                    <span
                        class="rounded-full px-2 py-0.5 font-mono text-[10px]"
                        :class="template.has_html ? 'bg-emerald-900/40 text-emerald-400' : 'bg-amber-900/40 text-amber-400'"
                    >
                        {{ template.has_html ? 'compiled' : 'no HTML' }}
                    </span>
                    <span class="text-xs text-faint">{{ template.updated_at }}</span>
                </div>
            </div>
            <p v-if="!templates.length" class="text-sm text-faint">No templates yet — create one above.</p>
        </div>
    </AdminLayout>
</template>
