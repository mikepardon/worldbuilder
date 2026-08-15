const OPTIONS = {
    long: { day: "numeric", month: "long", year: "numeric" },
    medium: { day: "numeric", month: "short", year: "numeric" },
    short: { day: "2-digit", month: "2-digit", year: "numeric" },
};

// Parse a date-only string as local (avoids the UTC-midnight off-by-one), otherwise defer to Date.
function toDate(iso) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
    if (match) {
        return new Date(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]),
        );
    }
    return new Date(iso);
}

// Whether an ISO timestamp is within the last `days` days (for the "New" badge).
export function isRecent(iso, days = 7) {
    if (!iso) {
        return false;
    }
    const then = Date.parse(iso);
    return (
        !Number.isNaN(then) && Date.now() - then < days * 24 * 60 * 60 * 1000
    );
}

// Format an ISO date for the reader per the world's chosen style. Read-only formatting via Intl —
// no date arithmetic, so no mutability concerns.
export function formatReaderDate(iso, format = "medium") {
    if (!iso) {
        return "";
    }
    const date = toDate(iso);
    if (Number.isNaN(date.getTime())) {
        return String(iso);
    }
    return new Intl.DateTimeFormat(
        undefined,
        OPTIONS[format] ?? OPTIONS.medium,
    ).format(date);
}
