// Play status shared by arcs and sessions on the campaign board — the label plus the pill/ring
// classes each status uses. Kept in one place so the board and the session panel agree.

export const STATUS_META = {
    played: { label: "Played", pill: "bg-white/10 text-faint", ring: "ring-edge3" },
    playing: { label: "Playing", pill: "bg-amber/15 text-amber", ring: "ring-amber" },
    to_play: { label: "To play", pill: "bg-teal/15 text-teal", ring: "ring-teal" },
};

export const statusMeta = (status) => STATUS_META[status] ?? STATUS_META.to_play;

export const STATUS_ORDER = ["to_play", "playing", "played"];

export const nextStatus = (status) =>
    STATUS_ORDER[(STATUS_ORDER.indexOf(status) + 1) % STATUS_ORDER.length];
