<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    campaigns: { type: Array, default: () => [] },
});

const statusColour = (status) => {
    const map = {
        draft: 'text-faint',
        queued: 'text-amber-400',
        scheduled: 'text-sky-400',
        sending: 'text-amber-400',
        sent: 'text-emerald-400',
        failed: 'text-red-400',
    };
    return map[status] ?? 'text-faint';
};
</script>

<template>
    <Head title="Campaigns · Admin" />

    <AdminLayout>
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="eyebrow-muted">Email</p>
                <h1 class="font-display text-2xl text-bright">Campaigns</h1>
            </div>
            <div class="flex gap-3">
                <Link :href="route('admin.mail.templates.index')" class="btn-secondary text-sm">Templates</Link>
                <Link :href="route('admin.mail.campaigns.create')" class="btn-primary text-sm">New campaign</Link>
            </div>
        </div>

        <div v-if="campaigns.length" class="panel overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-edge2 text-left">
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Subject</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Template</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Status</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Recipients</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="campaign in campaigns" :key="campaign.id" class="border-b border-edge2 last:border-0 hover:bg-raised/50">
                        <td class="px-4 py-3">
                            <Link :href="route('admin.mail.campaigns.show', campaign.id)" class="text-bright hover:text-amber">
                                {{ campaign.subject }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted">{{ campaign.template?.display_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span :class="statusColour(campaign.status)" class="font-mono text-[11px]">{{ campaign.status }}</span>
                        </td>
                        <td class="px-4 py-3 text-muted">{{ campaign.recipient_count ?? campaign.messages_count }}</td>
                        <td class="px-4 py-3 text-faint text-xs">{{ campaign.sent_at ? new Date(campaign.sent_at).toLocaleDateString() : campaign.scheduled_at ? `Scheduled ${new Date(campaign.scheduled_at).toLocaleDateString()}` : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="text-sm text-faint">No campaigns yet. <Link :href="route('admin.mail.campaigns.create')" class="text-amber hover:underline">Create one →</Link></p>
    </AdminLayout>
</template>
