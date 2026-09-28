// Shared entry-kind and family colours for the World Bible views (the radial wheel and the
// connections web), so both agree on hues without each keeping its own copy of the map.

export const KIND_COLOURS = {
    article: "#c9a94e",
    location: "#4e91c9",
    npc: "#c96f4e",
    faction: "#8a4ec9",
    bloodline: "#c98a4e",
    timeline: "#4ec9a0",
    item: "#c9c14e",
    rule: "#7d8590",
    session: "#c94e8a",
    quest: "#c9834e",
    lore: "#4ec96f",
    spell: "#4e6fc9",
    statblock: "#b0554e",
};

export const colourFor = (kind) => KIND_COLOURS[kind] ?? "#4e91c9";

// One hue per reader section (see App\Support\Sections::SECTIONS), plus the "other" catch-all.
export const FAMILY_COLOURS = {
    articles: "#c9a94e",
    locations: "#4e91c9",
    people: "#c96f4e",
    timelines: "#4ec9a0",
    data: "#c9c14e",
    sessions: "#c94e8a",
    lore: "#4ec96f",
    other: "#7d8590",
};

export const familyColourFor = (slug) => FAMILY_COLOURS[slug] ?? "#4e91c9";
