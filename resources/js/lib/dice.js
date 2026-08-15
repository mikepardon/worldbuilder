/** Dice-rolling helpers for the stat block: parse "2d6+3" style expressions and wrap them for clicking. */

/**
 * Roll a dice expression like "2d6+3", "d20", "4d8 + 4". Returns the individual rolls, the flat
 * modifier and the (floored-at-zero) total, or undefined if the expression isn't valid dice.
 *
 * @returns {{ rolls: number[], modifier: number, total: number, expr: string }|undefined}
 */
export function rollDice(expression, minTotal = 0) {
    const cleaned = String(expression ?? "").replace(/\s+/g, "");
    const match = cleaned.match(/^(\d*)d(\d+)([+-]\d+)?$/i);
    if (!match) return undefined;

    const count = Math.min(parseInt(match[1] || "1", 10), 100); // cap to keep a stray "999d6" harmless
    const sides = parseInt(match[2], 10);
    const modifier = parseInt(match[3] || "0", 10);
    if (!sides || count < 1) return undefined;

    const rolls = [];
    for (let i = 0; i < count; i += 1) {
        rolls.push(Math.floor(Math.random() * sides) + 1);
    }
    // Ability checks pass minTotal 1 (a check never totals below 1); damage stays floored at 0.
    const total = Math.max(
        minTotal,
        rolls.reduce((sum, roll) => sum + roll, 0) + modifier,
    );

    return { rolls, modifier, total, expr: cleaned };
}

/**
 * Wrap every dice expression in an ALREADY HTML-ESCAPED string with a clickable span. Dice tokens are
 * ASCII (digits/d/+/-), so escaping never alters them; the word boundary avoids matching inside words.
 */
export function wrapDice(escaped) {
    const html = String(escaped ?? "").replace(
        /\b(\d+)?d(\d+)(\s*[+-]\s*\d+)?/gi,
        (match) => {
            const roll = match.replace(/\s+/g, "");
            return `<span class="dice-roll" role="button" tabindex="0" data-roll="${roll}" title="Roll ${roll}">${match}</span>`;
        },
    );

    // Attack modifiers like "+7 to hit" (or "-1 to hit") roll a plain d20 plus the modifier. This runs
    // after the dice pass so the "1d20+7" we inject can't be re-matched by the dice regex above.
    return html.replace(/([+-]\s*\d+)(?=\s+to\s+hit)/gi, (match) => {
        const roll = `1d20${match.replace(/\s+/g, "")}`;
        return `<span class="dice-roll" role="button" tabindex="0" data-roll="${roll}" title="Roll ${roll} to hit">${match}</span>`;
    });
}
