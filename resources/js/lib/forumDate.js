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

// `forumDate()` plus a UTC 24h clock — an audit trail needs to order same-day entries, which the
// date-only convention can't do.
export function forumDateTime(value) {
    const date = new Date(value);
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');

    return `${forumDate(value)} ${hours}:${minutes}`;
}
