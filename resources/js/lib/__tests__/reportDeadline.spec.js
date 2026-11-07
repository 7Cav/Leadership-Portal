import { afterEach, describe, expect, it, vi } from 'vitest';
import { dueCountdown, reportDeadline, spanLabel } from '../reportDeadline';

const DUE = '2026-10-10T23:59:00+00:00';
const at = (iso) => new Date(iso).getTime();

describe('report deadline', () => {
    it('says nothing while more than 24 hours are left', () => {
        expect(reportDeadline(DUE, at('2026-10-09T23:58:00Z'))).toBeNull();
        expect(reportDeadline(DUE, at('2026-10-05T12:00:00Z'))).toBeNull();
    });

    it('counts down the last 24 hours', () => {
        expect(reportDeadline(DUE, at('2026-10-09T23:59:00Z'))).toEqual({ overdue: false, text: 'Report due in 24 h' });
        expect(reportDeadline(DUE, at('2026-10-10T18:30:00Z'))).toEqual({ overdue: false, text: 'Report due in 5 h' });
        expect(reportDeadline(DUE, at('2026-10-10T23:20:00Z'))).toEqual({ overdue: false, text: 'Report due in 39 min' });
    });

    it('says how long a report has been overdue', () => {
        expect(reportDeadline(DUE, at('2026-10-10T23:59:00Z'))).toEqual({ overdue: true, text: 'Report overdue by 1 min' });
        expect(reportDeadline(DUE, at('2026-10-11T03:10:00Z'))).toEqual({ overdue: true, text: 'Report overdue by 3 h' });
        expect(reportDeadline(DUE, at('2026-10-16T09:00:00Z'))).toEqual({ overdue: true, text: 'Report overdue by 5 d' });
    });

    it('stops calling a report overdue once its week leaves the 4-week window', () => {
        const until = '2026-10-31T00:00:00+00:00';

        expect(reportDeadline(DUE, at('2026-10-30T23:59:00Z'), false, until)).toEqual({ overdue: true, text: 'Report overdue by 20 d' });
        expect(reportDeadline(DUE, at('2026-10-31T00:00:00Z'), false, until)).toBeNull();
        expect(reportDeadline(DUE, at('2026-10-10T18:30:00Z'), false, until)).toEqual({ overdue: false, text: 'Report due in 5 h' });
    });

    it('says nothing for a sent report', () => {
        expect(reportDeadline(DUE, at('2026-10-10T18:30:00Z'), true)).toBeNull();
        expect(reportDeadline(DUE, at('2026-10-11T18:30:00Z'), true)).toBeNull();
    });

    it('rounds spans down, never to zero', () => {
        expect(spanLabel(0)).toBe('1 min');
        expect(spanLabel(59 * 60_000)).toBe('59 min');
        expect(spanLabel(47 * 3_600_000 + 59 * 60_000)).toBe('47 h');
        expect(spanLabel(48 * 3_600_000)).toBe('2 d');
    });
});

describe('due countdown', () => {
    afterEach(() => vi.useRealTimers());
    const left = (ms) => {
        vi.useFakeTimers();
        vi.setSystemTime(at(DUE) - ms);

        return dueCountdown(DUE, Date.now());
    };
    const H = 60 * 60 * 1000;
    const M = 60 * 1000;

    it('says nothing a week or more out', () => {
        expect(left(7 * 24 * H)).toBeNull();
        expect(left(7 * 24 * H - M)).toBe('in 6d');
    });

    it('switches from hours to days at 48 hours', () => {
        expect(left(48 * H)).toBe('in 2d');
        expect(left(47 * H + 59 * M)).toBe('in 47h');
    });

    it('switches from minutes to hours at one hour', () => {
        expect(left(H)).toBe('in 1h');
        expect(left(59 * M)).toBe('in 59m');
        expect(left(10 * 1000)).toBe('in 1m');
    });

    it('reads a passed deadline as overdue', () => {
        expect(left(0)).toBe('overdue');
        expect(left(-3 * H)).toBe('overdue');
        expect(dueCountdown(null, Date.now())).toBeNull();
    });
});
