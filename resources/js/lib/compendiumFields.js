/** Render a compendium type's structured fields to Markdown for the live preview. Mirrors the PHP
 * renderer in app/Support/CompendiumFields.php (which regenerates the saved document on update). */
export function renderMarkdown(schema, fields, name) {
    const lines = [];
    const body = [];
    for (const field of schema ?? []) {
        const raw = fields?.[field.key];
        // Spell ranks render as their own labelled body section.
        if (field.type === 'levels') {
            const rendered = renderLevels(Array.isArray(raw) ? raw : []);
            if (rendered) body.push(rendered);
            continue;
        }
        // Structured fields (effects, grants) hold arrays/objects and aren't part of the document.
        if (raw && typeof raw === 'object') continue;
        const value = String(raw ?? '').trim();
        if (!value) continue;
        if (field.type === 'longtext') {
            body.push(field.key === 'description' ? value : `***${field.label}.*** ${value}`);
        } else {
            lines.push(`**${field.label}** ${value}`);
        }
    }

    let out = `#### ${name}\n\n`;
    if (lines.length) out += `${lines.join('\n')}\n\n`;
    if (body.length) out += `${body.join('\n\n')}\n`;
    return out.trim();
}

const LEVEL_FACETS = [
    ['casting_time', 'Casting'],
    ['range', 'Range'],
    ['targets', 'Targets'],
    ['components', 'Components'],
    ['duration', 'Duration'],
    ['min_level', 'Requires level'],
];

/** Render a spell's mana-costed ranks into a labelled document section. Mirrors CompendiumFields::renderLevels. */
function renderLevels(levels) {
    const rows = [];
    for (const level of levels) {
        if (!level || typeof level !== 'object') continue;
        const rank = String(level.level ?? '').trim();
        if (!rank) continue;
        const name = String(level.name ?? '').trim();
        const mana = String(level.mana ?? '').trim();
        let head = `**Level ${rank}${mana ? ` · ${mana} mana` : ''}${name ? ` · ${name}` : ''}**`;
        const facets = LEVEL_FACETS.map(([key, label]) => {
            const value = String(level[key] ?? '').trim();
            return value && value !== '0' ? `${label} ${value}` : '';
        }).filter(Boolean);
        if (facets.length) head += ` — ${facets.join(', ')}`;
        const description = String(level.description ?? '').trim();
        rows.push(description ? `${head}  \n${description}` : head);
    }
    return rows.length ? `***Ranks.***\n\n${rows.join('\n\n')}` : '';
}
