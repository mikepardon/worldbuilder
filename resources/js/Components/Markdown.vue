<script setup>
import { marked } from "marked";
import DOMPurify from "dompurify";
import { computed } from "vue";

// Renders recap/analysis markdown (headings, bold, lists) as styled prose for read/view mode, while edit
// mode keeps the raw text in inputs. `inline` renders just inline formatting (bold/italic) with no block
// wrapping, for short one-line values like a moment description.
const { source = "", inline = false } = defineProps({
    source: { type: String, default: "" },
    inline: { type: Boolean, default: false },
});

const html = computed(() => {
    const raw = source ?? "";
    const rendered = inline
        ? marked.parseInline(raw, { async: false })
        : marked.parse(raw, { async: false });

    // v-html is unavoidable to render Markdown; the output is sanitised with DOMPurify before binding.
    return DOMPurify.sanitize(rendered);
});
</script>

<template>
    <!-- v-html renders the Markdown; `html` is DOMPurify-sanitised in the script above. -->
    <div class="md" :class="{ 'md-inline': inline }" v-html="html"></div>
</template>

<style scoped>
.md {
    font-size: 0.875rem;
    line-height: 1.65;
    color: inherit;
}
.md-inline {
    display: inline;
}
.md :deep(h1) {
    font-size: 1.3rem;
    font-weight: 600;
    color: #eef1f6;
    margin: 0 0 0.6rem;
}
.md :deep(h2) {
    font-size: 1.08rem;
    font-weight: 600;
    color: #eef1f6;
    margin: 1rem 0 0.4rem;
}
.md :deep(h3) {
    font-size: 0.98rem;
    font-weight: 600;
    color: #e8ebf1;
    margin: 0.8rem 0 0.3rem;
}
.md :deep(p) {
    margin: 0 0 0.65rem;
}
.md :deep(p:last-child) {
    margin-bottom: 0;
}
.md :deep(strong) {
    font-weight: 600;
    color: #eef1f6;
}
.md :deep(em) {
    font-style: italic;
}
.md :deep(ul),
.md :deep(ol) {
    margin: 0 0 0.65rem;
    padding-left: 1.25rem;
}
.md :deep(ul) {
    list-style: disc;
}
.md :deep(ol) {
    list-style: decimal;
}
.md :deep(li) {
    margin-bottom: 0.2rem;
}
.md :deep(a) {
    color: #d9a441;
    text-decoration: underline;
}
.md :deep(blockquote) {
    border-left: 2px solid #3a4150;
    padding-left: 0.75rem;
    font-style: italic;
    margin: 0 0 0.65rem;
}
.md :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 0.85em;
}
</style>
