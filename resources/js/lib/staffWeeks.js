import { attendedText, presenceAnswer } from './staffReport';

// Line HQ staff on the roster (a platoon, company or battalion HQ): they are on no section's roll call, so a week they
// were on the HQ reads their staff report instead (`RosterController::staffWeeks()`, `Milpac\StaffReportWeeks`). The
// server judges each report by the staff report pages' own rule; this only says how a week reads. Kept apart from
// `roster.js` so the week cell there stays as it is.

export const STAFF_STATES = {
    met: 'Report met the rule',
    short: 'Report fell short',
    notSent: 'Report not sent',
    open: 'Report due once the week ends',
    loa: 'On LOA',
};

/**
 * A roster week cell (`weekCell()`) with the staff report week in place of "No section that week": both halves take
 * the staff state (`staff-met`, `staff-short`, `staff-notSent`, `staff-open`), or `loa` on leave, and `staff` carries
 * the week. Any other cell is left as it is, so a roll call mark, a week before they joined or after they left, and
 * a week the cell already reads as LOA all win.
 */
export function withStaffWeek(cell, entry) {
    if (!entry || cell.rc !== 'none') return cell;
    const state = entry.state === 'loa' ? 'loa' : `staff-${entry.state}`;

    return { rc: state, sp: state, staff: entry };
}

/** "Staff report: Report met the rule" and the like, for a cell's label. */
export function staffWeekText(entry) {
    return `Staff report${entry.hq ? ` from ${entry.hq}` : ''}: ${STAFF_STATES[entry.state] ?? 'Unknown'}`;
}

/**
 * The tooltip rows for a staff week: the state with the HQ, then, where the viewer reads the report, the practices
 * attended as staff guest and the presence answer, and whether it was sent.
 */
export function staffTipRows(entry) {
    const rows = [
        ['Staff report', STAFF_STATES[entry.state] ?? 'Unknown', entry.hq],
        ['Sent', entry.sent ? 'Yes' : entry.state === 'open' ? 'Not yet' : 'No'],
    ];
    if (entry.report) {
        rows.push(['Practices', attendedText(entry.report)]);
        rows.push(['Presence', presenceAnswer(entry.report) || 'Not asked']);
    } else if (entry.sent) {
        rows.push(['Details', 'For the chain above the HQ']);
    }

    return rows;
}

/** "Reports 3 of 4 met": the staff reports over the KPI window's finished weeks. Null with none due. */
export function staffReportsText(reports) {
    return reports?.due ? `Reports ${reports.met} of ${reports.due} met` : null;
}

/** Tone for the staff reports summary: every one met, some, or none. */
export function staffReportsTone(reports) {
    if (!reports?.due) return 'muted';
    if (reports.met === reports.due) return 'ok';

    return reports.met === 0 ? 'bad' : 'warn';
}
