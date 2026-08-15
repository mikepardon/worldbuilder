// Scope a world's custom reader CSS to the reader root (.wb-reader) and strip unsafe constructs, so a
// GM can restyle their published reader without breaking out of it or pulling in external/executing
// resources. Mirrors the homebrew CSS scoper (resources/js/lib/homebrew.js) but targets the reader.

const READER_SCOPE = ".wb-reader";

/** Read a balanced {...} block starting at braceIndex, returning its inner text and the index after it. */
function readBlock(css, braceIndex) {
    let depth = 0;
    for (let i = braceIndex; i < css.length; i++) {
        if (css[i] === "{") depth++;
        else if (css[i] === "}") {
            depth--;
            if (depth === 0)
                return { block: css.slice(braceIndex + 1, i), end: i + 1 };
        }
    }
    return { block: css.slice(braceIndex + 1), end: css.length };
}

/**
 * Prefix every selector with the given scope so authored CSS is contained to that element/tree, and
 * drop imports, IE expressions and script-ish values. Selectors that target the page root (:root,
 * html, body) or the scope itself map onto the scope, so `:root { --x: … }` styles the scoped element.
 */
export function scopeCss(rawCss, scope) {
    if (!rawCss) return "";

    const css = String(rawCss)
        .replace(/\/\*[\s\S]*?\*\//g, "") // comments
        .replace(/@import[^;]+;/gi, "") // no external stylesheet imports
        .replace(/expression\s*\(/gi, "(") // legacy IE CSS expressions
        .replace(/javascript:/gi, "")
        .replace(/behavior\s*:/gi, "x-behavior:");

    let out = "";
    let i = 0;
    while (i < css.length) {
        const braceIndex = css.indexOf("{", i);
        if (braceIndex === -1) break;
        const prelude = css.slice(i, braceIndex).trim();
        const { block, end } = readBlock(css, braceIndex);
        i = end;
        if (!prelude) continue;

        if (/^@(media|supports|container|layer)/i.test(prelude)) {
            out += `${prelude}{${scopeCss(block, scope)}}`; // scope the rules inside
        } else if (
            /^@(font-face|keyframes|-webkit-keyframes|page|charset)/i.test(
                prelude,
            )
        ) {
            out += `${prelude}{${block}}`; // no selectors to scope
        } else {
            const scoped = prelude
                .split(",")
                .map((selector) => {
                    const trimmed = selector.trim();
                    if (trimmed === "") return "";
                    if (
                        [scope, ":root", "html", "body"].includes(
                            trimmed.toLowerCase(),
                        )
                    ) {
                        return scope;
                    }
                    return `${scope} ${trimmed}`;
                })
                .filter(Boolean)
                .join(", ");
            if (scoped) out += `${scoped}{${block}}`;
        }
    }
    return out;
}

/** Scope a world's custom CSS to the reader root. */
export function scopeReaderCss(rawCss) {
    return scopeCss(rawCss, READER_SCOPE);
}

/** Scope one template block's CSS to just that block's element (see .wb-block-<id> in the reader). */
export function scopeBlockCss(rawCss, id) {
    return scopeCss(rawCss, `.wb-block-${id}`);
}
