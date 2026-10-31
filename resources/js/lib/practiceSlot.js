import { zuluTime } from '@/lib/forumDate';

// A section's weekly practice slot (#392): `{ weekday, startMinute }`, weekday 0 Sunday to 6 Saturday and the start in
// minutes after 00:00Z. A section practice is always one hour. Times are entered and shown in Zulu; the viewer's local
// time is only a hint beside them.

export const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
export const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const DAY_MS = 86_400_000;
const MINUTE_MS = 60_000;

// Any instant on the slot's weekday and time, for formatting: 04JAN 1970 was a Sunday.
function sample(weekday, startMinute) {
    return new Date(Date.UTC(1970, 0, 4) + weekday * DAY_MS + startMinute * MINUTE_MS);
}

// `Thu 02 00Z` on screen, `Thu 0200Z` plain.
export function slotLabel(slot, options = {}) {
    return `${WEEKDAYS[slot.weekday]} ${zuluTime(sample(slot.weekday, slot.startMinute), options)}`;
}

// A practice start as an instant, the same way: `Fri 01 00Z`.
export function startLabel(value, options = {}) {
    const date = new Date(value);

    return `${WEEKDAYS[date.getUTCDay()]} ${zuluTime(date, options)}`;
}

// The next start of the slot at or after `now`.
export function nextStart(slot, now = new Date()) {
    const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
    let start = today + ((slot.weekday - now.getUTCDay() + 7) % 7) * DAY_MS + slot.startMinute * MINUTE_MS;
    if (start < now.getTime()) start += 7 * DAY_MS;

    return new Date(start);
}

// The viewer's own clock for an instant, as a hint beside the Zulu time: `Wed 22:00` in the browser's zone.
export function localLabel(value, locale = undefined, timeZone = undefined) {
    return new Intl.DateTimeFormat(locale, { weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date(value));
}

// `HH:MM` for a time input, from minutes after midnight, and back.
export function minuteToTime(minute) {
    return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
}

export function timeToMinute(value) {
    const match = /^(\d{2}):(\d{2})$/.exec(value ?? '');
    if (!match) return null;
    const minute = Number(match[1]) * 60 + Number(match[2]);

    return minute >= 0 && minute < 1440 ? minute : null;
}

// The instant a weekday and start fall on in the Cav week opening `weekStart` (a `YYYY-MM-DD` Saturday): the week opens
// on Saturday, so Saturday is its day 0 and Friday its day 6. The server's twin is `PracticeWindow::inWeek()`.
export function startInWeek(weekStart, weekday, startMinute) {
    return new Date(Date.parse(`${weekStart}T00:00:00Z`) + ((weekday + 1) % 7) * DAY_MS + startMinute * MINUTE_MS);
}
