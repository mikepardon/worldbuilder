import DOMPurify from "dompurify";
import { wrapDice } from "./dice";
import { marked } from "marked";
import { escapeHtml, statblockToHtml } from "./statblock";

/**
 * Homebrewery-style renderer for freeform "brew" documents. Turns the source into an array of
 * sanitized page HTML strings. Supports raw HTML + inline CSS (positioned/blended images, div
 * classes), the curly-bracket syntax ({{class,#id,key:value ...}}), page/column breaks, and the
 * app's own tokens ([[wiki-links]], [embed=id], {{secret}}). All output is passed through DOMPurify.
 *
 * <style> blocks and the per-brew CSS editor are supported, but every selector is rewritten to sit
 * under the brew scope (.brew-page) and dangerous constructs (@import, expression(), javascript:,
 * behavior:) are stripped, so authored CSS can never restyle the rest of the reader. <script> and
 * event handlers are always removed by DOMPurify.
 */

const SCOPE = ".brew-page";

// Extended inline Markdown à la Homebrewery: ^superscript^, ~subscript~, ==highlight==.
const inlineExtension = (name, tag, startPattern, rule) => ({
    name,
    level: "inline",
    start(src) {
        return src.match(startPattern)?.index;
    },
    tokenizer(src) {
        const match = rule.exec(src);
        if (match) return { type: name, raw: match[0], text: match[1] };
    },
    renderer(token) {
        return `<${tag}>${escapeHtml(token.text)}</${tag}>`;
    },
});

marked.use({
    extensions: [
        inlineExtension("hbSuperscript", "sup", /\^/, /^\^(?!\s)([^^\n]+?)\^/),
        inlineExtension(
            "hbSubscript",
            "sub",
            /~(?!~)/,
            /^~(?!~)(?!\s)([^~\n]+?)~(?!~)/,
        ),
        inlineExtension("hbHighlight", "mark", /==/, /^==(?!\s)([^\n]+?)==/),
    ],
});

/** Read a balanced {...} block starting at braceIndex ('{'); returns the inner text and the index past '}'. */
function readBlock(css, braceIndex) {
    let depth = 0;
    for (let i = braceIndex; i < css.length; i++) {
        if (css[i] === "{") depth++;
        else if (css[i] === "}" && --depth === 0)
            return { block: css.slice(braceIndex + 1, i), end: i + 1 };
    }
    return { block: css.slice(braceIndex + 1), end: css.length };
}

/** Prefix every selector with the brew scope so authored CSS is contained; drop unsafe constructs. */
function scopeCss(rawCss) {
    let css = rawCss
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
            out += `${prelude}{${scopeCss(block)}}`; // scope the rules inside
        } else if (
            /^@(font-face|keyframes|-webkit-keyframes|page|charset)/i.test(
                prelude,
            )
        ) {
            out += `${prelude}{${block}}`; // no selectors to scope
        } else {
            const scoped = prelude
                .split(",")
                .map((sel) => `${SCOPE} ${sel.trim()}`)
                .join(", ");
            out += `${scoped}{${block}}`;
        }
    }
    return out;
}

const DOMPURIFY_CONFIG = {
    ADD_ATTR: ["colspan", "rowspan", "target"],
    FORBID_TAGS: [
        "script",
        "style",
        "iframe",
        "object",
        "embed",
        "link",
        "meta",
        "form",
        "input",
        "button",
        "textarea",
        "base",
    ],
    FORBID_ATTR: [
        "onerror",
        "onload",
        "onclick",
        "onmouseover",
        "onfocus",
        "onblur",
    ],
    // Links/images: http(s), mailto/tel, in-app relative + anchors, and inline base64 images only.
    ALLOWED_URI_REGEXP:
        /^(?:https?:|mailto:|tel:|\/|#|data:image\/(?:png|jpe?g|gif|webp|svg\+xml);base64,)/i,
};

/** Parse a curly tag list ("monster,wide" / 'pen,#author,color:orange,font-family:"trebuchet ms"'). */
function parseTags(raw) {
    const classes = [];
    let id = "";
    const styles = [];
    // Split on commas that are not inside double quotes.
    const parts = (raw ?? "").match(/(?:[^,"]|"[^"]*")+/g) || [];
    for (const part of parts) {
        const token = part.trim();
        if (!token) continue;
        if (token.startsWith("#")) id = token.slice(1);
        else if (token.includes(":")) styles.push(token);
        else classes.push(token.replace(/^\./, ""));
    }
    return { classes, id, styles };
}

/** Tag list → HTML attribute string (for {{ }} spans/blocks). */
function attrsFromTags(raw) {
    const { classes, id, styles } = parseTags(raw);
    let attrs = "";
    if (classes.length) attrs += ` class="${classes.join(" ")}"`;
    if (id) attrs += ` id="${id}"`;
    if (styles.length) attrs += ` style="${styles.join(";")}"`;
    return attrs;
}

/** Apply a tag list to a live element (for bare {..} injection). */
function applyTags(element, raw) {
    const { classes, id, styles } = parseTags(raw);
    classes.forEach((c) => element.classList.add(c));
    if (id) element.id = id;
    if (styles.length) {
        const existing = element.getAttribute("style");
        element.setAttribute(
            "style",
            [existing, styles.join(";")].filter(Boolean).join(";"),
        );
    }
}

/**
 * Homebrewery "injection": a bare {key:value,.class,#id} applies to the element right before it.
 * Runs on the rendered HTML — a <p> that is only {..} styles the previous block; a leading {..} text
 * node styles the inline element before it (e.g. *word* {color:red} or ![img](url){width:100px}).
 */
function applyInjections(html) {
    const doc = new DOMParser().parseFromString(
        `<body>${html}</body>`,
        "text/html",
    );

    doc.querySelectorAll("p").forEach((paragraph) => {
        const match = paragraph.textContent.match(/^\s*\{([^{}]+)\}\s*$/);
        if (match && paragraph.previousElementSibling) {
            applyTags(paragraph.previousElementSibling, match[1]);
            paragraph.remove();
        }
    });

    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);
    for (const node of textNodes) {
        const match = node.nodeValue.match(/^\s*\{([^{}]+)\}/);
        if (match && node.previousElementSibling) {
            applyTags(node.previousElementSibling, match[1]);
            node.nodeValue = node.nodeValue.slice(match[0].length);
        }
    }

    // Give every heading a slug id so a {{toc}} / [link](#slug) can scroll to it.
    const usedIds = new Set();
    doc.querySelectorAll("h1, h2, h3, h4, h5, h6").forEach((heading) => {
        if (heading.id) {
            usedIds.add(heading.id);
            return;
        }
        const base = slugify(heading.textContent || "");
        let id = base;
        let n = 2;
        while (usedIds.has(id)) id = `${base}-${n++}`;
        usedIds.add(id);
        heading.id = id;
    });

    return doc.body.innerHTML;
}

// {{monster=1}} / {{armor=3,frame,wide}} — a compendium embed with optional layout classes after the id.
const EMBED_TOKEN = /\{\{[a-z]+=(\d+)((?:,[^}\n]*)?)\}\}/gi;

/** Parse the trailing ",frame,wide" of an embed token into a safe, space-joined class string. */
function embedClasses(raw) {
    return (raw || "")
        .split(",")
        .map((token) => token.trim())
        .filter((token) => /^[a-z][a-z0-9-]*$/i.test(token))
        .join(" ");
}

/** Map a non-monster compendium type to the parchment card class that best matches it. */
function embedCardClass(itemType) {
    if (itemType === "spell") return "spell";
    if (itemType === "item" || itemType === "magicitem") return "item";
    return "hb-embed";
}

/**
 * A [statblock=id]/[spell=id]/… embed → its full parchment card. Monsters become a stat block;
 * other entries become a titled card wrapping the item's Markdown body (rendered by the pipeline
 * below). Falls back to a chip when the entry has no renderable body, or a note when it is missing.
 */
function renderEmbed(embed, id, classes = "", spells = []) {
    if (!embed)
        return `\n\n<div class="hb-embed-missing">Embedded entry #${escapeHtml(id)} is unavailable.</div>\n\n`;

    if (embed.item_type === "monster" && embed.block) {
        return `\n\n${statblockToHtml(embed.block, embed.name, classes, spells, embed.image_url ?? "")}\n\n`;
    }

    const body = (embed.document ?? "").trim();
    if (embed.item_type !== "monster" && body) {
        const label = embed.typeLabel
            ? `<span class="hb-embed-type">${escapeHtml(embed.typeLabel)}</span>`
            : "";
        const head = `<div class="hb-embed-head"><span class="hb-embed-name">${escapeHtml(embed.name)}</span>${label}</div>`;
        // Blank lines around the body so Markdown inside the card is parsed (mirrors {{secret}}).
        return `\n\n<div class="hb-embed-card ${embedCardClass(embed.item_type)}${classes ? ` ${classes}` : ""}">\n${head}\n\n${body}\n\n</div>\n\n`;
    }

    return `<span class="embed-chip">▤ ${escapeHtml(embed.name)}</span>`;
}

/**
 * A GM-only {{secret}} box. With `reveal`, it carries a "Reveal to players" control (a span, not a
 * <button>, which the sanitizer forbids) tagged with the secret's 0-based index — matching
 * Secrets::reveal server-side — so the article renderer can publish it via event delegation.
 */
function secretBox(inner, index, reveal) {
    if (!reveal) return `\n\n<div class="hb-secret">\n\n${inner}\n\n</div>\n\n`;
    const head = `<div class="hb-secret-head"><span class="hb-secret-tag">GM only · hidden from players</span><span role="button" tabindex="0" class="hb-reveal" data-reveal="${index}" title="Permanently publish this hidden block so your players can see it in the entry. This can't be undone from here.">Reveal to players</span></div>`;
    return `\n\n<div class="hb-secret hb-secret-live">\n${head}\n\n${inner}\n\n</div>\n\n`;
}

/** Resolve the app's own inline tokens to final HTML before Markdown/curly processing. */
function renderAppTokens(
    source,
    {
        entries = [],
        embeds = [],
        gm = true,
        reveal = false,
        wikiBase = "/documents/",
        wikiSuffix = "/edit",
        spells = [],
    },
) {
    let text = source;

    // {{secret}}…{{/}} — GM sees a boxed secret; players get it stripped. Index counts every block in order.
    let secretIndex = 0;
    text = text.replace(/\{\{secret\}\}([\s\S]*?)\{\{\/\}\}/g, (_, inner) => {
        const index = secretIndex++;
        return gm ? secretBox(inner, index, reveal) : "";
    });

    // Embeds before wiki-links so a card's Markdown body can itself contain [[wiki-links]].
    text = text.replace(EMBED_TOKEN, (_, id, classesRaw) =>
        renderEmbed(
            embeds.find((e) => e.id === Number(id)),
            id,
            embedClasses(classesRaw),
            spells,
        ),
    );

    const byTitle = {};
    for (const entry of entries) byTitle[entry.title.toLowerCase()] = entry;
    text = text.replace(/\[\[([^\[\]]+)\]\]/g, (_, inner) => {
        const [rawTitle, rawDisplay] = inner.split("|");
        const display = (rawDisplay ?? rawTitle).trim();
        const target = byTitle[rawTitle.trim().toLowerCase()];
        if (!target) return `<span class="wikilink-missing">${display}</span>`;
        // Readers link by /{type}/{slug}; the editor (targets without a slug) links by id.
        const href = target.slug
            ? `${wikiBase}${target.type}/${target.slug}${wikiSuffix}`
            : `${wikiBase}${target.id}${wikiSuffix}`;
        return `<a class="wikilink" href="${href}">${display}</a>`;
    });

    // @-mentions: @[Name](char:id) → a character chip. Done here (before Markdown) so the [](…) form
    // isn't parsed as a Markdown link.
    text = text.replace(
        /@\[([^\]]+)\]\(char:(\d+)\)/g,
        (_, name, id) =>
            `<span class="hb-mention" data-char-id="${id}" style="color:#8aa6ff;font-weight:600">@${escapeHtml(name.trim())}</span>`,
    );

    return text;
}

/** Convert Homebrewery {{ }} spans and blocks into HTML divs/spans. */
function convertCurly(source) {
    // Inline span: {{tags content}} on a single line (no braces/newlines inside).
    let text = source.replace(/\{\{((?:[^{}\n])+)\}\}/g, (match, inner) => {
        const space = inner.indexOf(" ");
        // No content → an empty styled span, e.g. Font Awesome icons: {{fas,fa-tint}} → <span class="fas fa-tint">.
        if (space === -1) return `<span${attrsFromTags(inner)}></span>`;
        return `<span${attrsFromTags(inner.slice(0, space))}>${inner.slice(space + 1)}</span>`;
    });

    // Block open ({{tags on its own line) / close (}} on its own line). Blank lines keep inner Markdown alive.
    return text
        .split("\n")
        .map((line) => {
            const trimmed = line.trim();
            if (trimmed === "}}") return "\n\n</div>\n";
            const opener = trimmed.match(/^\{\{([^\s{}][^{}]*)?$/);
            if (opener) return `\n<div${attrsFromTags(opener[1] || "")}>\n`;
            return line;
        })
        .join("\n");
}

/** Slugify heading text into an anchor id. Strips inline Markdown so it matches the rendered heading. */
function slugify(text) {
    return (
        (text || "")
            .replace(/\[([^\]]*)\]\([^)]*\)/g, "$1") // [text](url) → text
            .replace(/[*_`~]/g, "")
            .toLowerCase()
            .replace(/[^\w\s-]/g, "")
            .trim()
            .replace(/\s+/g, "-") || "section"
    );
}

/**
 * Auto-generate a table of contents from the document's headings (h1–h3), wherever `{{toc}}` appears.
 * Each entry links to the heading's slug id and shows the page it lands on (counted from \page breaks).
 */
function convertToc(text) {
    if (!text.includes("{{toc}}")) return text;

    const used = new Set();
    const entries = [];
    let page = 1;
    for (const line of text.split("\n")) {
        if (/^\\page\s*$/.test(line)) {
            page += 1;
            continue;
        }
        const heading = line.match(/^(#{1,3})\s+(.+?)\s*$/);
        if (!heading) continue;
        const title = heading[2].trim();
        let id = slugify(title);
        let n = 2;
        while (used.has(id)) id = `${slugify(title)}-${n++}`;
        used.add(id);
        entries.push({ level: heading[1].length, title, id, page });
    }

    const items = entries
        .map(
            (entry) =>
                `<div class="toc-item toc-h${entry.level}"><a href="#${entry.id}"><span class="toc-title">${escapeHtml(entry.title.replace(/[*_`]/g, ""))}</span><span class="toc-page">${entry.page}</span></a></div>`,
        )
        .join("");
    return text.replace(
        /\{\{toc\}\}/g,
        `\n<div class="toc wide">\n${items}\n</div>\n`,
    );
}

/**
 * Markdown definition lists → <dl>. A term line immediately followed by one or more ":  definition"
 * lines becomes a <dt>/<dd> group. Terms/definitions are plain text (kept simple; no inline Markdown).
 *
 *   Difficult Terrain
 *   : Costs 2 feet of movement for every 1 foot travelled.
 */
function convertDefinitionLists(text) {
    const lines = text.split("\n");
    const output = [];
    let index = 0;
    while (index < lines.length) {
        const term = lines[index];
        const definitionFollows =
            index + 1 < lines.length && /^:\s+\S/.test(lines[index + 1]);
        // A term is a non-blank line that isn't itself another block marker (heading, quote, list, table…).
        if (
            term.trim() !== "" &&
            !/^\s*[:#>|)\-*+]/.test(term) &&
            definitionFollows
        ) {
            const items = [`<dt>${escapeHtml(term.trim())}</dt>`];
            index += 1;
            while (index < lines.length && /^:\s+(\S.*)$/.test(lines[index])) {
                items.push(
                    `<dd>${escapeHtml(lines[index].replace(/^:\s+/, ""))}</dd>`,
                );
                index += 1;
            }
            output.push(`\n<dl>${items.join("")}</dl>\n`);
            continue;
        }
        output.push(term);
        index += 1;
    }
    return output.join("\n");
}

// "___" → PHB divider; ":::" → vertical space.
function convertSpacing(text) {
    return (
        text
            .replace(/^___\s*$/gm, '\n<hr class="hb-divider">\n')
            // Homebrewery's " :: " label separator (e.g. "**Armor Class** :: 21") collapses to a space.
            .replace(/ :: /g, " ")
            .replace(
                /^(:{2,})\s*$/gm,
                (_, colons) =>
                    `\n<div class="hb-vspace" style="height:${colons.length * 9}px"></div>\n`,
            )
    );
}

const TABLE_DELIMITER = /^\s*\|?\s*:?-{1,}:?\s*(?:\|\s*:?-{1,}:?\s*)*\|?\s*$/;

/** Split a table row into raw cell strings, dropping only the pipes that fence the row's ends. */
function splitTableRow(line) {
    // Split on pipes that are not escaped (\|). Interior empties are meaningful (colspan).
    const cells = line.trim().split(/(?<!\\)\|/);
    if (cells.length && cells[0].trim() === "") cells.shift(); // leading fence pipe
    if (cells.length && cells[cells.length - 1].trim() === "") cells.pop(); // trailing fence pipe
    return cells.map((cell) => cell.replace(/\\\|/g, "|"));
}

/** A row uses spans if it has an interior empty cell (colspan) or a caret-only cell (rowspan). */
function rowHasSpan(line) {
    return splitTableRow(line).some((cell) => {
        const trimmed = cell.trim();
        return trimmed === "" || /^\^+$/.test(trimmed);
    });
}

/** Per-column text alignment from the delimiter row (`:--`, `:-:`, `--:`). */
function parseAlignments(delimiterLine) {
    return splitTableRow(delimiterLine).map((cell) => {
        const trimmed = cell.trim();
        const left = trimmed.startsWith(":");
        const right = trimmed.endsWith(":");
        return left && right ? "center" : right ? "right" : left ? "left" : "";
    });
}

/**
 * Build a grid of cell objects from raw rows, resolving `||` (empty cell → previous cell's
 * colspan) and `^` (caret-only cell → the cell above's rowspan). Cells merged upward are dropped.
 *
 * @returns {Array<Array<{startColumn: number, colspan: number, rowspan: number, content: string, header: boolean, dropped: boolean}>>}
 */
function buildTableGrid(rows) {
    const grid = rows.map((row) => {
        const cells = [];
        let column = 0;
        let previous;
        for (const token of row.tokens) {
            const trimmed = token.trim();
            if (trimmed === "" && previous) {
                previous.colspan += 1;
                column += 1;
                continue;
            }
            const cell = {
                startColumn: column,
                colspan: 1,
                rowspan: 1,
                content: trimmed,
                header: row.header,
                caret: /^\^+$/.test(trimmed),
                dropped: false,
            };
            cells.push(cell);
            column += 1;
            previous = cell;
        }
        return cells;
    });

    grid.forEach((cells, rowIndex) => {
        for (const cell of cells) {
            if (!cell.caret) continue;
            let owner;
            // A rowspan must stay within its section: a body caret never merges into the header.
            for (let above = rowIndex - 1; above >= 0 && !owner; above--) {
                if (rows[above].header !== rows[rowIndex].header) break;
                owner = grid[above].find(
                    (candidate) =>
                        candidate.startColumn === cell.startColumn &&
                        !candidate.dropped,
                );
            }
            if (owner) {
                owner.rowspan += 1;
                cell.dropped = true;
            } else {
                cell.caret = false; // orphan caret: nothing above to merge into, render literally
            }
        }
    });

    return grid;
}

/** Render one detected span-table block to a raw HTML <table> (inline Markdown per cell). */
function renderSpanTable(headerLine, delimiterLine, bodyLines) {
    const alignments = parseAlignments(delimiterLine);
    const rows = [
        { header: true, tokens: splitTableRow(headerLine) },
        ...bodyLines.map((line) => ({
            header: false,
            tokens: splitTableRow(line),
        })),
    ];
    const grid = buildTableGrid(rows);

    const renderRow = (cells) => {
        const inner = cells
            .filter((cell) => !cell.dropped)
            .map((cell) => {
                const tag = cell.header ? "th" : "td";
                const alignment = alignments[cell.startColumn];
                const attributes = [
                    cell.colspan > 1 ? ` colspan="${cell.colspan}"` : "",
                    cell.rowspan > 1 ? ` rowspan="${cell.rowspan}"` : "",
                    alignment ? ` style="text-align:${alignment}"` : "",
                ].join("");
                return `<${tag}${attributes}>${marked.parseInline(cell.content, { gfm: true })}</${tag}>`;
            })
            .join("");
        return `<tr>${inner}</tr>`;
    };

    const headerRows = grid.filter((cells) =>
        cells.some((cell) => cell.header),
    );
    const bodyRows = grid.filter((cells) => !cells.some((cell) => cell.header));
    const thead = headerRows.length
        ? `<thead>${headerRows.map(renderRow).join("")}</thead>`
        : "";
    const tbody = bodyRows.length
        ? `<tbody>${bodyRows.map(renderRow).join("")}</tbody>`
        : "";

    return `<table class="hb-table">${thead}${tbody}</table>`;
}

/**
 * Pre-render pipe tables that use span markers (`||` colspan, `^` rowspan) to raw HTML, since GFM
 * tables have no notion of merged cells. Plain tables are left untouched for marked's own renderer.
 */
function convertSpanTables(text) {
    const lines = text.split("\n");
    const output = [];
    let index = 0;
    while (index < lines.length) {
        const headerLine = lines[index];
        const delimiterLine = lines[index + 1];
        const looksLikeTable =
            headerLine.includes("|") &&
            headerLine.trim() !== "" &&
            delimiterLine !== undefined &&
            TABLE_DELIMITER.test(delimiterLine);

        if (looksLikeTable) {
            const bodyLines = [];
            let cursor = index + 2;
            while (
                cursor < lines.length &&
                lines[cursor].includes("|") &&
                lines[cursor].trim() !== ""
            ) {
                bodyLines.push(lines[cursor]);
                cursor += 1;
            }
            if ([headerLine, ...bodyLines].some(rowHasSpan)) {
                output.push(
                    "",
                    renderSpanTable(headerLine, delimiterLine, bodyLines),
                    "",
                );
                index = cursor;
                continue;
            }
        }

        output.push(headerLine);
        index += 1;
    }
    return output.join("\n");
}

/** Strip <style> blocks out of the source and return them scoped + merged with the CSS editor field. */
function extractStyleCss(source, optionCss) {
    const styleBlocks = [];
    const text = (source || "").replace(
        /<style[^>]*>([\s\S]*?)<\/style>/gi,
        (_, css) => {
            styleBlocks.push(css);
            return "";
        },
    );
    return {
        text,
        css: scopeCss([optionCss ?? "", ...styleBlocks].join("\n")),
    };
}

// Ability-score cells like "9 (-1)" / "16 (+3)" → a d20 check on the modifier. The parenthesised
// value is just a sign + digits (never dice), so this never collides with wrapped damage like "(2d6+3)".
function wrapAbilityChecks(html) {
    return html.replace(
        /\b(\d+)\s*\(\s*([+\-−]\d+)\s*\)/g,
        (match, _score, modifier) => {
            const roll = `1d20${modifier.replace("−", "-")}`;
            return `<span class="dice-roll" role="button" tabindex="0" data-roll="${roll}" data-min="1" data-label="Ability check" title="Roll ability check (${roll})">${match}</span>`;
        },
    );
}

// Make dice expressions (and ability-score cells) inside hand-authored {{monster …}} blocks clickable
// (compendium embeds get this via statblockToHtml). Runs while the block is still literal, before
// convertCurly wraps it. Damage is wrapped first so ability-cell wrapping can't touch "(2d6+3)".
function wrapDiceInMonsterBlocks(text) {
    return text.replace(
        /(\{\{monster[^\n]*\n)([\s\S]*?)(\n\}\})/g,
        (match, open, body, close) =>
            `${open}${wrapAbilityChecks(wrapDice(body))}${close}`,
    );
}

/** Run the shared token/curly/table/spacing transforms. `reveal` makes {{secret}} boxes publishable. */
function transformBrew(text, options, reveal) {
    let out = renderAppTokens(text, { ...options, reveal });
    out = wrapDiceInMonsterBlocks(out);
    out = convertToc(out);
    out = convertCurly(out);
    out = convertSpanTables(out);
    out = convertDefinitionLists(out);
    out = convertSpacing(out);
    return out;
}

/**
 * @param {string} source
 * @param {{ entries?: any[], embeds?: any[], gm?: boolean, css?: string }} options
 *   embeds: compendium entries ({id, name, item_type, typeLabel, block?, document?}) an [embed=id] can render as a full card.
 * @returns {{ pages: string[], css: string }} sanitized page HTML + scoped, safe CSS.
 */
export function renderBrew(source, options = {}) {
    const { text, css } = extractStyleCss(source, options.css);
    const transformed = transformBrew(text, options, false);

    const pages = transformed.split(/^\\page\s*$/m).map((pageText) => {
        const withColumns = pageText.replace(
            /^\\column\s*$/m,
            '\n\n<div class="hb-colbreak"></div>\n\n',
        );
        const html = applyInjections(
            marked.parse(withColumns, { breaks: true, gfm: true }),
        );
        return DOMPurify.sanitize(html, DOMPURIFY_CONFIG);
    });

    return { pages, css };
}

/**
 * Render brew source to a single continuous HTML string (no page sheets) for embedding in a themed
 * container — used by the article renderer, which flows it into 2 columns. `\page` becomes a
 * full-width section divider, `\column` a column break, and {{secret}} boxes carry a reveal control.
 *
 * @param {string} source
 * @param {{ entries?: any[], embeds?: any[], gm?: boolean, css?: string }} options
 * @returns {{ html: string, css: string }} sanitized HTML + scoped, safe CSS.
 */
export function renderBrewInline(source, options = {}) {
    const { text, css } = extractStyleCss(source, options.css);
    const transformed = transformBrew(text, options, true)
        .replace(/^\\page\s*$/gm, '\n\n<hr class="hb-pagebreak">\n\n')
        .replace(/^\\column\s*$/gm, '\n\n<div class="hb-colbreak"></div>\n\n');

    const html = DOMPurify.sanitize(
        applyInjections(marked.parse(transformed, { breaks: true, gfm: true })),
        DOMPURIFY_CONFIG,
    );
    return { html, css };
}

/** @returns {string[]} one sanitized HTML string per page (CSS ignored). */
export function renderBrewPages(source, options = {}) {
    return renderBrew(source, options).pages;
}
