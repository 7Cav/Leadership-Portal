// Relative and local readings of a portal timestamp. The Cav works in Zulu, so every stamp keeps its
// UTC text as the primary reading (`forumDateTime`, `14Oct26 22:16Z`); these add how long ago that
// was and what it is on the viewer's own clock.

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function toDate(value) {
    if (value === null || value === undefined || value === '') return null;

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

// `just now`, `35 min ago`, `3 h ago`, `2 days ago`; a future time reads `in 5 min`. Whole units,
// rounded down, so a stamp never reads as more recent or further off than it is. Null for a
// missing or unreadable value.
export function timeAgo(value, now = Date.now()) {
    const date = toDate(value);
    if (!date) return null;

    const diff = Number(now) - date.getTime();
    const span = Math.abs(diff);
    if (span < MINUTE) return 'just now';

    let amount;
    if (span < HOUR) amount = `${Math.floor(span / MINUTE)} min`;
    else if (span < 2 * DAY) amount = `${Math.floor(span / HOUR)} h`;
    else amount = `${Math.floor(span / DAY)} days`;

    return diff > 0 ? `${amount} ago` : `in ${amount}`;
}

// The same moment on the viewer's clock, in their locale and time zone, with the zone named
// (`Fri, Oct 16, 12:16 AM GMT+2`). Null for a missing or unreadable value.
export function localTime(value, locale = undefined, timeZone = undefined) {
    const date = toDate(value);
    if (!date) return null;

    return new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short',
        timeZone,
    }).format(date);
}
