import { describe, expect, it } from 'vitest';
import { laneTotals, stepCells, tileChange, tileSeries, hasTrend, TILES } from '../home';

const step = (over = {}) => ({
    id: 1, designation: '1/1/A/1-7', platoon: '1/A/1-7', strength: 8, postedAt: null, signed: 0, held: false, marked: 0, report: 'not_started', sentAt: null, links: {}, ...over,
});

describe('stepCells', () => {
    it('reads a section nobody has started as not yet, never overdue', () => {
        const cells = stepCells(step());

        expect(cells.posted).toEqual({ state: 'wait', text: 'Not posted' });
        expect(cells.held).toEqual({ state: 'wait', text: 'Not held' });
        expect(cells.marked).toEqual({ state: 'wait', text: '0 of 8 marked' });
        expect(cells.report).toEqual({ state: 'wait', text: 'Not started' });
        expect(Object.values(cells).map((cell) => cell.state)).not.toContain('bad');
    });

    it('marks done steps with the weekday they happened, in UTC', () => {
        const cells = stepCells(step({ postedAt: '2026-10-03T23:30:00+00:00', held: true, marked: 8, signed: 8, report: 'sent', sentAt: '2026-10-09T21:30:00+00:00' }));

        expect(cells.posted).toEqual({ state: 'ok', text: 'Sat' });
        expect(cells.held.state).toBe('ok');
        expect(cells.marked.state).toBe('ok');
        expect(cells.signed).toMatchObject({ state: 'ok', percent: 100, text: '8 of 8 signed' });
        expect(cells.report).toEqual({ state: 'ok', text: 'Sent Fri' });
    });

    it('shows the signed share as a rounded percent, and a draft report as a warning', () => {
        const cells = stepCells(step({ strength: 6, signed: 5, report: 'draft' }));

        expect(cells.signed).toMatchObject({ percent: 83, state: 'wait' });
        expect(cells.report).toEqual({ state: 'warn', text: 'Draft' });
    });

    it('has nothing to sign or mark in a section with no troopers, rather than 0 or 100%', () => {
        const cells = stepCells(step({ strength: 0 }));

        expect(cells.signed).toMatchObject({ state: 'none', percent: null });
        expect(cells.marked.state).toBe('none');
    });
});

describe('laneTotals', () => {
    it('adds each step up across the sections in scope', () => {
        const lanes = laneTotals([
            step({ postedAt: '2026-10-03T10:00:00+00:00', strength: 6, signed: 5, held: true, marked: 6, report: 'sent' }),
            step({ id: 2, strength: 4, signed: 1, marked: 2, report: 'draft' }),
            step({ id: 3, strength: 2 }),
        ]);

        expect(lanes.map((lane) => lane.text)).toEqual([
            '1 of 3 posted',
            '6 of 12 signed',
            '1 of 3 held',
            '8 of 12 marked',
            '1 sent, 1 draft, 1 not started',
        ]);
        expect(lanes[1].fill).toBe(0.5);
        expect(lanes.every((lane) => !lane.complete)).toBe(true);
    });

    it('is complete only when every section has done the step', () => {
        const lanes = laneTotals([step({ held: true, strength: 2, marked: 2 }), step({ id: 2, held: true, strength: 1, marked: 1 })]);

        expect(lanes[2].complete).toBe(true);
        expect(lanes[3].complete).toBe(true);
        expect(lanes[0].complete).toBe(false);
    });
});

describe('tile series', () => {
    const kpi = TILES.find((tile) => tile.key === 'rc');
    const point = (week, n, d) => ({ week, kpis: n === null ? null : { strength: 5, rc: { numerator: n, denominator: d } } });

    it('needs a sent week behind the live value to draw a trend', () => {
        const none = tileSeries(kpi, { rc: { numerator: 9, denominator: 10 } }, [point('a', null), point('b', null)]);
        const some = tileSeries(kpi, { rc: { numerator: 9, denominator: 10 } }, [point('a', null), point('b', 8, 10)]);

        expect(hasTrend(none)).toBe(false);
        expect(hasTrend(some)).toBe(true);
    });

    it('changes against the latest sent week, skipping gaps, with the right tone', () => {
        const series = tileSeries(kpi, { rc: { numerator: 9, denominator: 10 } }, [point('a', 5, 10), point('b', null)]);

        expect(tileChange(kpi, series)).toMatchObject({ value: 40, text: '+40', tone: 'good', direction: 'up', unit: 'points' });
    });

    it('has no change without a baseline or a current value', () => {
        expect(tileChange(kpi, tileSeries(kpi, { rc: { numerator: 9, denominator: 10 } }, [point('a', null)]))).toBeNull();
        expect(tileChange(kpi, tileSeries(kpi, { rc: { numerator: 0, denominator: 0 } }, [point('a', 5, 10)]))).toBeNull();
    });
});
