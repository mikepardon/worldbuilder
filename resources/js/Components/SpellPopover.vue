<script setup>
import { marked } from 'marked';
import { computed } from 'vue';

const props = defineProps({ spell: { type: Object, default: undefined } });

const fields = computed(() => props.spell?.fields ?? {});

// D&D-Beyond-style facet grid, in display order; only the ones with a value are shown.
const FACETS = [
    ['Level', 'level'],
    ['Casting Time', 'casting_time'],
    ['Range', 'range'],
    ['Components', 'components'],
    ['Duration', 'duration'],
    ['School', 'school'],
];
const facets = computed(() => FACETS.map(([label, key]) => ({ label, value: fields.value[key] })).filter((f) => f.value));

// Subtitle like "3rd-level Evocation" / "Transmutation cantrip".
const subtitle = computed(() => {
    const level = fields.value.level;
    const school = fields.value.school;
    if (!level && !school) return '';
    if (level && /cantrip/i.test(level)) return `${school ?? ''} cantrip`.trim();
    if (level && school) return `${level}-level ${school}`;
    return level || school || '';
});

// Body: the structured description (+ higher levels), else fall back to the stored document.
const bodyHtml = computed(() => {
    const description = fields.value.description;
    if (description) {
        let markdown = description;
        if (fields.value.higher_levels) markdown += `\n\n***At Higher Levels.*** ${fields.value.higher_levels}`;
        return marked.parse(markdown, { breaks: true });
    }
    if (props.spell?.document) {
        // Drop the leading "#### Name" heading; the header already shows the name.
        return marked.parse(props.spell.document.replace(/^#{1,6}\s+.*\n+/, ''), { breaks: true });
    }
    return props.spell?.summary ? `<p>${props.spell.summary}</p>` : '';
});

// Imported entries can be name-only stubs — show a note rather than a blank card.
const isEmpty = computed(() => facets.value.length === 0 && bodyHtml.value.trim() === '');
</script>

<template>
    <Teleport to="body">
        <div v-if="spell" class="spell-card" :style="{ top: `${spell.top}px`, left: `${spell.left}px` }">
            <div class="spell-card-head">
                <div class="spell-card-title">
                    <div class="spell-card-name">{{ spell.name }}</div>
                    <div v-if="subtitle" class="spell-card-sub">{{ subtitle }}</div>
                </div>
                <span class="spell-card-badge">SPELL</span>
            </div>
            <div v-if="facets.length" class="spell-card-facets">
                <div v-for="f in facets" :key="f.label" class="spell-card-facet">
                    <div class="spell-card-facet-label">{{ f.label }}</div>
                    <div class="spell-card-facet-value">{{ f.value }}</div>
                </div>
            </div>
            <div v-if="isEmpty" class="spell-card-body spell-card-empty">No details recorded for this spell yet.</div>
            <div v-else class="spell-card-body" v-html="bodyHtml" />
        </div>
    </Teleport>
</template>

<style scoped>
.spell-card {
    position: fixed;
    z-index: 60;
    width: 380px;
    max-width: calc(100vw - 24px);
    max-height: 70vh;
    overflow-y: auto;
    border-radius: 8px;
    border: 1px solid #2a2f38;
    background: #f7f2e6;
    color: #2a2118;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55);
    font-family: 'EB Garamond', Georgia, serif;
    font-size: 14px;
    line-height: 1.5;
    pointer-events: none; /* a read-only preview; hover the name, not the card */
    animation: spell-pop 0.12s ease-out;
}
.spell-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 16px;
    background: #14161b;
    border-radius: 8px 8px 0 0;
}
.spell-card-name {
    font-family: 'Cinzel', Georgia, serif;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.1;
    color: #f3efe6;
}
.spell-card-sub {
    margin-top: 2px;
    font-style: italic;
    font-size: 12.5px;
    color: #b8bcc4;
}
.spell-card-badge {
    flex-shrink: 0;
    align-self: center;
    border-radius: 4px;
    padding: 2px 8px;
    background: #5a3fb0;
    color: #fff;
    font-family: ui-sans-serif, system-ui, sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
}
.spell-card-facets {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 16px;
    padding: 12px 16px;
    border-bottom: 2px solid #7a200c;
}
.spell-card-facet-label {
    font-family: ui-sans-serif, system-ui, sans-serif;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #8a7355;
}
.spell-card-facet-value {
    font-size: 15px;
    color: #2a2118;
}
.spell-card-body {
    padding: 12px 16px;
}
.spell-card-empty {
    font-style: italic;
    color: #7a6a52;
}
.spell-card-body :deep(p) { margin: 0 0 8px; }
.spell-card-body :deep(ul) { margin: 0 0 8px; padding-left: 20px; list-style: disc; }
.spell-card-body :deep(li) { margin-bottom: 3px; }
.spell-card-body :deep(strong) { color: #58180d; }
.spell-card-body :deep(em) { font-style: italic; }
@keyframes spell-pop {
    from { opacity: 0; transform: translateY(-3px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
