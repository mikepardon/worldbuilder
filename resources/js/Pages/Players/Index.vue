<script setup>
import WorldLayout from '@/Layouts/WorldLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    world: Object,
    campaign: Object,
    members: Array,
    invites: Array,
});

const form = useForm({ email: '', role: 'player' });
const invite = () => form.post(route('invites.store', props.campaign.id), { onSuccess: () => form.reset() });
const copyLink = () => navigator.clipboard.writeText(props.campaign.invite_url);
</script>

<template>
    <Head title="Players & invites" />

    <WorldLayout :world="world">
        <div class="flex flex-col gap-1.5">
            <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign.name }}</div>
            <div class="font-display text-[32px] leading-[1.05] text-bright">Players &amp; invites</div>
        </div>

        <div class="max-w-3xl">
            <p class="mb-4 text-sm text-muted">
                Players see the player-facing parts of your world — never your GM-only secrets.
            </p>

            <!-- Invite link -->
            <div class="panel mb-4 p-4">
                <div class="eyebrow-muted mb-2">Invite link</div>
                <p v-if="campaign.visibility === 'private'" class="text-sm text-muted">
                    This world is <b class="text-ink">private</b>. Set it to Public or Unlisted, or invite by email below.
                </p>
                <div v-else class="flex items-center gap-2">
                    <code class="flex-1 truncate rounded bg-raised px-3 py-2 font-mono text-xs text-muted">{{ campaign.invite_url }}</code>
                    <button class="btn-ghost !px-3 !py-2" @click="copyLink">Copy</button>
                </div>
            </div>

            <!-- Invite by email -->
            <div class="panel mb-6 p-4">
                <div class="eyebrow-muted mb-2">Invite by email</div>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="invite">
                    <input v-model="form.email" type="email" required placeholder="player@example.com" class="field min-w-[220px] flex-1" />
                    <button type="submit" :disabled="form.processing" class="btn-primary">
                        {{ form.processing ? 'Sending…' : 'Send invite' }}
                    </button>
                </form>
                <div v-if="invites.length" class="mt-3 flex flex-col gap-1.5">
                    <div v-for="i in invites" :key="i.id" class="flex items-center gap-3 rounded-md border border-dashed border-edge3 px-3 py-2">
                        <span class="flex-1 truncate text-sm text-ink">{{ i.email }}</span>
                        <span class="font-mono text-[10px] uppercase tracking-wider text-faint">pending</span>
                        <button class="text-faint hover:text-blood" @click="router.delete(route('invites.revoke', i.id))">✕</button>
                    </div>
                </div>
            </div>

            <!-- Members -->
            <div class="eyebrow-muted">{{ members.length }} {{ members.length === 1 ? 'player' : 'players' }}</div>
            <div class="mt-2 flex flex-col gap-2">
                <div v-for="m in members" :key="m.id" class="panel flex items-center gap-4 px-4 py-3">
                    <div class="flex size-8 items-center justify-center rounded-full bg-raised font-mono text-xs uppercase text-teal">
                        {{ m.email?.[0] ?? '?' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm text-ink">{{ m.name }} <span class="text-faint">· {{ m.email }}</span></div>
                        <div class="font-mono text-[11px] text-faint">{{ m.role }} · joined {{ m.joined_at }}</div>
                    </div>
                    <button class="text-sm text-faint hover:text-blood" @click="router.delete(route('members.remove', m.id))">Remove</button>
                </div>
                <p v-if="!members.length" class="rounded-lg border border-dashed border-edge3 p-8 text-center text-sm text-muted">
                    No one has joined yet.
                </p>
            </div>
        </div>
    </WorldLayout>
</template>
