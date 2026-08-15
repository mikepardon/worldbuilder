/**
 * Shared stat-block helpers. Consumed by StatblockCard.vue (article/live rendering) and by the
 * homebrew renderer (lib/homebrew.js), which serialises a block to a parchment-styled HTML card
 * since its output pipeline is sanitized HTML strings rather than mounted Vue components.
 */

import { wrapDice } from "./dice";

/**
 * Extract the compact stat data from hand-authored `{{monster …}}` blocks in brew content, so custom
 * monsters (not just compendium embeds) can appear in the reader's stat-block swiper.
 *
 * @returns {Array<{id: string, name: string, block: {ac?: string, hp?: string, cr?: string, abilities: object}}>}
 */
export function parseMonsterBlocks(content) {
    const blocks = [];
    // {{monster (,classes)? \n …inner… \n}}  — the one-line {{monster=id}} embed form won't match.
    const blockPattern = /\{\{monster[^\n]*\n([\s\S]*?)\n\}\}/g;
    let match;
    while ((match = blockPattern.exec(content ?? "")) !== null) {
        const inner = match[1];
        const name = (inner.match(/^\s*#{1,6}\s+(.+?)\s*$/m) || [])[1];
        if (!name) continue;

        const field = (label) => {
            const found = inner.match(
                new RegExp(`\\*\\*\\s*${label}\\s*\\*\\*\\s*([^\\n]+)`, "i"),
            );
            // Homebrewery writes stat lines as "**Armor Class** :: value" — drop the leading separator.
            return found ? found[1].trim().replace(/^::\s*/, "") : undefined;
        };

        const ac = field("Armor Class");
        const abilities = parseAbilityRow(inner);
        // Skip fragments that aren't a real stat block (e.g. the "Actions" column of a wide block).
        if (!ac && Object.keys(abilities).length === 0) continue;

        blocks.push({
            id: `custom-${blocks.length}`,
            name: name.replace(/[*_`]/g, "").trim(),
            block: {
                ac,
                hp: field("Hit Points"),
                cr: field("Challenge"),
                abilities,
            },
        });
    }
    return blocks;
}

/** Pull {str,dex,…} from a Homebrewery-style `| STR | DEX | … |` / `| 23 (+6) | … |` table. */
function parseAbilityRow(inner) {
    const lines = inner.split("\n");
    const keys = ["str", "dex", "con", "int", "wis", "cha"];
    for (let i = 0; i < lines.length; i++) {
        if (!/\|\s*str\s*\|/i.test(lines[i])) continue;
        // header row, then (usually) a separator row, then the values row.
        const valuesLine = /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1] || "")
            ? lines[i + 2]
            : lines[i + 1];
        const cells = (valuesLine || "")
            .split("|")
            .map((cell) => cell.trim())
            .filter((cell) => cell !== "");
        if (cells.length < 6) continue;
        const abilities = {};
        keys.forEach((key, index) => {
            const number = (cells[index].match(/-?\d+/) || [])[0];
            if (number !== undefined) abilities[key] = Number(number);
        });
        return abilities;
    }
    return {};
}

/** Escape a value for safe interpolation into an HTML string. DOMPurify is the final net; this keeps structure intact. */
export function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

/** "14" → "14 (+2)". Missing scores default to 10 (+0), matching the 5e convention. */
export function abilityModifier(score) {
    const modifier = Math.floor((Number(score || 10) - 10) / 2);
    return `${score} (${modifier >= 0 ? "+" : ""}${modifier})`;
}

/**
 * The optional single-line stat rows, in display order, with blank ones dropped.
 *
 * @returns {Array<[string, string]>}
 */
export function statblockLines(block) {
    return [
        ["Saving Throws", block?.saves],
        ["Skills", block?.skills],
        ["Damage Resistances", block?.resistances],
        ["Damage Immunities", block?.immunities],
        ["Condition Immunities", block?.conditionImmunities],
        ["Senses", block?.senses],
        ["Languages", block?.languages],
        ["Legendary Resistances", block?.legendaryResistance],
        ["Challenge", block?.cr],
    ].filter(([, value]) => value && String(value).trim());
}

/**
 * Normalise a block's spellcasting into { intro, groups: [{label, spells: [name]}] }, or undefined
 * when empty. Spells may be free text or compendium references; only the display name is used here.
 */
export function spellcasting(block) {
    const sc = block?.spellcasting;
    if (!sc || typeof sc !== "object") return undefined;
    const intro = String(sc.intro ?? "").trim();
    const groups = (Array.isArray(sc.groups) ? sc.groups : [])
        .map((group) => ({
            label: String(group?.label ?? "").trim(),
            // Keep the compendium id so linked spells can hover-preview / link to their entry.
            spells: (Array.isArray(group?.spells) ? group.spells : [])
                .map((s) => ({ name: String(s?.name ?? "").trim(), id: s?.id }))
                .filter((s) => s.name),
        }))
        .filter((group) => group.label || group.spells.length);
    if (!intro && groups.length === 0) return undefined;
    return { intro, groups };
}

const ACTION_GROUPS = {
    traits: "Traits",
    actions: "Actions",
    bonusActions: "Bonus Actions",
    reactions: "Reactions",
    legendary: "Legendary Actions",
    mythic: "Mythic Actions",
};

/**
 * Serialise a monster block to a parchment stat-block card (HTML string). Every interpolated value
 * is escaped; the markup mirrors StatblockCard.vue so the brew and article renderings stay in step.
 */
export function statblockToHtml(
    block,
    name,
    extraClasses = "",
    spells = [],
    image = "",
) {
    if (!block) return "";

    // Lookup so a spellcasting entry's compendium-linked spells can carry a hover summary.
    const spellById = new Map((spells ?? []).map((s) => [s.id, s]));
    const spellRef = (spell) => {
        const info = spell.id == null ? undefined : spellById.get(spell.id);
        if (!info) return `<em>${escapeHtml(spell.name)}</em>`;
        // No title attribute — the hover card (SpellPopover) replaces the native tooltip.
        return `<em class="spell-ref" role="button" tabindex="0" data-spell-id="${spell.id}">${escapeHtml(spell.name)}</em>`;
    };

    const abilities = block.abilities ?? {};
    // A grid cell per ability (label + "score (mod)"), the mod clickable to roll a d20 ability check.
    const abilityCell = (key, label) => {
        const mod = Math.floor((Number(abilities[key] ?? 10) - 10) / 2);
        const roll = `1d20${mod >= 0 ? "+" : ""}${mod}`;
        const score = `<span class="dice-roll" role="button" tabindex="0" data-roll="${roll}" data-min="1" data-label="${label} check" title="Roll ${label} check (${roll})">${escapeHtml(abilityModifier(abilities[key]))}</span>`;
        return `<div class="hb-sb-ability"><div class="hb-sb-ability-label">${label}</div><div class="hb-sb-ability-score">${score}</div></div>`;
    };
    const meta =
        [block.size, block.type].filter(Boolean).map(escapeHtml).join(" ") +
        (block.alignment ? `, ${escapeHtml(block.alignment)}` : "");
    const stat = (label, value) =>
        value
            ? `<div><strong>${label}</strong> ${wrapDice(escapeHtml(value))}</div>`
            : "";
    const lines = statblockLines(block)
        .map(
            ([label, value]) =>
                `<div><strong>${escapeHtml(label)}</strong> ${wrapDice(escapeHtml(value))}</div>`,
        )
        .join("");
    // Spellcasting is rendered as the first entry under Traits (intro, then a line per spell level).
    const sc = spellcasting(block);
    const spellHtml = sc
        ? `<p><strong><em>Spellcasting.</em></strong> ${wrapDice(escapeHtml(sc.intro))}</p>` +
          sc.groups
              .map(
                  (group) =>
                      `<p>${group.label ? `<strong>${escapeHtml(group.label)}:</strong> ` : ""}${group.spells.map(spellRef).join(", ")}</p>`,
              )
              .join("")
        : "";
    const groups = Object.entries(ACTION_GROUPS)
        .map(([key, title]) => {
            const entries = Array.isArray(block[key]) ? block[key] : [];
            const extra = key === "traits" ? spellHtml : "";
            if (entries.length === 0 && !extra) return "";
            const items = entries
                .map(
                    (entry) =>
                        `<p><strong><em>${escapeHtml(entry.name)}.</em></strong> ${wrapDice(escapeHtml(entry.desc))}</p>`,
                )
                .join("");
            return `<div class="hb-sb-group">${escapeHtml(title)}</div>${extra}${items}`;
        })
        .join("");

    return [
        `<div class="monster hb-statblock${extraClasses ? ` ${extraClasses}` : ""}">`,
        '<div style="display:flex;gap:12px;align-items:flex-start">',
        image
            ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(name)}" style="width:84px;height:84px;object-fit:cover;border-radius:6px;flex:none" />`
            : "",
        '<div style="min-width:0">',
        `<div class="hb-sb-name">${escapeHtml(name)}</div>`,
        `<div class="hb-sb-meta"><em>${meta}</em></div>`,
        "</div>",
        "</div>",
        '<div class="hb-sb-rule"></div>',
        stat("Armor Class", block.ac),
        stat("Hit Points", block.hp),
        stat("Speed", block.speed),
        '<div class="hb-sb-rule"></div>',
        `<div class="hb-sb-abilities">${abilityCell("str", "STR")}${abilityCell("dex", "DEX")}${abilityCell("con", "CON")}${abilityCell("int", "INT")}${abilityCell("wis", "WIS")}${abilityCell("cha", "CHA")}</div>`,
        lines ? `<div class="hb-sb-rule"></div>${lines}` : "",
        groups,
        "</div>",
    ].join("");
}
