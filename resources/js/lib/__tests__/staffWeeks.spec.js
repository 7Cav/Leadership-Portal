import { describe, expect, it } from 'vitest';
import { weekCell } from '../roster';
import { staffReportsText, staffReportsTone, staffTipRows, staffWeekText, withStaffWeek } from '../staffWeeks';

const met = { state: 'met', hq: '1/A/1-7', sent: true, report: { attended: true, practiceType: 'sp', ftx: null, officeHour: null, presenceType: 'public_server', practices: ['1/1/A/1-7', '2/1/A/1-7'] } };
const none = { rc: 'none', sp: 'none' };

describe('withStaffWeek', () => {
    it('reads a line HQ staff week as its staff report in place of "No section that week"', () => {
        expect(withStaffWeek(none, met)).toEqual({ rc: 'staff-met', sp: 'staff-met', staff: met });
        expect(withStaffWeek(none, { state: 'short' }).sp).toBe('staff-short');
        expect(withStaffWeek(none, { state: 'notSent' }).sp).toBe('staff-notSent');
        expect(withStaffWeek(none, { state: 'open' }).sp).toBe('staff-open');
    });

    it('keeps a week before staff reports existed neutral, never not sent', () => {
        const before = { state: 'before', hq: '1/A/1-7', sent: false, report: null };
        expect(withStaffWeek(none, before)).toEqual({ rc: 'none', sp: 'none', staff: before });
        expect(staffWeekText(before)).toBe('Staff report from 1/A/1-7: No staff reports yet');
        expect(staffTipRows(before)).toEqual([['Staff report', 'No staff reports yet', '1/A/1-7']]);
    });

    it('shows a week on leave as LOA, as for troopers', () => {
        expect(withStaffWeek(none, { state: 'loa', sent: false })).toMatchObject({ rc: 'loa', sp: 'loa' });
    });

    it('leaves any other cell alone: a mark, a week before joining or after leaving, and a group week', () => {
        const mark = { rollcall: 'will_attend', status: 'present', source: 'manual', held: true };
        expect(withStaffWeek(weekCell(mark, '2026-09-26', { inSection: false }), met)).toEqual({ rc: 'ok', sp: 'ok' });
        expect(withStaffWeek(weekCell(null, '2026-09-19', { joined: '2026-09-28', inSection: false }), met)).toEqual({ rc: 'out', sp: 'out' });
        expect(withStaffWeek(weekCell(null, '2026-10-03', { departedAt: '2026-09-30', inSection: false }), met)).toEqual({ rc: 'left', sp: 'left' });
        expect(withStaffWeek(weekCell(null, '2026-09-26', { inSection: false }), null)).toEqual(none);
        expect(withStaffWeek(weekCell(null, '2026-09-26', { inSection: false }), met).staff).toBe(met);
    });
});

describe('staff week text', () => {
    it('names the HQ and the state', () => {
        expect(staffWeekText(met)).toBe('Staff report from 1/A/1-7: Report met the rule');
        expect(staffWeekText({ state: 'notSent', hq: null })).toBe('Staff report: Report not sent');
    });

    it('gives the practices, the presence and whether it was sent in the tooltip', () => {
        expect(staffTipRows(met)).toEqual([
            ['Staff report', 'Report met the rule', '1/A/1-7'],
            ['Sent', 'Yes'],
            ['Practices', 'Yes: 1/1/A/1-7, 2/1/A/1-7'],
            ['Presence', 'Public server hour'],
        ]);
        expect(staffTipRows({ state: 'open', hq: '1/A/1-7', sent: false, report: null })).toEqual([
            ['Staff report', 'Report due once the week ends', '1/A/1-7'],
            ['Sent', 'Not yet'],
        ]);
        expect(staffTipRows({ state: 'short', hq: 'A/1-7', sent: true, report: null })).toContainEqual(['Details', 'For the chain above the HQ']);
        expect(staffTipRows({ state: 'short', hq: 'A/1-7', sent: true, report: { attended: false, practices: [], presenceType: null } })).toEqual(
            expect.arrayContaining([['Practices', 'No'], ['Presence', 'Not asked']]),
        );
    });

    it('sums the reports as "Reports 3 of 4 met", toned by how many met', () => {
        expect(staffReportsText({ met: 3, due: 4, weeks: 4 })).toBe('Reports 3 of 4 met');
        expect(staffReportsText(null)).toBeNull();
        expect(staffReportsText({ met: 0, due: 0, weeks: 4 })).toBeNull();
        expect(staffReportsTone({ met: 4, due: 4 })).toBe('ok');
        expect(staffReportsTone({ met: 2, due: 4 })).toBe('warn');
        expect(staffReportsTone({ met: 0, due: 4 })).toBe('bad');
        expect(staffReportsTone(null)).toBe('muted');
    });
});
