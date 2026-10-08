import { describe, expect, it } from 'vitest';
import {
    dayTime,
    defaultExpanded,
    displayStatus,
    indexTree,
    percent,
    points,
    progress,
    reminderText,
    visibleRows,
} from '../reportRollup';

const kpis = (rc = [3, 4]) => ({ strength: 4, rc: { numerator: rc[0], denominator: rc[1] }, sp: { numerator: 1, denominator: 2 }, atRisk: 0, onLoa: 0, makeups: 0 });
const node = (id, type, status, children = [], extra = {}) => ({
    id, type, status, children, name: `Unit ${id}`, callsign: `${id}/A`, holding: false, live: status !== 'sent',
    open: status !== 'sent' || children.some((c) => c.open), kpis: kpis(), delta: null, coverage: null, reportUrl: `/reports/${id}/2026-10-03`, ...extra,
});

// company 1 ─┬─ platoon 2 ─┬─ section 4 (sent)
//            │             └─ section 5 (draft)
//            └─ platoon 3 ─── section 6 (sent)
function tree() {
    const p2 = node(2, 'platoon', 'draft', [node(4, 'section', 'sent'), node(5, 'section', 'draft')], { holding: true });
    const p3 = node(3, 'platoon', 'sent', [node(6, 'section', 'sent')]);

    return [node(1, 'company', 'not_started', [p2, p3], { holding: true })];
}

describe('reportRollup helpers', () => {
    it('shows holding for an unsent unit waiting on the chain, otherwise its own status', () => {
        expect(displayStatus({ status: 'sent', holding: false })).toBe('sent');
        expect(displayStatus({ status: 'draft', holding: true })).toBe('holding');
        expect(displayStatus({ status: 'not_started', holding: false })).toBe('not_started');
    });

    it('formats ratios as whole percentages and n/a as null, never 0%', () => {
        expect(percent({ numerator: 2, denominator: 3 })).toBe(67);
        expect(percent({ numerator: 0, denominator: 0 })).toBeNull();
        expect(points(2080)).toBe(20.8);
        expect(points(-300)).toBe(-3);
        expect(points(null)).toBeNull();
    });

    it('formats timestamps in UTC with the forum date', () => {
        expect(dayTime('2026-10-10T23:59:00+00:00')).toBe('Sat 10Oct26 23:59Z');
    });

    it('opens the top two levels by default', () => {
        expect([...defaultExpanded(tree())].sort()).toEqual([1, 2, 3]);
    });

    it('walks visible rows depth-first, hiding collapsed children and, when filtered, sent branches', () => {
        const roots = tree();
        const all = visibleRows(roots, { expanded: new Set([1, 2, 3]), filter: 'all' }).map((r) => r.node.id);
        const open = visibleRows(roots, { expanded: new Set([1, 2, 3]), filter: 'open' }).map((r) => r.node.id);
        const collapsed = visibleRows(roots, { expanded: new Set([1]), filter: 'all' });

        expect(all).toEqual([1, 2, 4, 5, 3, 6]);
        expect(open).toEqual([1, 2, 5]);
        expect(collapsed.map((r) => [r.node.id, r.level, r.parentId, r.expanded])).toEqual([[1, 1, null, true], [2, 2, 1, false], [3, 2, 1, false]]);
    });

    it('indexes every node with its parent and level', () => {
        const byId = indexTree(tree());

        expect(byId.get(5)).toMatchObject({ parentId: 2, level: 3 });
        expect(byId.get(1)).toMatchObject({ parentId: null, level: 1 });
    });

    it('counts sent leaves and each echelon between them and the top', () => {
        const result = progress(tree());

        expect(result.label).toBe('Sections sent 2 of 3');
        expect(result.segments).toEqual([
            { status: 'sent', label: 'Sent', count: 2 },
            { status: 'draft', label: 'Draft', count: 1 },
            { status: 'not_started', label: 'Not started', count: 0 },
        ]);
        expect(result.echelons).toEqual(['Platoons sent 1 of 2']);
        expect(result.rootStatus).toBe('holding');
    });

    it('writes a reminder naming the unit, week and deadline, and says when it is overdue', () => {
        const unit = node(5, 'section', 'draft', [], { name: '2nd Section', callsign: '2/1/A/1-7' });
        const week = { weekStart: '2026-10-03T00:00:00+00:00', reportDue: '2026-10-10T23:59:00+00:00', origin: 'https://portal.test' };

        expect(reminderText(unit, { ...week, now: Date.parse('2026-10-08T12:00:00Z') })).toBe(
            'Reminder: the 2nd Section (2/1/A/1-7) weekly report for the week of 03Oct26 is due Sat 10Oct26 23:59Z. '
                + 'Please send it from the Leadership Portal: https://portal.test/reports/5/2026-10-03',
        );
        expect(reminderText(unit, { ...week, now: Date.parse('2026-10-11T00:00:00Z') })).toContain('was due Sat 10Oct26 23:59Z and is overdue');
    });
});
