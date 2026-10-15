// When a week's report deadline is close enough to call out on the rollcall and report screens: in
// the last 24 hours before it, and after it while the report is still unsent. The deadline is the
// server's (`WeekOf::reportDue`, 23:59Z on the Saturday after the week); this only words it.

export const DEADLINE_WINDOW_MS = 24 * 60 * 60 * 1000;

// A rough span: minutes under an hour, hours under two days, then days. Never "0 min".
export function spanLabel(ms) {
    const minutes = Math.max(1, Math.floor(ms / 60_000));
    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);

    return hours < 48 ? `${hours} h` : `${Math.floor(hours / 24)} d`;
}

// `{ overdue, text }` for an unsent report due at `due` (ISO-8601), or null when there is nothing
// to say yet (more than 24 hours left) or the report is sent.
export function reportDeadline(due, now, sent = false) {
    if (sent || !due) return null;

    const left = new Date(due).getTime() - now;
    if (left > DEADLINE_WINDOW_MS) return null;

    return left > 0 ? { overdue: false, text: `Report due in ${spanLabel(left)}` } : { overdue: true, text: `Report overdue by ${spanLabel(-left)}` };
}
