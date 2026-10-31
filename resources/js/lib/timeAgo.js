// Relative and local readings of a portal timestamp. The Cav works in Zulu, so every stamp keeps its
// UTC text as the primary reading (`forumDateTime`, `14OCT26 2216Z`); these add how long ago that
// was and what it is on the viewer's own clock.

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function toDate(value) {
    if (value === null || value === undefined || value === '') return null;

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

// `just now`, `35 min ago`, `3 h ago`, `2 days ago`. Whole units, rounded down, so a stamp never
// reads as more recent than it is. Every stamp is something that already happened, so one ahead of
// the viewer's clock (a server time on a clock that runs slow) reads `just now`. Null for a missing
// or unreadable value.
export function timeAgo(value, now = Date.now()) {
    const date = toDate(value);
    if (!date) return null;

    const span = Number(now) - date.getTime();
    if (span < MINUTE) return 'just now';

    if (span < HOUR) return `${Math.floor(span / MINUTE)} min ago`;
    if (span < 2 * DAY) return `${Math.floor(span / HOUR)} h ago`;

    return `${Math.floor(span / DAY)} days ago`;
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
