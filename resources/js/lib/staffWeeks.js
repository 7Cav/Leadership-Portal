import { attendedText, presenceAnswer } from './staffReport';

// Line HQ staff on the roster (a platoon, company or battalion HQ): they are on no section's roll call, so a week they
// were on the HQ reads their staff report instead (`RosterController::staffWeeks()`, `Milpac\StaffReportWeeks`). The
// server judges each report by the staff report pages' own rule; this only says how a week reads. Kept apart from
// `roster.js` so the week cell there stays as it is.
//
// Like a section trooper's roll call and SP halves, a staff week has two: the top says whether the report was sent,
// the bottom whether it met the rule.

/** The top half: whether the report was sent, by the week's state. */
export const STAFF_SENT_STATES = {
    met: 'Sent',
    short: 'Sent',
    notSent: 'Not sent',
    open: 'Not sent yet',
    loa: 'On LOA',
    before: 'No staff reports yet',
};

/** The bottom half: whether the report met the rule, by the week's state. A finished week with no report sent falls short. */
export const STAFF_MET_STATES = {
    met: 'Met the rule',
    short: 'Fell short',
    notSent: 'Not met, no report sent',
    open: 'Not applicable',
    loa: 'Not applicable',
    before: 'Not applicable',
};

const SENT_CLASS = { met: 'staff-sent', short: 'staff-sent', notSent: 'staff-notSent', open: 'staff-open', loa: 'loa', before: 'none' };
const MET_CLASS = { met: 'staff-met', short: 'staff-short', notSent: 'staff-short', open: 'staff-na', loa: 'staff-na', before: 'none' };

/**
 * A roster week cell (`weekCell()`) with the staff report week in place of "No section that week": the top half
 * (`rc`) takes whether it was sent (`staff-sent`, `staff-notSent`, `staff-open`, or `loa` on leave), the bottom half
 * (`sp`) whether it met the rule (`staff-met`, `staff-short`, or `staff-na` when nothing to judge), and `staff` carries
 * the week. A week before staff reports could be filed (`before`) keeps the neutral `none` look in both. Any other
 * cell is left as it is, so a roll call mark, a week before they joined or after they left, and a week the cell
 * already reads as LOA all win.
 */
export function withStaffWeek(cell, entry) {
    if (!entry || cell.rc !== 'none') return cell;

    return { rc: SENT_CLASS[entry.state] ?? 'none', sp: MET_CLASS[entry.state] ?? 'none', staff: entry };
}

export const staffSentText = (entry) => STAFF_SENT_STATES[entry.state] ?? 'Unknown';
export const staffMetText = (entry) => STAFF_MET_STATES[entry.state] ?? 'Unknown';

/** "Staff report from 1/A/1-7: Sent. Accountability: Met the rule" and the like, for a cell's label. */
export function staffWeekText(entry) {
    return `Staff report${entry.hq ? ` from ${entry.hq}` : ''}: ${staffSentText(entry)}. Accountability: ${staffMetText(entry)}`;
}

/**
 * The tooltip rows for a staff week: whether it was sent, with the HQ, whether it met the rule, then, where the
 * viewer reads the report, the practices attended as staff guest and the presence answer.
 */
export function staffTipRows(entry) {
    const rows = [
        ['Staff report', staffSentText(entry), entry.hq],
        ['Accountability', staffMetText(entry)],
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
