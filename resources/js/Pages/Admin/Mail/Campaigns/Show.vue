<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    campaign: { type: Object, required: true },
    stats: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
});

const statusColour = (status) => {
    const map = {
        sent: 'text-emerald-400',
        failed: 'text-red-400',
        queued: 'text-amber-400',
        sending: 'text-amber-400',
    };
    return map[status] ?? 'text-faint';
};

const pct = (count, total) =>
    total > 0 ? `${Math.round((count / total) * 100)}%` : '—';
</script>

<template>
    <Head :title="`${campaign.subject} · Campaigns · Admin`" />

    <AdminLayout>
        <div class="flex items-start justify-between gap-4">
            <div>
                <Link :href="route('admin.mail.campaigns.index')" class="eyebrow-muted hover:text-ink">← Campaigns</Link>
                <h1 class="font-display text-2xl text-bright">{{ campaign.subject }}</h1>
                <div class="mt-1 flex items-center gap-3 text-sm text-muted">
                    <span :class="statusColour(campaign.status)" class="font-mono text-[11px]">{{ campaign.status }}</span>
                    <span v-if="campaign.template">via {{ campaign.template.display_name }}</span>
                    <span v-if="campaign.sent_at">{{ new Date(campaign.sent_at).toLocaleString() }}</span>
                    <span v-else-if="campaign.scheduled_at">Scheduled {{ new Date(campaign.scheduled_at).toLocaleString() }}</span>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="panel p-4 text-center">
                <div class="font-display text-3xl text-bright">{{ stats.total }}</div>
                <div class="mt-1 text-xs text-faint">Sent</div>
            </div>
            <div class="panel p-4 text-center">
                <div class="font-display text-3xl text-bright">{{ pct(stats.opened, stats.total) }}</div>
                <div class="mt-1 text-xs text-faint">Opened</div>
            </div>
            <div class="panel p-4 text-center">
                <div class="font-display text-3xl text-bright">{{ pct(stats.clicked, stats.total) }}</div>
                <div class="mt-1 text-xs text-faint">Clicked</div>
            </div>
            <div class="panel p-4 text-center">
                <div class="font-display text-3xl text-bright">{{ pct(stats.bounced, stats.total) }}</div>
                <div class="mt-1 text-xs text-faint">Bounced</div>
            </div>
        </div>

        <!-- Per-recipient table -->
        <div v-if="messages.length" class="panel overflow-hidden">
            <p class="border-b border-edge2 px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-faint">Recipients</p>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-edge2 text-left">
                        <th class="px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-faint">Email</th>
                        <th class="px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-faint">Sent</th>
                        <th class="px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-faint">Opened</th>
                        <th class="px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-faint">Clicked</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="message in messages" :key="message.id" class="border-b border-edge2 last:border-0">
                        <td class="px-4 py-2.5 text-muted">{{ message.recipient_email }}</td>
                        <td class="px-4 py-2.5 text-xs text-faint">{{ message.sent_at ? new Date(message.sent_at).toLocaleString() : '—' }}</td>
                        <td class="px-4 py-2.5">
                            <span v-if="message.opened_at" class="text-xs text-emerald-400">✓</span>
                            <span v-else class="text-xs text-faint">—</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <span v-if="message.clicked_at" class="text-xs text-emerald-400">✓</span>
                            <span v-else class="text-xs text-faint">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="text-sm text-faint">No messages recorded yet.</p>
    </AdminLayout>
</template>
