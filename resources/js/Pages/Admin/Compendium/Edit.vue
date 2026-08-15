<script setup>
import CompendiumEditor from '@/Components/CompendiumEditor.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    item: Object,
    fieldSchema: { type: Array, default: () => [] },
    races: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    ai: { type: Object, default: () => ({ configured: false }) },
});

const backHref = props.item.source?.id
    ? route('admin.compendium.show', props.item.source.id)
    : route('admin.compendium.index');
const backLabel = props.item.source?.name ?? 'Compendium';
</script>

<template>
    <Head :title="`${item.name} · Admin`" />

    <AuthenticatedLayout>
        <CompendiumEditor
            :item="item"
            :field-schema="fieldSchema"
            :races="races"
            :spells="spells"
            :ai="ai"
            admin
            :back-href="backHref"
            :back-label="backLabel"
            update-route="admin.compendium.items.update"
            draft-route="admin.compendium.items.draft"
        />
    </AuthenticatedLayout>
</template>
