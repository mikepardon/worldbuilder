// Shared entry-variable and block-rule logic for the template blocks, so the reader block and the
// structural scaffold (Article.vue) resolve variables and evaluate visibility rules identically.

/**
 * The variables a template block can pull in for an entry: its quick-facts fields by key, plus a few
 * entry basics and computed values.
 */
export function buildVars(entry, facts = [], counts = {}) {
    const vars = {};
    for (const fact of facts) {
        if (fact.key) vars[fact.key] = fact.value ?? "";
    }
    if (entry) {
        vars.title = entry.title ?? "";
        vars.summary = entry.summary ?? "";
        vars.kind = entry.kindLabel ?? "";
        vars.reading_time = String(entry.reading_minutes ?? "");
        vars.word_count = String(entry.word_count ?? "");
        vars.related_count = String(counts.related ?? 0);
        vars.connection_count = String(counts.connections ?? 0);
    }
    return vars;
}

/**
 * Replace {{ key }} tokens with their variable, with an optional {{ key | fallback }} used when the
 * variable is missing or empty. An itemScope (for the repeater) merges on top. Never matches the markdown
 * embed syntax ({{monster=id}}) because that contains "=".
 */
export function resolveVars(text, vars, itemScope) {
    if (typeof text !== "string" || !text.includes("{{")) return text;
    const scope = itemScope ? { ...vars, ...itemScope } : vars;
    return text.replace(
        /\{\{\s*([\w.]+)\s*(?:\|([^}]*))?\}\}/g,
        (match, key, fallback) => {
            const value = scope[key];
            if (value !== undefined && value !== "") return value;
            if (fallback !== undefined) return fallback.trim();
            return match;
        },
    );
}

/**
 * The responsive class for a block's `device` setting: hide it on the other breakpoint. "all" (the
 * default) returns no class.
 */
export function deviceClass(block) {
    if (block?.device === "desktop") return "wb-only-desktop";
    if (block?.device === "mobile") return "wb-only-mobile";
    return "";
}

/**
 * The full wrapper class for a block: its per-block CSS scope plus any responsive class.
 */
export function blockWrapperClass(block) {
    return [`wb-block-${block.id}`, deviceClass(block)].filter(Boolean);
}

/**
 * Evaluate a block's optional visibility rule ({ field, op, value }) against its variables. No rule (or no
 * field) means "always shown".
 */
export function ruleAllows(rule, vars) {
    if (!rule || !rule.field) return true;
    const actual = (vars[rule.field] ?? "").toString().trim().toLowerCase();
    const value = (rule.value ?? "").toString().trim().toLowerCase();
    if (rule.op === "unset") return actual === "";
    if (rule.op === "eq") return actual === value;
    if (rule.op === "ne") return actual !== value;
    return actual !== ""; // "set" (default)
}
