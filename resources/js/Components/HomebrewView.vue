<script setup>
import DicePopover from '@/Components/DicePopover.vue';
import SpellPopover from '@/Components/SpellPopover.vue';
import { useDiceRoller } from '@/composables/useDiceRoller';
import { useSpellRefs } from '@/composables/useSpellRefs';
import { renderBrew } from '@/lib/homebrew';
import { router } from '@inertiajs/vue3';
import { computed, toRef } from 'vue';

const { roll, handleClick: rollDiceClick } = useDiceRoller();

const props = defineProps({
    content: { type: String, default: '' },
    css: { type: String, default: '' },
    entries: { type: Array, default: () => [] },
    // Compendium entries an [embed=id] can render as a full card ({id, name, item_type, typeLabel, block?, document?}).
    embeds: { type: Array, default: () => [] },
    // Spells ({id, name, summary}) so a monster's spellcasting entries can hover-preview their spell.
    spells: { type: Array, default: () => [] },
    gm: { type: Boolean, default: false },
    pageMode: { type: String, default: 'spread' }, // single | spread | grid
    zoom: { type: Number, default: 100 },
    theme: { type: String, default: 'classic' }, // classic | ivory | journal
    fullWidth: { type: Boolean, default: false }, // fill the pane width, one page each (vs fixed page sheets)

    // Where a [[wiki-link]] points: editor by default, or the public reader.
    wikiBase: { type: String, default: '/documents/' },
    wikiSuffix: { type: String, default: '/edit' },
});

const { spell, handleClick: spellClick, handleOver: spellOver, handleOut: spellOut } = useSpellRefs(toRef(props, 'spells'));

const brew = computed(() =>
    renderBrew(props.content, {
        entries: props.entries,
        embeds: props.embeds,
        spells: props.spells,
        gm: props.gm,
        css: props.css,
        wikiBase: props.wikiBase,
        wikiSuffix: props.wikiSuffix,
    }),
);

const sheetWidth = computed(() => (props.pageMode === 'grid' ? 300 : props.pageMode === 'single' ? 520 : 460));

// Full-width pages (each fills all available width), a page-filling spread (two pages sharing the
// row, 2rem gap), or fixed page sheets.
const sheetStyle = computed(() => {
    if (props.fullWidth) return { width: '100%', maxWidth: '800px', minHeight: '800px' };
    // Spread: two pages share the row (600–800px each); when 2×600 no longer fits they wrap to one-up (full view).
    if (props.pageMode === 'spread') return { flex: '1 1 calc(50% - 1rem)', minWidth: '600px', maxWidth: '800px', minHeight: '800px' };
    return { width: `${sheetWidth.value}px`, minHeight: `${Math.round(sheetWidth.value * 1.29)}px` };
});

const onClick = (event) => {
    if (spellClick(event)) return;
    if (rollDiceClick(event)) return;
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
</script>

<template>
    <div @click="onClick" @mouseover="spellOver" @mouseout="spellOut">
        <component :is="'style'" v-text="brew.css" />
        <div class="printable flex justify-center" :class="fullWidth ? 'flex-col items-stretch gap-8' : pageMode === 'single' ? 'flex-col items-center gap-6' : 'flex-wrap items-stretch gap-8'" :style="{ transform: `scale(${zoom / 100})`, transformOrigin: 'top center' }">
            <div
                v-for="(pageHtml, i) in brew.pages"
                :key="i"
                class="brew-sheet relative mx-auto bg-[#fdf1dc] px-9 pb-11 pt-8 text-[#2a1e12] shadow-[0_16px_40px_rgba(0,0,0,0.5)]"
                :class="`theme-${theme}`"
                :style="sheetStyle"
            >
                <div class="brew-page" :class="`theme-${theme}`" :style="{ columnCount: pageMode === 'grid' ? 1 : 2, columnGap: fullWidth ? '2rem' : '20px' }" v-html="pageHtml" />
                <div class="absolute bottom-3 right-5 text-right font-mono text-[10px] tracking-widest text-[#8a7355]">{{ i + 1 }}</div>
            </div>
        </div>
        <DicePopover :roll="roll" />
        <SpellPopover :spell="spell" />
    </div>
</template>

<style scoped>
.brew-page {
    --hb-ink: #2a1e12;
    --hb-accent: #c0a24e;
    --hb-header: #58180d;
    font-family: 'EB Garamond', Georgia, serif;
    font-size: 12.5px;
    line-height: 1.4;
    color: var(--hb-ink);
}
/* Themes swap the palette; the sheet background is set on .brew-sheet below. */
.brew-page.theme-ivory { --hb-ink: #2b2b2b; --hb-accent: #b8ae90; --hb-header: #6b3a2a; }
.brew-page.theme-journal { --hb-ink: #3a2b1a; --hb-accent: #9c7a3f; --hb-header: #6a3410; }
.brew-sheet.theme-ivory { background: #fbf7ef; }
.brew-sheet.theme-journal { background: #ede0c2; }
.brew-page :deep(h1) {
    font-family: 'Cinzel', 'Georgia', serif;
    font-size: 23px;
    line-height: 1.1;
    letter-spacing: 0.01em;
    color: var(--hb-header);
    border-bottom: 2px solid var(--hb-accent);
    padding-bottom: 2px;
    margin: 0 0 6px;
    break-after: avoid;
}
/* PHB-style drop cap on the first paragraph after a chapter heading. */
.brew-page :deep(h1 + p::first-letter) {
    font-family: 'Cinzel Decorative', 'Cinzel', serif;
    float: left;
    font-size: 3.1em;
    line-height: 0.66;
    padding: 4px 6px 0 0;
    color: #7a200c;
}
.brew-page :deep(h2) {
    font-family: 'Cinzel', 'Georgia', serif;
    font-size: 17px;
    letter-spacing: 0.01em;
    color: var(--hb-header);
    border-bottom: 1px solid var(--hb-accent);
    margin: 10px 0 4px;
    break-after: avoid;
}
.brew-page :deep(h3) { font-size: 14px; font-weight: 700; color: var(--hb-header); margin: 8px 0 2px; break-after: avoid; }
.brew-page :deep(h4) { font-size: 13px; font-weight: 700; color: var(--hb-header); margin: 6px 0 2px; break-after: avoid; }
.brew-page :deep(h5) { font-size: 12px; font-weight: 700; letter-spacing: 0.02em; margin: 5px 0 2px; break-after: avoid; }
.brew-page :deep(p) { margin: 0 0 6px; }
.brew-page :deep(ul), .brew-page :deep(ol) { margin: 0 0 6px; padding-left: 18px; }
.brew-page :deep(ul) { list-style: disc; }
.brew-page :deep(ol) { list-style: decimal; }
.brew-page :deep(li) { margin-bottom: 1px; }
.brew-page :deep(a) { color: var(--hb-header); }
.brew-page :deep(strong) { font-weight: 700; }
.brew-page :deep(em) { font-style: italic; }
/* Extended inline Markdown: ^super^, ~sub~, ==highlight==, and definition lists. */
.brew-page :deep(sup), .brew-page :deep(sub) { font-size: 0.72em; }
.brew-page :deep(mark) { background: #f0e2a4; color: inherit; padding: 0 2px; border-radius: 2px; }
.brew-page :deep(dl) { margin: 0 0 6px; }
.brew-page :deep(dt) { font-weight: 700; }
.brew-page :deep(dd) { margin: 0 0 3px 16px; }
.brew-page :deep(hr) { border: 0; border-top: 1px solid var(--hb-accent); margin: 8px 0; }
.brew-page :deep(img) { max-width: 100%; }
.brew-page :deep(blockquote) {
    border-left: 2px solid var(--hb-accent);
    padding-left: 8px;
    font-style: italic;
    margin: 0 0 6px;
}
.brew-page :deep(table) { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 0 0 8px; break-inside: avoid; }
.brew-page :deep(th), .brew-page :deep(td) { border: 1px solid #c9b890; padding: 1px 6px; text-align: left; overflow-wrap: break-word; word-break: break-word; }
.brew-page :deep(thead th) { background: #dce6d4; font-weight: 700; }
.brew-page :deep(tbody tr:nth-child(even)) { background: #efe3c8; }

/* Homebrewery blocks */
.brew-page :deep(.wide) { column-span: all; -webkit-column-span: all; }
.brew-page :deep(.note),
.brew-page :deep(.descriptive) {
    background: #e8dcc0;
    border: 1px solid #d8c9a0;
    border-radius: 4px;
    box-shadow: inset 0 0 6px rgba(0, 0, 0, 0.08);
    padding: 8px 12px;
    margin: 0 0 8px;
    break-inside: avoid;
}
.brew-page :deep(.descriptive) { background: #efe7d3; font-style: italic; }
.brew-page :deep(.monster) { border: 1px solid #b89b3f; background: #f5ecd7; color: #3a2c14; border-radius: 4px; padding: 8px 12px; margin: 0 0 8px; break-inside: avoid; }
/* Hand-authored {{monster}} blocks: style the inner Markdown like a 5e stat block. */
.brew-page :deep(.monster h1), .brew-page :deep(.monster h2), .brew-page :deep(.monster h3) {
    font-family: 'Cinzel', 'Georgia', serif; font-variant: small-caps; font-size: 19px; line-height: 1.05;
    color: #7a200c; border: 0; margin: 0 0 1px; break-after: avoid;
}
.brew-page :deep(.monster hr), .brew-page :deep(.monster .hb-divider) { border: 0; height: 0; border-top: 2px solid #922610; background: none; margin: 4px 0; break-after: avoid; }
.brew-page :deep(.monster p) { margin: 0 0 4px; }
.brew-page :deep(.monster table) { border: 0; width: 100%; margin: 3px 0; text-align: center; break-inside: avoid; }
.brew-page :deep(.monster th), .brew-page :deep(.monster td) { border: 0; background: none; padding: 0 4px; text-align: center; }
.brew-page :deep(.monster thead th) { color: #7a200c; font-weight: 700; background: none; }
.brew-page :deep(.monster tbody tr:nth-child(even)) { background: none; }
/* {{monster,wide}} — flow the stat block into two columns (the compendium card keeps its own layout). */
.brew-page :deep(.monster.wide:not(.hb-statblock)) { column-count: 2; column-gap: 1.6rem; }
.brew-page :deep(.spell) { border: 1px solid #7a5a9b; background: #efe6f2; border-radius: 4px; padding: 8px 12px; margin: 0 0 8px; break-inside: avoid; }
.brew-page :deep(.item) { border: 1px solid #b8843f; background: #f3ecd8; border-radius: 4px; padding: 8px 12px; margin: 0 0 8px; break-inside: avoid; }
.brew-page :deep(.hb-secret) {
    border: 1px dashed #a86b1f;
    background: #f2e2bf;
    border-radius: 4px;
    padding: 6px 10px;
    margin: 0 0 8px;
}

/* Cover & part/chapter pages */
.brew-page :deep(.titlePage) { column-span: all; text-align: center; padding: 30% 8% 0; }
.brew-page :deep(.titlePage h1) { font-size: 40px; border: 0; letter-spacing: 0.04em; margin-bottom: 6px; }
.brew-page :deep(.titlePage h2) { font-size: 19px; border: 0; font-style: italic; letter-spacing: 0; }
.brew-page :deep(.titlePage hr) { width: 55%; margin: 12px auto; }
.brew-page :deep(.partPage) { column-span: all; text-align: center; padding: 26% 8% 0; }
.brew-page :deep(.partPage h1) { font-size: 34px; border: 0; letter-spacing: 0.04em; }
.brew-page :deep(.partPage h2) { font-size: 18px; border: 0; font-style: italic; letter-spacing: 0; }

/* Compendium embeds ({{monster=id}}, {{spell=id}}, …) rendered as full D&D-style stat blocks. */
.brew-page :deep(.hb-statblock) { font-family: 'EB Garamond', Georgia, serif; container-type: inline-size; background: #f5ecd7; border-color: #9c9b6d; color: #3a2c14; }
.brew-page :deep(.hb-sb-name) { font-family: 'EB Garamond', Georgia, serif; font-variant: small-caps; font-size: 22px; line-height: 1.05; color: #7a200c; font-weight: 700; letter-spacing: 0.01em; }
.brew-page :deep(.hb-sb-meta) { font-style: italic; margin-bottom: 3px; }
.brew-page :deep(.hb-sb-rule) { height: 0; border-top: 2px solid #922610; margin: 5px 0; }
.brew-page :deep(.hb-sb-group) { border-bottom: 1px solid #922610; color: #7a200c; font-variant: small-caps; font-weight: 700; font-size: 17px; margin: 8px 0 3px; }
.brew-page :deep(.hb-statblock p) { margin: 0 0 4px; }
/* Abilities: label over "score (mod)", centered, borderless — 3×2 narrow, 6-across wide. */
.brew-page :deep(.hb-sb-abilities) { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px 8px; margin: 3px 0; text-align: center; }
.brew-page :deep(.hb-sb-ability) { padding: 1px 0; }
.brew-page :deep(.hb-sb-ability-label) { font-weight: 700; font-size: 12px; color: #7a200c; }
.brew-page :deep(.hb-sb-ability-score) { font-size: 12.5px; }
@container (min-width: 340px) {
    .brew-page :deep(.hb-sb-abilities) { grid-template-columns: repeat(6, 1fr); }
}

/* Layout only — the box colour comes from the type class (.spell / .item / .hb-embed) also on the element. */
.brew-page :deep(.hb-embed-card) { margin: 0 0 8px; break-inside: avoid; }
.brew-page :deep(.hb-embed) { border: 1px solid #b8843f; background: #f3ecd8; border-radius: 4px; padding: 8px 12px; }
.brew-page :deep(.hb-embed-head) { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; border-bottom: 1px solid var(--hb-accent); padding-bottom: 2px; margin-bottom: 4px; }
.brew-page :deep(.hb-embed-name) { font-family: 'EB Garamond', Georgia, serif; font-size: 15px; font-weight: 700; color: var(--hb-header); }
.brew-page :deep(.hb-embed-type) { font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; color: #8a6d3b; white-space: nowrap; }
.brew-page :deep(.hb-embed-card > p:last-child) { margin-bottom: 0; }
.brew-page :deep(.hb-embed-missing) { border: 1px dashed #b8843f; background: rgba(184, 132, 63, 0.1); border-radius: 4px; padding: 4px 8px; margin: 0 0 8px; font-size: 0.9em; color: #8a6d3b; }
/* {{monster=1,frame}} — a decorative double border on an embed. */
.brew-page :deep(.frame) { border: 2px solid #b89b3f; border-top-width: 6px; box-shadow: inset 0 0 0 2px #f5ecd7, inset 0 0 0 4px #b89b3f, 0 3px 10px rgba(0, 0, 0, 0.3); border-radius: 2px; padding: 12px 16px; }
.brew-page :deep(.hb-divider) { border: 0; height: 3px; background: #9c2b1b; border-radius: 2px; margin: 6px 0; }
.brew-page :deep(.hb-colbreak) { break-before: column; height: 0; margin: 0; }

/* Page furniture */
.brew-page :deep(.pageNumber) { position: absolute; bottom: 18px; right: 24px; font-size: 10px; text-align: right; color: #8a7355; }
.brew-page :deep(.pageNumber.auto) { right: 24px; left: auto; }
.brew-page :deep(.footnote) { position: absolute; bottom: 18px; left: 24px; max-width: 55%; font-size: 9px; letter-spacing: 0.1em; text-transform: uppercase; text-align: left; color: #8a7355; }

/* Table of contents — two columns, dotted leaders, right-aligned page numbers ({{toc}} auto-generated). */
.brew-page :deep(.toc) { column-span: all; -webkit-column-span: all; columns: 2; column-gap: 30px; margin: 0 0 8px; }
.brew-page :deep(.toc-item) { break-inside: avoid; margin: 0 0 3px; }
.brew-page :deep(.toc-item a) { display: flex; align-items: baseline; color: var(--hb-header); text-decoration: none; }
.brew-page :deep(.toc-title) { flex: 1; overflow: hidden; white-space: nowrap; font-variant: small-caps; font-weight: 700; }
.brew-page :deep(.toc-title::after) { content: ' ........................................................................................................'; color: var(--hb-accent); }
.brew-page :deep(.toc-page) { flex-shrink: 0; padding-left: 6px; font-weight: 700; }
.brew-page :deep(.toc-h2) { padding-left: 10px; }
.brew-page :deep(.toc-h3) { padding-left: 20px; }
.brew-page :deep(.toc-h2 .toc-title), .brew-page :deep(.toc-h3 .toc-title) { font-variant: normal; font-weight: 400; }

/* Compendium-linked spells in a monster's spellcasting entry (hover shows the spell's summary). */
.brew-sheet :deep(.spell-ref) {
    border-bottom: 1px dotted currentColor;
    cursor: pointer;
}
.brew-sheet :deep(.spell-ref:hover) { color: #922610; }

/* Clickable dice expressions and ability checks in rendered stat blocks. */
.brew-sheet :deep(.dice-roll) {
    cursor: pointer;
    font-weight: 700;
    border-bottom: 1px dotted currentColor;
}
.brew-sheet :deep(.dice-roll:hover) {
    color: #922610;
    background: rgba(146, 38, 16, 0.1);
    border-radius: 3px;
}

.brew-sheet :deep(.wikilink) {
    color: var(--hb-header);
    text-decoration: underline;
    text-decoration-color: rgba(88, 24, 13, 0.4);
    cursor: pointer;
}
.brew-sheet :deep(.wikilink-missing) {
    color: #9b2c2c;
    text-decoration: underline dashed rgba(155, 44, 44, 0.45);
}
.brew-sheet :deep(.embed-chip) {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px dashed #a86b1f;
    background: rgba(168, 107, 31, 0.14);
    color: #5c3d12;
    border-radius: 4px;
    padding: 0 5px;
    font-size: 0.9em;
}

@media print {
    .brew-sheet {
        box-shadow: none !important;
        break-after: page;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
