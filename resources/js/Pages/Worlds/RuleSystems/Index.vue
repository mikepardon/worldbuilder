<script setup>
import WorldLayout from '@/Layouts/WorldLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    world: { type: Object, required: true },
    systems: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
});

const createForm = useForm({ name: '', starter: false });
const create = () =>
    createForm.post(route('rule-systems.store', props.world.id), { onSuccess: () => createForm.reset() });

const cloneTemplate = (template) =>
    router.post(route('rule-systems.clone', [props.world.id, template.id]), {}, { preserveScroll: true });

const destroy = (system) => {
    if (confirm(`Delete “${system.name}”? Characters built on it will lose their build.`)) {
        router.delete(route('rule-systems.destroy', system.id), { preserveScroll: true });
    }
};

const assign = (campaign, event) =>
    router.put(
        route('campaigns.rule-system', campaign.id),
        { rule_system_id: event.target.value || null },
        { preserveScroll: true },
    );
</script>

<template>
    <Head :title="`Rule systems · ${world.name}`" />

    <WorldLayout :world="world">
        <div class="mx-auto max-w-4xl p-6">
            <p class="eyebrow-muted">World</p>
            <h1 class="font-display text-2xl text-bright">Rule systems</h1>
            <p class="mt-1 text-sm text-muted">
                Build your own TTRPG system — stats, skills, spells and talent webs — or clone a ready-made template and make it yours.
            </p>

            <!-- Your systems -->
            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <div v-for="system in systems" :key="system.id" class="panel p-4">
                    <div class="flex items-start justify-between">
                        <Link :href="route('rule-systems.build', system.id)" class="font-display text-lg text-bright hover:text-amber">
                            {{ system.name }}
                        </Link>
                        <button class="text-xs text-faint hover:text-blood" @click="destroy(system)">Delete</button>
                    </div>
                    <p v-if="system.description" class="mt-1 line-clamp-2 text-sm text-muted">{{ system.description }}</p>
                    <p class="mt-3 font-mono text-[10px] uppercase tracking-wide text-faint">
                        {{ system.webs_count }} webs · {{ system.stats_count }} stats · {{ system.skills_count }} skills
                    </p>
                </div>
                <p v-if="!systems.length" class="text-sm text-faint">No systems yet — create one or clone a template below.</p>
            </div>

            <!-- Create -->
            <form class="panel mt-6 flex flex-wrap items-end gap-3 p-4" @submit.prevent="create">
                <label class="min-w-[200px] flex-1">
                    <span class="eyebrow-muted">New system name</span>
                    <input v-model="createForm.name" class="field mt-1" placeholder="e.g. Ashfall Homebrew" required />
                </label>
                <label class="flex items-center gap-2 text-sm text-muted">
                    <input v-model="createForm.starter" type="checkbox" class="rounded border-edge3 bg-raised" />
                    Start from the Ascendancy starter
                </label>
                <button class="btn-primary" :disabled="createForm.processing">Create</button>
            </form>

            <!-- Templates -->
            <div v-if="templates.length" class="mt-8">
                <h2 class="font-display text-lg text-bright">Clone a template</h2>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <div v-for="template in templates" :key="template.id" class="panel flex items-center justify-between p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-ink">{{ template.name }}</p>
                            <p v-if="template.description" class="truncate text-xs text-faint">{{ template.description }}</p>
                        </div>
                        <button class="btn-ghost text-xs" @click="cloneTemplate(template)">Clone</button>
                    </div>
                </div>
            </div>

            <!-- Enable on campaigns -->
            <div v-if="campaigns.length" class="mt-8">
                <h2 class="font-display text-lg text-bright">Enable on a campaign</h2>
                <p class="mt-1 text-sm text-muted">Players in a campaign build their characters on the system you choose here.</p>
                <div class="panel mt-2 divide-y divide-edge">
                    <div v-for="campaign in campaigns" :key="campaign.id" class="flex items-center justify-between p-3">
                        <span class="text-sm text-ink">{{ campaign.name }}</span>
                        <select class="field max-w-xs !py-1.5" :value="campaign.rule_system_id ?? ''" @change="assign(campaign, $event)">
                            <option value="">— none —</option>
                            <option v-for="system in systems" :key="system.id" :value="system.id">{{ system.name }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
