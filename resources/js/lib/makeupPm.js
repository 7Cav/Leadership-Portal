import { weekLabel } from '@/lib/forumDate';

// The makeup PM (Command Brief principle 2: "send a makeup PM directly from the row you are looking at").
// The portal never writes to the forum, so this is only a link to the forum's own "Start conversation" form
// (XenForo 2 `conversations/add`), with the recipient and the title filled in. The leader reads it, writes
// the message and sends it from the forum themselves. XenForo reads `to` and `title` from the query string.

const WEEK = /^\d{4}-\d{2}-\d{2}$/;

// "Section practice makeup: week ending 09Oct26", or "weeks ending 02Oct26, 09Oct26" for more than one, oldest
// first, each week named by its Friday in the forum's date convention (#568). Just "Section practice makeup" when no week is known.
export function makeupPmTitle(missedWeeks = []) {
    const weeks = [...new Set(missedWeeks.filter((week) => WEEK.test(String(week))))].sort();
    if (!weeks.length) return 'Section practice makeup';

    const labels = weeks.map(weekLabel);

    return `Section practice makeup: ${labels.length === 1 ? 'week' : 'weeks'} ending ${labels.join(', ')}`;
}

// The forum URL that opens a new conversation to `username` titled for the missed weeks, or null when
// there is nobody to address (no forum username) or no usable forum address, so the caller draws no link.
export function makeupPmUrl(baseUrl, username, missedWeeks = []) {
    const base = String(baseUrl ?? '').trim().replace(/\/+$/, '');
    const to = String(username ?? '').trim();
    if (!to || !/^https?:\/\/[^/\s]+/i.test(base)) return null;

    return `${base}/conversations/add?to=${encodeURIComponent(to)}&title=${encodeURIComponent(makeupPmTitle(missedWeeks))}`;
}
