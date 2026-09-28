<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    subscribers: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({ email: '', name: '' });
const add = () => form.post(route('admin.mail.subscribers.store'), { onSuccess: () => form.reset() });

const remove = (subscriber) => {
    if (confirm(`Remove ${subscriber.email} from all lists?`)) {
        router.delete(route('admin.mail.subscribers.destroy', subscriber.id), { preserveScroll: true });
    }
};

const q = ref('');
const shown = computed(() =>
    props.subscribers.filter((s) => {
        if (!q.value.trim()) return true;
        const needle = q.value.toLowerCase();
        return s.email.toLowerCase().includes(needle) || (s.name ?? '').toLowerCase().includes(needle);
    }),
);

const subscribedCount = computed(() => props.subscribers.filter((s) => s.subscribed).length);
</script>

<template>
    <Head title="Subscribers · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="eyebrow-muted">Email</p>
                <h1 class="font-display text-2xl text-bright">Subscribers</h1>
            </div>
            <div class="flex gap-3">
                <Link :href="route('admin.mail.templates.index')" class="btn-secondary text-sm">Templates</Link>
                <Link :href="route('admin.mail.campaigns.index')" class="btn-secondary text-sm">Campaigns</Link>
            </div>
        </div>

        <div v-if="flash.success" class="rounded-lg bg-emerald-900/30 px-4 py-3 text-sm text-emerald-400">{{ flash.success }}</div>

        <div class="flex flex-wrap items-end gap-3">
            <form class="panel flex flex-wrap items-end gap-3 p-4" @submit.prevent="add">
                <label>
                    <span class="eyebrow-muted">Email</span>
                    <input v-model="form.email" type="email" class="field mt-1" placeholder="user@example.com" required />
                    <p v-if="form.errors.email" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
                </label>
                <label>
                    <span class="eyebrow-muted">Name (optional)</span>
                    <input v-model="form.name" class="field mt-1" placeholder="Jane Smith" />
                </label>
                <button class="btn-primary" :disabled="form.processing">Add</button>
            </form>

            <input v-model="q" class="field ml-auto" placeholder="Search…" style="max-width: 200px" />
        </div>

        <p class="text-sm text-muted">
            {{ subscribedCount }} subscribed of {{ subscribers.length }} total
        </p>

        <div v-if="shown.length" class="panel overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-edge2 text-left">
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Email</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Name</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Status</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Added</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="subscriber in shown" :key="subscriber.id" class="border-b border-edge2 last:border-0 hover:bg-raised/50">
                        <td class="px-4 py-2.5 text-bright">{{ subscriber.email }}</td>
                        <td class="px-4 py-2.5 text-muted">{{ subscriber.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <span
                                class="font-mono text-[11px]"
                                :class="subscriber.subscribed ? 'text-emerald-400' : 'text-faint'"
                            >
                                {{ subscriber.subscribed ? 'subscribed' : 'unsubscribed' }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-faint">{{ subscriber.created_at }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <button class="text-xs text-faint hover:text-blood" @click="remove(subscriber)">Remove</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="text-sm text-faint">No subscribers match your search.</p>
    </AdminLayout>
</template>
