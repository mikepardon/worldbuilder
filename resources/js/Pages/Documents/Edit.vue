<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import ArticleEditor from "@/Components/ArticleEditor.vue";
import BloodlineEditor from "@/Components/BloodlineEditor.vue";
import BrewEditor from "@/Components/BrewEditor.vue";
import FieldEditor from "@/Components/FieldEditor.vue";
import TimelineEditor from "@/Components/TimelineEditor.vue";
import { Head } from "@inertiajs/vue3";

defineProps({
    world: Object,
    campaign: Object,
    document: Object,
    fields: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    isFormKind: Boolean,
    isArticleKind: Boolean,
    isTimeline: Boolean,
    isBloodline: Boolean,
    entries: { type: Array, default: () => [] },
    compendium: { type: Array, default: () => [] },
    characters: { type: Array, default: () => [] },
    embeds: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
    links: { type: Array, default: () => [] },
    backlinks: { type: Array, default: () => [] },
    relationshipOptions: { type: Array, default: () => [] },
    access: {
        type: Object,
        default: () => ({ has_password: false, share_url: null }),
    },
    viewUrl: { type: String, default: null },
    graph: { type: Object, default: () => ({ nodes: [], edges: [] }) },
    allTags: { type: Array, default: () => [] },
    map: { type: Object, default: null },
});
</script>

<template>
    <Head :title="document.title" />

    <WorldLayout :world="world" flush>
        <ArticleEditor
            v-if="isArticleKind"
            :campaign="campaign"
            :document="document"
            :entries="entries"
            :compendium="compendium"
            :characters="characters"
            :embeds="embeds"
            :spells="spells"
            :links="links"
            :backlinks="backlinks"
            :relationship-options="relationshipOptions"
            :access="access"
            :graph="graph"
            :all-tags="allTags"
            :view-url="viewUrl"
            :templates="templates"
        />
        <FieldEditor
            v-else-if="isFormKind"
            :campaign="campaign"
            :document="document"
            :fields="fields"
            :entries="entries"
            :compendium="compendium"
            :embeds="embeds"
            :all-tags="allTags"
            :map="map"
            :view-url="viewUrl"
            :templates="templates"
            :access="access"
        />
        <TimelineEditor
            v-else-if="isTimeline"
            :campaign="campaign"
            :document="document"
            :entries="entries"
            :view-url="viewUrl"
        />
        <BloodlineEditor
            v-else-if="isBloodline"
            :campaign="campaign"
            :document="document"
            :entries="entries"
            :view-url="viewUrl"
        />
        <BrewEditor
            v-else
            :campaign="campaign"
            :document="document"
            :entries="entries"
            :compendium="compendium"
            :characters="characters"
            :embeds="embeds"
            :spells="spells"
            :all-tags="allTags"
            :view-url="viewUrl"
            :templates="templates"
            :access="access"
        />
    </WorldLayout>
</template>
