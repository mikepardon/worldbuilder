<script setup>
import DicePopover from '@/Components/DicePopover.vue';
import SpellPopover from '@/Components/SpellPopover.vue';
import { useDiceRoller } from '@/composables/useDiceRoller';
import { useSpellRefs } from '@/composables/useSpellRefs';
import { renderBrewInline } from '@/lib/homebrew';
import { router } from '@inertiajs/vue3';
import { computed, toRef } from 'vue';

const { roll, handleClick: rollDiceClick } = useDiceRoller();

const props = defineProps({
    content: { type: String, default: '' },
    // Compendium embeds an [embed=id] renders as a card ({id, name, item_type, typeLabel, block?, document?}).
    embeds: { type: Array, default: () => [] },
    // Spells ({id, name, summary}) so a monster's spellcasting entries can hover-preview their spell.
    spells: { type: Array, default: () => [] },
    gm: { type: Boolean, default: false }, // when true, {{secret}} blocks render as revealable GM boxes
    // Entries a [[wiki-link]] can point at ({id, title}) and the URL prefix/suffix to build links from
    // (e.g. linkBase "/w/{world-slug}/" for the reader, or "/documents/" + linkSuffix "/edit" for the editor).
    wikiTargets: { type: Array, default: () => [] },
    linkBase: { type: String, default: '' },
    linkSuffix: { type: String, default: '' },
    // Field-kind entries (a location, an NPC…) read as a single narrow column with facts alongside,
    // rather than the two-column magazine flow used for freeform articles.
    singleColumn: { type: Boolean, default: false },
});
const emit = defineEmits(['reveal']);

const { spell, handleClick: spellClick, handleOver: spellOver, handleOut: spellOut } = useSpellRefs(toRef(props, 'spells'));

// The article renderer shares the homebrew pipeline (embeds, {{blocks}}, tables, \column, {{wide}}),
// flowed into a dark two-column layout rather than parchment page sheets.
const rendered = computed(() =>
    renderBrewInline(props.content, {
        entries: props.wikiTargets,
        embeds: props.embeds,
        spells: props.spells,
        gm: props.gm,
        wikiBase: props.linkBase,
        wikiSuffix: props.linkSuffix,
    }),
);

const revealTarget = (event) => event.target.closest?.('.hb-reveal[data-reveal]');

const onClick = (event) => {
    if (spellClick(event)) return;
    if (rollDiceClick(event)) return;
    const reveal = revealTarget(event);
    if (reveal) { emit('reveal', Number(reveal.dataset.reveal)); return; }
    const anchor = event.target.closest?.('a[href]');
    if (!anchor) return;
    const href = anchor.getAttribute('href');
    // In-page anchors (e.g. a {{toc}} entry) scroll to the matching heading id.
    if (href && href.startsWith('#')) {
        event.preventDefault();
        document.getElementById(decodeURIComponent(href.slice(1)))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }
    if (anchor.classList.contains('wikilink')) { event.preventDefault(); router.visit(href); }
};

const onKeydown = (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const reveal = revealTarget(event);
    if (reveal) { event.preventDefault(); emit('reveal', Number(reveal.dataset.reveal)); }
};
</script>

<template>
    <div @click="onClick" @keydown="onKeydown" @mouseover="spellOver" @mouseout="spellOut">
        <component :is="'style'" v-text="rendered.css" />
        <div class="rc brew-page" :class="{ 'rc-single': singleColumn }" v-html="rendered.html" />
        <DicePopover :roll="roll" />
        <SpellPopover :spell="spell" />
    </div>
</template>

<style scoped>
/* Two-column flow. Full-width breakouts span both columns; \column forces the next column. */
.rc {
    column-count: 2;
    column-gap: 34px;
    color: #d7dbe2;
    font-family: 'EB Garamond', Georgia, serif;
    font-size: 16.5px;
    line-height: 1.55;
}
@media (max-width: 720px) {
    .rc { column-count: 1; }
}
/* Single-column reading measure for field-kind entries (facts live in the right rail instead). */
.rc-single { column-count: 1; max-width: 680px; }
.rc :deep(.wide),
.rc :deep(.toc),
.rc :deep(.hb-pagebreak) { column-span: all; -webkit-column-span: all; }
/* Table of contents — two columns, dotted leaders ({{toc}} auto-generated). */
.rc :deep(.toc) { columns: 2; column-gap: 30px; margin: 0 0 12px; }
.rc :deep(.toc-item) { break-inside: avoid; margin: 0 0 4px; }
.rc :deep(.toc-item a) { display: flex; align-items: baseline; color: #d9a441; text-decoration: none; }
.rc :deep(.toc-title) { flex: 1; overflow: hidden; white-space: nowrap; font-variant: small-caps; font-weight: 700; }
.rc :deep(.toc-title::after) { content: ' ........................................................................................................'; color: #3a4150; }
.rc :deep(.toc-page) { flex-shrink: 0; padding-left: 6px; font-weight: 700; }
.rc :deep(.toc-h2) { padding-left: 10px; }
.rc :deep(.toc-h3) { padding-left: 20px; }
.rc :deep(.toc-h2 .toc-title), .rc :deep(.toc-h3 .toc-title) { font-variant: normal; font-weight: 400; }
.rc :deep(.hb-colbreak) { break-before: column; height: 0; margin: 0; }
.rc :deep(.note),
.rc :deep(.descriptive),
.rc :deep(.monster),
.rc :deep(.hb-embed-card),
.rc :deep(.hb-secret),
.rc :deep(table),
.rc :deep(blockquote) { break-inside: avoid; }

/* Prose elements (dark theme). */
.rc :deep(h1) { font-family: 'Cinzel', 'Georgia', serif; font-size: 28px; line-height: 1.1; letter-spacing: 0.01em; color: #f2f4f8; margin: 0 0 10px; break-after: avoid; }
.rc :deep(h2) { font-family: 'Cinzel', 'Georgia', serif; font-size: 21px; letter-spacing: 0.01em; color: #eef1f6; border-bottom: 1px solid #2a2f38; padding-bottom: 3px; margin: 16px 0 8px; break-after: avoid; }
/* PHB-style drop cap on the first paragraph after a chapter heading. */
.rc :deep(h1 + p::first-letter) { font-family: 'Cinzel Decorative', 'Cinzel', serif; float: left; font-size: 3.1em; line-height: 0.66; padding: 4px 8px 0 0; color: #d9a441; }
.rc :deep(h3) { font-size: 17px; font-weight: 700; color: #e8ebf1; margin: 12px 0 4px; break-after: avoid; }
.rc :deep(h4) { font-size: 15px; font-weight: 700; color: #e8ebf1; margin: 10px 0 3px; break-after: avoid; }
.rc :deep(h5) { font-size: 13px; font-weight: 700; letter-spacing: 0.02em; color: #c8ccd3; margin: 8px 0 3px; break-after: avoid; }
.rc :deep(p) { margin: 0 0 10px; }
.rc :deep(ul), .rc :deep(ol) { margin: 0 0 10px; padding-left: 22px; }
.rc :deep(ul) { list-style: disc; }
.rc :deep(ol) { list-style: decimal; }
.rc :deep(li) { margin-bottom: 3px; }
.rc :deep(a) { color: #4ec9a0; }
.rc :deep(strong) { font-weight: 700; color: #eef1f6; }
.rc :deep(em) { font-style: italic; }
/* Extended inline Markdown: ^super^, ~sub~, ==highlight==, and definition lists. */
.rc :deep(sup), .rc :deep(sub) { font-size: 0.72em; }
.rc :deep(mark) { background: #4a4326; color: #f4ecc9; padding: 0 3px; border-radius: 2px; }
.rc :deep(dl) { margin: 0 0 10px; }
.rc :deep(dt) { font-weight: 700; color: #eef1f6; }
.rc :deep(dd) { margin: 0 0 4px 18px; }
.rc :deep(img) { max-width: 100%; border-radius: 6px; }
.rc :deep(hr.hb-divider) { border: 0; height: 3px; background: #7a200c; border-radius: 2px; margin: 12px 0; }
.rc :deep(hr.hb-pagebreak) { border: 0; border-top: 1px solid #2a2f38; margin: 26px 0; }
.rc :deep(blockquote) { border-left: 2px solid #3a4150; padding-left: 12px; font-style: italic; color: #b8bcc4; margin: 0 0 10px; }

/* Tables (dark). Cards restyle their own tables below. */
.rc :deep(table) { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 0 0 12px; }
.rc :deep(th), .rc :deep(td) { border: 1px solid #2a2f38; padding: 3px 8px; text-align: left; overflow-wrap: break-word; word-break: break-word; }
.rc :deep(thead th) { background: #171b21; color: #eef1f6; font-weight: 700; }
.rc :deep(tbody tr:nth-child(even)) { background: #14171d; }

/* Layout callouts (dark). */
.rc :deep(.wide) { column-span: all; }
.rc :deep(.note), .rc :deep(.descriptive) {
    background: #171b21;
    border: 1px solid #2a2f38;
    border-radius: 8px;
    padding: 10px 14px;
    margin: 0 0 12px;
}
.rc :deep(.descriptive) { background: #14181d; font-style: italic; }

/* GM secret box with a revealable control. */
.rc :deep(.hb-secret) {
    border: 1px dashed #6b4c14;
    background: #20190d;
    border-radius: 8px;
    padding: 12px 16px;
    margin: 0 0 14px;
}
.rc :deep(.hb-secret-head) { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.rc :deep(.hb-secret-tag) { font-family: ui-monospace, monospace; font-size: 9.5px; letter-spacing: 0.16em; text-transform: uppercase; color: #d9a441; }
.rc :deep(.hb-reveal) { cursor: pointer; border: 1px solid #6b4c14; border-radius: 6px; padding: 4px 12px; font-size: 13px; color: #d9a441; user-select: none; white-space: nowrap; }
.rc :deep(.hb-reveal:hover) { background: #2b2110; }

/* Compendium embeds render as parchment cards (islands on the dark page), matching the reader elsewhere. */
.rc :deep(.monster) { border: 1px solid #9c9b6d; background: #f5ecd7; border-radius: 6px; padding: 10px 14px; margin: 0 0 12px; }
/* Hand-authored {{monster}} blocks: style the inner Markdown like a 5e stat block. */
.rc :deep(.monster h1), .rc :deep(.monster h2), .rc :deep(.monster h3) { font-family: 'Cinzel', 'Georgia', serif; font-variant: small-caps; font-size: 20px; line-height: 1.05; color: #7a200c; border: 0; margin: 0 0 1px; }
.rc :deep(.monster hr), .rc :deep(.monster .hb-divider) { border: 0; height: 0; border-top: 2px solid #922610; background: none; margin: 4px 0; }
.rc :deep(.monster p) { margin: 0 0 4px; }
.rc :deep(.monster table) { border: 0; width: 100%; margin: 3px 0; text-align: center; }
.rc :deep(.monster th), .rc :deep(.monster td) { border: 0; background: none; padding: 0 4px; text-align: center; }
.rc :deep(.monster thead th) { color: #7a200c; font-weight: 700; background: none; }
.rc :deep(.monster tbody tr:nth-child(even)) { background: none; }
.rc :deep(.monster.wide:not(.hb-statblock)) { column-count: 2; column-gap: 1.6rem; }
.rc :deep(.spell) { border: 1px solid #7a5a9b; background: #efe6f2; border-radius: 6px; padding: 10px 14px; margin: 0 0 12px; }
.rc :deep(.item) { border: 1px solid #b8843f; background: #f3ecd8; border-radius: 6px; padding: 10px 14px; margin: 0 0 12px; }
.rc :deep(.hb-embed) { border: 1px solid #b8843f; background: #f3ecd8; border-radius: 6px; padding: 10px 14px; }
.rc :deep(.hb-embed-card) { margin: 0 0 12px; }
/* Card interiors sit on parchment, so override the dark prose colours. */
.rc :deep(.monster), .rc :deep(.spell), .rc :deep(.item), .rc :deep(.hb-embed-card) { color: #3a2c14; }
.rc :deep(.monster h1), .rc :deep(.monster h2), .rc :deep(.monster h3), .rc :deep(.monster h4),
.rc :deep(.spell h1), .rc :deep(.spell h2), .rc :deep(.spell h3), .rc :deep(.spell h4),
.rc :deep(.item h1), .rc :deep(.item h2), .rc :deep(.item h3), .rc :deep(.item h4),
.rc :deep(.hb-embed-card h1), .rc :deep(.hb-embed-card h2), .rc :deep(.hb-embed-card h3), .rc :deep(.hb-embed-card h4),
.rc :deep(.monster strong), .rc :deep(.spell strong), .rc :deep(.item strong), .rc :deep(.hb-embed-card strong) { color: #58180d; border-color: #c0a24e; }
.rc :deep(.monster th), .rc :deep(.monster td),
.rc :deep(.hb-embed-card th), .rc :deep(.hb-embed-card td),
.rc :deep(.spell th), .rc :deep(.spell td), .rc :deep(.item th), .rc :deep(.item td) { border-color: #c9b890; }
.rc :deep(.monster thead th), .rc :deep(.hb-embed-card thead th) { background: #dce6d4; color: #58180d; }
.rc :deep(.monster tbody tr:nth-child(even)), .rc :deep(.hb-embed-card tbody tr:nth-child(even)) { background: #efe3c8; }

.rc :deep(.hb-statblock) { font-family: 'EB Garamond', Georgia, serif; container-type: inline-size; background: #f5ecd7; border-color: #9c9b6d; color: #3a2c14; }
.rc :deep(.hb-sb-name) { font-family: 'EB Garamond', Georgia, serif; font-variant: small-caps; font-size: 22px; line-height: 1.05; color: #7a200c; font-weight: 700; letter-spacing: 0.01em; }
.rc :deep(.hb-sb-meta) { font-style: italic; margin-bottom: 3px; }
.rc :deep(.hb-sb-rule) { height: 0; border-top: 2px solid #922610; margin: 5px 0; }
.rc :deep(.hb-sb-group) { border-bottom: 1px solid #922610; color: #7a200c; font-variant: small-caps; font-weight: 700; font-size: 17px; margin: 8px 0 3px; }
.rc :deep(.hb-statblock p) { margin: 0 0 4px; }
/* Abilities: label over "score (mod)", centered, borderless — 3×2 narrow, 6-across wide. */
.rc :deep(.hb-sb-abilities) { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px 8px; margin: 3px 0; text-align: center; }
.rc :deep(.hb-sb-ability) { padding: 1px 0; }
.rc :deep(.hb-sb-ability-label) { font-weight: 700; font-size: 12px; color: #7a200c; }
.rc :deep(.hb-sb-ability-score) { font-size: 12.5px; }
@container (min-width: 340px) {
    .rc :deep(.hb-sb-abilities) { grid-template-columns: repeat(6, 1fr); }
}

.rc :deep(.hb-embed-head) { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; border-bottom: 1px solid #c0a24e; padding-bottom: 2px; margin-bottom: 4px; }
.rc :deep(.hb-embed-name) { font-family: 'EB Garamond', Georgia, serif; font-size: 16px; font-weight: 700; color: #58180d; }
.rc :deep(.hb-embed-type) { font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; color: #8a6d3b; white-space: nowrap; }
.rc :deep(.hb-embed-missing) { border: 1px dashed #3a4150; background: #14171d; border-radius: 6px; padding: 6px 10px; margin: 0 0 12px; font-size: 0.92em; color: #9aa0aa; }
/* {{monster=1,frame}} — a decorative double border on an embed. */
.rc :deep(.frame) { border: 2px solid #b89b3f; border-top-width: 6px; box-shadow: inset 0 0 0 2px #f5ecd7, inset 0 0 0 4px #b89b3f, 0 3px 10px rgba(0, 0, 0, 0.4); border-radius: 2px; padding: 12px 16px; }
.rc :deep(.embed-chip) {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px dashed #6b7280;
    background: rgba(120, 130, 150, 0.12);
    color: #c8ccd3;
    border-radius: 4px;
    padding: 0 5px;
    font-size: 0.9em;
}

/* Compendium-linked spells in a monster's spellcasting entry (hover shows the spell's summary). */
.rc :deep(.spell-ref) {
    border-bottom: 1px dotted currentColor;
    cursor: pointer;
}
.rc :deep(.spell-ref:hover) { color: #922610; }

/* Clickable dice expressions (2d6+3, ability checks). */
.rc :deep(.dice-roll) {
    cursor: pointer;
    font-weight: 700;
    border-bottom: 1px dotted currentColor;
    text-underline-offset: 2px;
    transition: color 0.1s, background 0.1s;
}
.rc :deep(.dice-roll:hover) { color: #922610; background: rgba(146, 38, 16, 0.08); border-radius: 3px; }

/* Wiki-links. */
.rc :deep(.wikilink) { color: #4ec9a0; text-decoration: none; border-bottom: 1px solid rgba(78, 201, 160, 0.4); cursor: pointer; }
.rc :deep(.wikilink:hover) { border-bottom-color: #4ec9a0; }
.rc :deep(.wikilink-missing) { color: #b8bcc4; }
</style>
