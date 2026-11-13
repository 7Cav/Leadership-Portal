import { describe, expect, it } from 'vitest';
import { weekCell } from '../roster';
import { staffMetText, staffReportsText, staffReportsTone, staffSentText, staffTipRows, staffWeekText, withStaffWeek } from '../staffWeeks';

const met = { state: 'met', hq: '1/A/1-7', sent: true, report: { attended: true, practiceType: 'sp', ftx: null, officeHour: null, presenceType: 'public_server', practices: ['1/1/A/1-7', '2/1/A/1-7'] } };
const none = { rc: 'none', sp: 'none' };

describe('withStaffWeek', () => {
    it('reads a line HQ staff week as two halves in place of "No section that week": report sent on top, rule met below', () => {
        expect(withStaffWeek(none, met)).toEqual({ rc: 'staff-sent', sp: 'staff-met', staff: met });
        expect(withStaffWeek(none, { state: 'short' })).toMatchObject({ rc: 'staff-sent', sp: 'staff-short' });
        expect(withStaffWeek(none, { state: 'notSent' })).toMatchObject({ rc: 'staff-notSent', sp: 'staff-na' });
        expect(withStaffWeek(none, { state: 'open' })).toMatchObject({ rc: 'staff-open', sp: 'staff-na' });
    });

    it('keeps a week before staff reports existed neutral, never not sent', () => {
        const before = { state: 'before', hq: '1/A/1-7', sent: false, report: null };
        expect(withStaffWeek(none, before)).toEqual({ rc: 'none', sp: 'none', staff: before });
        expect(staffWeekText(before)).toBe('Staff report from 1/A/1-7: No staff reports yet. Accountability: Not applicable');
        expect(staffTipRows(before)).toEqual([
            ['Staff report', 'No staff reports yet', '1/A/1-7'],
            ['Accountability', 'Not applicable'],
        ]);
    });

    it('shows a week on leave as LOA on top, with nothing to judge below', () => {
        expect(withStaffWeek(none, { state: 'loa', sent: false })).toMatchObject({ rc: 'loa', sp: 'staff-na' });
        expect(staffMetText({ state: 'loa' })).toBe('Not applicable');
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
    it('names the HQ, whether it was sent and whether it met the rule', () => {
        expect(staffWeekText(met)).toBe('Staff report from 1/A/1-7: Sent. Accountability: Met the rule');
        expect(staffWeekText({ state: 'short', hq: '1/A/1-7' })).toBe('Staff report from 1/A/1-7: Sent. Accountability: Fell short');
        expect(staffWeekText({ state: 'notSent', hq: null })).toBe('Staff report: Not sent. Accountability: Not applicable');
        expect(staffSentText({ state: 'open' })).toBe('Not sent yet');
    });

    it('gives both states, then the practices and the presence in the tooltip', () => {
        expect(staffTipRows(met)).toEqual([
            ['Staff report', 'Sent', '1/A/1-7'],
            ['Accountability', 'Met the rule'],
            ['Practices', 'Yes: 1/1/A/1-7, 2/1/A/1-7'],
            ['Presence', 'Public server hour'],
        ]);
        expect(staffTipRows({ state: 'open', hq: '1/A/1-7', sent: false, report: null })).toEqual([
            ['Staff report', 'Not sent yet', '1/A/1-7'],
            ['Accountability', 'Not applicable'],
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
