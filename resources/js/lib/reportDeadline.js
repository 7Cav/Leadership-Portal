// When a week's report deadline is close enough to call out on the rollcall and report screens: in
// the last 24 hours before it, and after it while the report is still unsent and the week is recent
// (until `overdueUntil`, when the week leaves the 4-week KPI window). Both times are the server's
// (`WeekOf::reportDue`, 2359Z on the Saturday after the week); this only words them.

export const DEADLINE_WINDOW_MS = 24 * 60 * 60 * 1000;

// A rough span: minutes under an hour, hours under two days, then days. Never "0 min".
export function spanLabel(ms) {
    const minutes = Math.max(1, Math.floor(ms / 60_000));
    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);

    return hours < 48 ? `${hours} h` : `${Math.floor(hours / 24)} d`;
}

// `{ overdue, text }` for an unsent report due at `due` (ISO-8601), or null when there is nothing
// to say: more than 24 hours left, the report is sent, or it is overdue for a week older than
// `overdueUntil` allows.
export function reportDeadline(due, now, sent = false, overdueUntil = null) {
    if (sent || !due) return null;

    const left = new Date(due).getTime() - now;
    if (left > DEADLINE_WINDOW_MS) return null;
    if (left <= 0 && overdueUntil && now >= new Date(overdueUntil).getTime()) return null;

    return left > 0 ? { overdue: false, text: `Report due in ${spanLabel(left)}` } : { overdue: true, text: `Report overdue by ${spanLabel(-left)}` };
}
