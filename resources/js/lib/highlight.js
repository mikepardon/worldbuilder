/**
 * Lightweight syntax highlighter for the brew source editor. Produces an HTML string (one line per
 * source line) that is rendered in a layer *behind* a transparent <textarea>, mirroring Homebrewery's
 * coloured editor. It only classifies tokens — the textarea remains the single source of truth.
 */

/** Escape text for safe injection into the highlight layer. */
function esc(value) {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Inline tokens, each captured in its own group so the replace callback can pick a class.
const INLINE = /(\{\{[^}\n]*\}\}|\}\})|(\[\[[^\]\n]*\]\])|(\[(?:statblock|item|spell|monster|magicitem|equipment|condition|race|feat)=\d+\])|(!?\[[^\]\n]*\]\([^)\n]*\))|(`[^`\n]+`)|(\*\*[^*\n]+\*\*|__[^_\n]+__)|(<\/?[a-zA-Z][^>\n]*>)/g;

function highlightInline(text) {
    let out = '';
    let last = 0;
    let match;
    INLINE.lastIndex = 0;
    while ((match = INLINE.exec(text)) !== null) {
        out += esc(text.slice(last, match.index));
        const [token, curly, wiki, embed, link, code, bold, tag] = match;
        const cls = curly ? 'tok-curly' : wiki ? 'tok-wiki' : embed ? 'tok-embed' : link ? 'tok-link' : code ? 'tok-code' : bold ? 'tok-bold' : 'tok-tag';
        out += `<span class="${cls}">${esc(token)}</span>`;
        last = match.index + token.length;
    }
    out += esc(text.slice(last));
    return out;
}

function highlightLine(line) {
    if (/^\s*\\(?:page|column)\s*$/.test(line)) return `<span class="tok-key">${esc(line)}</span>`;
    if (/^\s*(?:---|___|:{2,})\s*$/.test(line)) return `<span class="tok-key">${esc(line)}</span>`;
    if (/^\s*#{1,6}\s+/.test(line)) return `<span class="tok-heading">${esc(line)}</span>`;

    const quote = line.match(/^(\s*>\s?)(.*)$/);
    if (quote) return `<span class="tok-quote">${esc(quote[1])}</span>${highlightInline(quote[2])}`;

    const listItem = line.match(/^(\s*(?:[-*+]|\d+\.)\s+)(.*)$/);
    if (listItem) return `<span class="tok-key">${esc(listItem[1])}</span>${highlightInline(listItem[2])}`;

    return highlightInline(line);
}

/** @returns {string} highlighted HTML, one source line per line (newlines preserved). */
export function highlightBrewSource(text) {
    return (text ?? '').split('\n').map(highlightLine).join('\n');
}
