import { forumDate } from '@/lib/forumDate';

// The makeup PM (Command Brief principle 2: "send a makeup PM directly from the row you are looking at").
// The portal never writes to the forum, so this is only a link to the forum's own "Start conversation" form
// (XenForo 2 `conversations/add`), with the recipient and the title filled in. The leader reads it, writes
// the message and sends it from the forum themselves. XenForo reads `to` and `title` from the query string.

const WEEK = /^\d{4}-\d{2}-\d{2}$/;

// "Section practice makeup: week of 03Oct26", or "weeks of 26Sep26, 03Oct26" for more than one, oldest
// first, in the forum's date convention. Just "Section practice makeup" when no week is known.
export function makeupPmTitle(missedWeeks = []) {
    const weeks = [...new Set(missedWeeks.filter((week) => WEEK.test(String(week))))].sort();
    if (!weeks.length) return 'Section practice makeup';

    const labels = weeks.map((week) => forumDate(`${week}T00:00:00Z`));

    return `Section practice makeup: ${labels.length === 1 ? 'week' : 'weeks'} of ${labels.join(', ')}`;
}

// The forum URL that opens a new conversation to `username` titled for the missed weeks, or null when
// there is nobody to address (no forum username) or no usable forum address, so the caller draws no link.
export function makeupPmUrl(baseUrl, username, missedWeeks = []) {
    const base = String(baseUrl ?? '').trim().replace(/\/+$/, '');
    const to = String(username ?? '').trim();
    if (!to || !/^https?:\/\/[^/\s]+/i.test(base)) return null;

    return `${base}/conversations/add?to=${encodeURIComponent(to)}&title=${encodeURIComponent(makeupPmTitle(missedWeeks))}`;
}

// The most troopers one group makeup PM addresses. The forum's own per-conversation recipient limit is not known to
// the portal, so this stays small; the forum refuses the form if its limit is lower.
export const GROUP_PM_LIMIT = 10;

// One forum conversation to several troopers ("PM all at-risk"): the forum's `to` takes a comma-separated list. Up to
// `GROUP_PM_LIMIT` usernames, blanks and repeats dropped, in the order given. Every recipient sees the others, so the
// title names no weeks. Null with fewer than 2 usable names (a single trooper has their own link) or no forum address.
export function groupMakeupPmUrl(baseUrl, usernames = []) {
    const base = String(baseUrl ?? '').trim().replace(/\/+$/, '');
    const to = [...new Set(usernames.map((name) => String(name ?? '').trim()).filter(Boolean))].slice(0, GROUP_PM_LIMIT);
    if (to.length < 2 || !/^https?:\/\/[^/\s]+/i.test(base)) return null;

    return `${base}/conversations/add?to=${encodeURIComponent(to.join(', '))}&title=${encodeURIComponent(makeupPmTitle())}`;
}
