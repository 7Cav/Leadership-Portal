import { weekPhrase } from '@/lib/forumDate';
import { KPIS, kpiChange, percent, stamp } from '@/lib/report';

// The short plain-text summary of a weekly report that "Copy summary" puts on the clipboard, for
// pasting into Discord or a staff thread. Plain lines only: no BBCode and no markdown of its own.
// Text a leader wrote (and names) is escaped so Discord shows it as written: `*`, `_`, `~`, `` ` ``
// and `|` would otherwise turn into styling or spoilers, `#` and `>` at a line start into headings
// and quotes, and @everyone/@here would ping the channel.

// Long notes are cut at a word near this many characters; the full notes stay in the portal.
export const NOTES_MAX = 500;

const BY_KEY = Object.fromEntries(KPIS.map((kpi) => [kpi.key, kpi]));

export function discordPlain(text) {
    return String(text ?? '')
        .replace(/[\\*_~`|]/g, '\\$&')
        .replace(/^([ \t]*)([#>])/gm, '$1\\$2')
        .replace(/@(everyone|here)\b/gi, '@​$1');
}

// Trailing spaces off each line, at most one blank line in a row, and cut to `max` characters at a
// word boundary when longer.
export function trimNotes(notes, max = NOTES_MAX) {
    const text = String(notes ?? '')
        .replace(/\r\n?/g, '\n')
        .split('\n')
        .map((line) => line.trimEnd())
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();

    if (text.length <= max) return text;

    const cut = text.slice(0, max);
    const space = cut.search(/\s\S*$/);

    return `${(space > max * 0.6 ? cut.slice(0, space) : cut).trimEnd()} (cut short; full notes in the portal)`;
}

function change(delta, key) {
    const c = kpiChange(delta, BY_KEY[key]);
    if (!c) return '';

    return c.unit === 'points' ? ` (${c.text} pts)` : ` (${c.text})`;
}

function rate(kpis, delta, key) {
    const value = percent(kpis[key]);

    return value === null ? 'n/a' : `${value}%${change(delta, key)}`;
}

function unitName(unit) {
    if (unit.name && unit.callsign && unit.name !== unit.callsign) return `${unit.name} (${unit.callsign})`;

    return unit.callsign || unit.name || '';
}

/**
 * @param {object} report
 * @param {object} report.unit `{ name, callsign, isLeaf }`
 * @param {object} report.week `{ start }`
 * @param {boolean} report.sent
 * @param {?string} report.sentAt
 * @param {?string} report.sentBy
 * @param {?object} report.kpis the six KPIs as the report screen gets them
 * @param {?object} report.delta the change against the baseline, rates in basis points
 * @param {?string} report.trainingSubject
 * @param {?string} report.notes
 */
export function reportSummary({ unit, week, sent = false, sentAt = null, sentBy = null, kpis = null, delta = null, trainingSubject = null, notes = null }) {
    const lines = [`${discordPlain(unitName(unit))} weekly report, ${weekPhrase(week.start)}`];

    lines.push(sent ? `Sent ${stamp(sentAt, { plain: true })}${sentBy ? ` by ${discordPlain(sentBy)}` : ''}` : 'Draft, not sent yet');

    if (kpis) {
        // Both numbers, labelled apart (#424): the headcount counts staff and groups, "in rollcall" is what the rates are over.
        if (kpis.headcount != null) lines.push(`Headcount ${kpis.headcount}, with staff`);
        lines.push(`In roll call ${kpis.strength}${change(delta, 'strength')}`);
        lines.push(`RC ${rate(kpis, delta, 'rc')}, SP ${rate(kpis, delta, 'sp')}, last 30 days`);
        lines.push(`At-risk ${kpis.atRisk}${change(delta, 'atRisk')}, on LOA ${kpis.onLoa}, makeups ${kpis.makeups}`);
    } else {
        lines.push('Numbers not available');
    }

    const subject = String(trainingSubject ?? '').trim();
    if (unit.isLeaf || subject) lines.push(`Trained: ${subject ? discordPlain(subject) : 'not filled in'}`);

    const text = trimNotes(notes);
    if (text) lines.push('', 'Notes:', discordPlain(text));

    return lines.join('\n');
}
