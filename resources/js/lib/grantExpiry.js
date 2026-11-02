import { forumDate } from '@/lib/forumDate';

const DAY_MS = 86_400_000;

// The quick picks for a grant's end date, in days from today (Zulu).
export const EXPIRY_PRESETS = [7, 30, 90];

// A grant lapsing or ending within this many days is listed first, with a warning.
export const SOON_DAYS = 7;

// `YYYY-MM-DD` for the Zulu day `days` after `now`, as the form's `ends_on`.
export function endsOnIn(days, now = Date.now()) {
    return new Date(Number(now) + days * DAY_MS).toISOString().slice(0, 10);
}

// Today's Zulu day, `YYYY-MM-DD`: the earliest end date the server takes.
export function todayZulu(now = Date.now()) {
    return endsOnIn(0, now);
}

// A grant's status from its end date. The server's `past_end` decides whether it already gives no scope, so a viewer
// whose clock is off never sees a lapsed grant as working, or the reverse. `tone` picks the badge, `soon` whether it
// is listed first.
export function grantStatus(grant, now = Date.now()) {
    if (!grant.expires_at || grant.mode === 'none') {
        return { label: null, tone: null, soon: false };
    }

    const date = forumDate(grant.expires_at);

    if (grant.past_end) {
        return grant.mode === 'renew'
            ? { label: `Lapsed since ${date}, renew to restore`, tone: 'danger', soon: true }
            : { label: 'Ended', tone: 'danger', soon: true };
    }

    const soon = new Date(grant.expires_at).getTime() - Number(now) <= SOON_DAYS * DAY_MS;

    return {
        label: grant.mode === 'renew' ? `Renew by ${date}` : `Ends ${date}`,
        tone: soon ? 'warning' : 'outline',
        soon,
    };
}

// Grants lapsing or ending within a week (or already past their date) first, soonest first; the rest keep their order.
export function sortGrants(grants, now = Date.now()) {
    const soon = grants.filter((grant) => grantStatus(grant, now).soon);
    const rest = grants.filter((grant) => !grantStatus(grant, now).soon);

    soon.sort((a, b) => new Date(a.expires_at) - new Date(b.expires_at));

    return [...soon, ...rest];
}
