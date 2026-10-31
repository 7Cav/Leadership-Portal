const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Forum date convention (`30May26`). Reads UTC components — timestamps are stored UTC (AD-11),
// so a local-time read would drift the displayed day for viewers outside that offset.
export function forumDate(value) {
    const date = new Date(value);
    const day = String(date.getUTCDate()).padStart(2, '0');
    const month = MONTHS[date.getUTCMonth()];
    const year = String(date.getUTCFullYear()).slice(-2);

    return `${day}${month}${year}`;
}

const DAY_MS = 86_400_000;

// A Cav week by its end date, the Friday, as the Google Form names it (`Week Ending 30Oct26`, #568). The week runs from
// Saturday 00:00 UTC (AD-11), so a `week_start` names the week it opens and any other moment names the week it falls
// in, read at its UTC instant. Pass a `YYYY-MM-DD` date (read as UTC midnight) or a timestamp with an offset or `Z`: a time\n// with no offset would be read in the viewer's local time. Only the label: weeks stay keyed by their start.
export function weekLabel(value) {
    const date = new Date(value);
    const daysToFriday = (12 - date.getUTCDay()) % 7;

    return forumDate(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()) + daysToFriday * DAY_MS);
}

// `forumDate()` plus a UTC 24h clock — an audit trail needs to order same-day entries, which the
// date-only convention can't do.
export function forumDateTime(value) {
    const date = new Date(value);
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');

    return `${forumDate(value)} ${hours}:${minutes}`;
}
