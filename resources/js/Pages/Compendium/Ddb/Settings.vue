<script setup>
import WorldLayout from '@/Layouts/WorldLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    world: Object,
    campaign: Object,
    ddbCampaigns: { type: Array, default: () => [] },
});

const form = useForm({
    cobalt: '',
    ddb_campaign_id: props.campaign.ddb_campaign_id ?? '',
});

// Validate the pasted (or saved) token and list the GM's DDB campaigns. The page reloads with the
// campaign list flashed into the ddbCampaigns prop.
const fetchCampaigns = () => {
    form.post(route('ddb.campaigns', props.campaign.id), { preserveScroll: true });
};

const save = () => {
    form.put(route('ddb.settings.save', props.campaign.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="D&D Beyond · Keys" />

    <WorldLayout :world="world">
        <div class="flex flex-col gap-1.5">
            <div class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber">{{ campaign.name }}</div>
            <div class="font-display text-[32px] leading-[1.05] text-bright">D&D Beyond</div>
            <p class="max-w-2xl text-sm text-muted">
                Connect your D&D Beyond account so this world can import the content you own. Set up your
                key here once, then head to the import page whenever you want to pull content in.
            </p>
        </div>

        <form class="panel flex max-w-2xl flex-col gap-5 p-6" @submit.prevent="save">
            <!-- Cobalt token -->
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-medium text-faint">
                    Cobalt session token
                    <span v-if="campaign.ddb_saved" class="text-[#9dc47a]">· saved (leave blank to keep)</span>
                </label>
                <input
                    v-model="form.cobalt" type="password" autocomplete="off"
                    class="field font-mono text-[12.5px]"
                    :placeholder="campaign.ddb_saved ? '•••••••• (a token is already saved)' : 'Paste your CobaltSession value…'"
                />
                <p v-if="form.errors.cobalt" class="text-sm text-red-400">{{ form.errors.cobalt }}</p>
                <p class="text-[11px] leading-relaxed text-faint">
                    On <a href="https://www.dndbeyond.com" target="_blank" class="text-teal hover:underline">dndbeyond.com</a>
                    while logged in, open your browser's cookies for the site and copy the value of
                    <code class="rounded bg-raised px-1 font-mono text-[11px]">CobaltSession</code>.
                    Stored encrypted — only content your account owns is importable, and the token is never shared.
                </p>
            </div>

            <!-- Campaign selection -->
            <div class="flex flex-col gap-1.5 border-t border-edge2 pt-5">
                <label class="text-xs font-medium text-faint">Shared campaign (optional)</label>
                <p class="text-[11px] text-faint">
                    If content is shared with you through a D&D Beyond campaign, select it so that content is
                    included too. Fetch your campaigns first.
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <select v-model="form.ddb_campaign_id" class="field max-w-xs flex-1">
                        <option value="">— None —</option>
                        <option v-for="c in ddbCampaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                        <option
                            v-if="form.ddb_campaign_id && !ddbCampaigns.some((c) => c.id === form.ddb_campaign_id)"
                            :value="form.ddb_campaign_id"
                        >Saved campaign ({{ form.ddb_campaign_id }})</option>
                    </select>
                    <button
                        type="button" class="btn-ghost !py-2 text-sm"
                        :disabled="form.processing" @click="fetchCampaigns"
                    >Fetch my campaigns</button>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-edge2 pt-5">
                <Link :href="route('ddb.import', campaign.id)" class="text-sm text-teal hover:underline">Go to import →</Link>
                <button type="submit" class="btn-primary" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save settings' }}
                </button>
            </div>
        </form>
    </WorldLayout>
</template>
