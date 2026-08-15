<script setup>
import CompendiumEditor from '@/Components/CompendiumEditor.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    campaign: Object,
    item: Object,
    allTags: { type: Array, default: () => [] },
    races: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    fieldSchema: { type: Array, default: () => [] },
    ai: { type: Object, default: () => ({ configured: false, remaining: 0, limit: 0 }) },
});
</script>

<template>
    <Head :title="item.name" />

    <AuthenticatedLayout>
        <CompendiumEditor
            :item="item"
            :all-tags="allTags"
            :races="races"
            :spells="spells"
            :field-schema="fieldSchema"
            :ai="ai"
            :back-href="route('compendium.index', campaign.id)"
            back-label="Compendium"
            update-route="compendium.update"
            draft-route="compendium.draft"
            destroy-route="compendium.destroy"
            clone-route="compendium.clone"
            rebuild-route="compendium.rebuild"
        />
    </AuthenticatedLayout>
</template>
