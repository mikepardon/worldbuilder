/** Render a compendium type's structured fields to Markdown for the live preview. Mirrors the PHP
 * renderer in app/Support/CompendiumFields.php (which regenerates the saved document on update). */
export function renderMarkdown(schema, fields, name) {
    const lines = [];
    const body = [];
    for (const field of schema ?? []) {
        const value = String(fields?.[field.key] ?? '').trim();
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
