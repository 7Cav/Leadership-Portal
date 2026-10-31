const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

// Forum date convention (`30MAY26`). Reads UTC components — timestamps are stored UTC (AD-11),
// so a local-time read would drift the displayed day for viewers outside that offset.
export function forumDate(value) {
    const date = new Date(value);
    const day = String(date.getUTCDate()).padStart(2, '0');
    const month = MONTHS[date.getUTCMonth()];
    const year = String(date.getUTCFullYear()).slice(-2);

    return `${day}${month}${year}`;
}

const DAY_MS = 86_400_000;

// A Cav week by both its dates, Saturday to Friday (`24OCT26 to 30OCT26`): the Google Form names a week by its end
// date and the portal its start, so the label shows both. The week runs from Saturday 00:00 UTC (AD-11), so a
// `week_start` names the week it opens and any other moment names the week it falls in, read at its UTC instant. Pass
// a `YYYY-MM-DD` date (read as UTC midnight) or a timestamp with an offset or `Z`: a time with no offset would be read
// in the viewer's local time. Only the label: weeks stay keyed by their start. The server's twin is
// `App\Support\Time\WeekLabel`.
function weekBounds(value) {
    const date = new Date(value);
    const midnight = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
    const saturday = midnight - ((date.getUTCDay() + 1) % 7) * DAY_MS;

    return [saturday, saturday + 6 * DAY_MS];
}

// The short form, for table columns, pickers, badges and tooltips: `24OCT26 to 30OCT26`.
export function weekLabel(value) {
    const [start, end] = weekBounds(value);

    return `${forumDate(start)} to ${forumDate(end)}`;
}

// The long form, for page titles, headers and home cards: `Week Ending 30OCT26 (24OCT26 to 30OCT26)`. It keeps the Google
// Form's "Week Ending" name and adds the range. Mid-sentence, write `the week ending 30OCT26 (24OCT26 to 30OCT26)`.
export function weekTitle(value) {
    return `Week Ending ${weekEnding(value)} (${weekLabel(value)})`;
}

// The long form mid-sentence, lower case: `week ending 30OCT26 (24OCT26 to 30OCT26)`. Where a sentence or a tight spot
// reads better without it (a list of weeks, a tooltip, a toast), use the short `weekLabel()`.
export function weekPhrase(value) {
    return `week ending ${weekEnding(value)} (${weekLabel(value)})`;
}

// The week's end date alone (`30OCT26`), the way the Google Form and its sheet name a week.
export function weekEnding(value) {
    return forumDate(weekBounds(value)[1]);
}

// A chart axis tick, no year: `24 to 30OCT`, or `31OCT to 06NOV` when the week spans two months.
export function weekTick(value) {
    const [start, end] = weekBounds(value).map((t) => forumDate(t).slice(0, 5));

    return start.slice(2) === end.slice(2) ? `${start.slice(0, 2)} to ${end}` : `${start} to ${end}`;
}

// A week's start date alone, no year: `24OCT`. For a spot too narrow for weekTick's range, like the ends of the
// roster's eight week strip.
export function weekStartTick(value) {
    return forumDate(weekBounds(value)[0]).slice(0, 5);
}

// The gap inside a Zulu time on screen: a hair space (U+200A), so `2359` reads as `23 59` without a colon.
export const HAIR_SPACE = '\u200A';

// A UTC 24h clock without the `Z`: `23 59` with a hair space on screen, or `2359` with `{ plain: true }` for text
// that leaves the portal (Discord, forum posts, the clipboard), where a hair space may not render. The one place the
// portal's time format is decided; the server sends ISO 8601 instants and words no times of day.
export function clockOf(value, { plain = false } = {}) {
    const date = new Date(value);
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');

    return `${hours}${plain ? '' : HAIR_SPACE}${minutes}`;
}

// A Zulu time: `23 59Z` on screen, `2359Z` plain.
export function zuluTime(value, options = {}) {
    return `${clockOf(value, options)}Z`;
}

// `forumDate()` plus a UTC 24h clock (no `Z`; callers add it) — an audit trail needs to order same-day entries,
// which the date-only convention can't do.
export function forumDateTime(value, options = {}) {
    return `${forumDate(value)} ${clockOf(value, options)}`;
}
