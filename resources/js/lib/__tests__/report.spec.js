import { describe, expect, it } from 'vitest';
import {
    KPIS,
    baselineText,
    defaultSendReason,
    formatKpi,
    highlights,
    kpiChange,
    percent,
    readinessChecks,
    sentenceDiff,
} from '../report';

const kpi = (key) => KPIS.find((k) => k.key === key);
const trooper = (id, overrides = {}) => ({ id, name: `PFC T${id}`, rollcall: 'will_attend', status: 'present', atRisk: false, missed: 0, onLoa: false, ...overrides });

describe('KPI formatting', () => {
    it('renders a rate with nothing eligible as a dash, never 0%', () => {
        expect(percent({ numerator: 0, denominator: 0 })).toBeNull();
        expect(formatKpi({ rc: { numerator: 0, denominator: 0 } }, kpi('rc'))).toBe('—');
        expect(formatKpi({ rc: { numerator: 3, denominator: 4 } }, kpi('rc'))).toBe('75%');
    });

    it('colours a change by what it means for that KPI (5.4)', () => {
        expect(kpiChange({ strength: 1 }, kpi('strength'))).toMatchObject({ text: '+1', tone: 'good', direction: 'up' });
        expect(kpiChange({ atRisk: 1 }, kpi('atRisk'))).toMatchObject({ text: '+1', tone: 'bad' });
        expect(kpiChange({ onLoa: -1 }, kpi('onLoa'))).toMatchObject({ text: '-1', tone: 'good' });
        expect(kpiChange({ makeups: 0 }, kpi('makeups'))).toMatchObject({ text: '0', tone: 'neutral', direction: 'flat' });
    });

    it('turns a rate delta in basis points into whole percentage points', () => {
        expect(kpiChange({ spBp: -312 }, kpi('sp'))).toMatchObject({ value: -3, tone: 'bad', unit: 'points' });
    });

    it('shows no change when there is no delta or the rate had nothing eligible', () => {
        expect(kpiChange(null, kpi('strength'))).toBeNull();
        expect(kpiChange({ rcBp: null }, kpi('rc'))).toBeNull();
    });
});

describe('baselineText', () => {
    it('distinguishes the prior week, a skipped week, and a first report', () => {
        expect(baselineText({ kind: 'prior', week: '2026-09-26T00:00:00+00:00' })).toContain('26Sep26');
        expect(baselineText({ kind: 'no_prior', week: '2026-09-19T00:00:00+00:00' })).toMatch(/No prior baseline.*19Sep26/);
        expect(baselineText({ kind: 'first', week: null })).toMatch(/^First report/);
    });
});

describe('readinessChecks', () => {
    const content = { trainingSubject: 'Room clearing', unaccounted: [] };

    it('passes every check for a fully marked, explained, reviewed section', () => {
        const checks = readinessChecks({ isLeaf: true, troopers: [trooper(1), trooper(2)], content, reviewed: true });

        expect(checks.map((c) => c.id)).toEqual(['rollcall', 'practice', 'unaccounted', 'subject', 'reviewed']);
        expect(checks.every((c) => c.done)).toBe(true);
    });

    it('flags unsigned, unmarked and unexplained troopers, counting LOA as accounted', () => {
        const troopers = [
            trooper(1, { rollcall: 'unsigned' }),
            trooper(2, { status: null }),
            trooper(3, { status: 'missed' }),
            trooper(4, { rollcall: 'unsigned', status: 'loa', onLoa: true }),
        ];
        const checks = Object.fromEntries(
            readinessChecks({ isLeaf: true, troopers, content: { trainingSubject: 'ab', unaccounted: [] }, reviewed: false }).map((c) => [c.id, c]),
        );

        expect(checks.rollcall).toMatchObject({ done: false, count: '3/4' });
        expect(checks.practice).toMatchObject({ done: false, count: '2/3' });
        expect(checks.unaccounted).toMatchObject({ done: false, count: '0/1' });
        expect(checks.subject.done).toBe(false);
        expect(checks.reviewed.done).toBe(false);
    });

    it('counts a reason as explaining the miss', () => {
        const checks = readinessChecks({
            isLeaf: true,
            troopers: [trooper(3, { status: 'missed' })],
            content: { trainingSubject: '', unaccounted: [{ trooper_id: 3, reason: 'sick' }] },
            reviewed: false,
        });

        expect(checks.find((c) => c.id === 'unaccounted')).toMatchObject({ done: true, count: '1/1' });
    });

    it('checks a roll-up for its children instead', () => {
        const children = [
            { id: 1, callsign: '1/1/A', status: 'sent' },
            { id: 2, callsign: '2/1/A', status: 'draft' },
        ];
        const checks = readinessChecks({ isLeaf: false, children, content: {}, reviewed: true });

        expect(checks.map((c) => c.id)).toEqual(['children', 'reviewed']);
        expect(checks[0]).toMatchObject({ done: false, count: '1/2', detail: 'Waiting on 2/1/A.' });
    });
});

describe('defaultSendReason', () => {
    it('is a plain weekly report when nothing is open', () => {
        expect(defaultSendReason([])).toBe('Weekly report');
    });

    it('records open items and missing units so the audit log shows a knowing send', () => {
        expect(defaultSendReason([{ title: 'Training subject filled' }], ['3/1/A'])).toBe(
            'Weekly report, sent with open items: training subject filled; without 3/1/A',
        );
    });
});

describe('highlights', () => {
    it('writes facts from the data, including an at-risk trooper and their reason', () => {
        const list = highlights({
            kpis: { strength: 2, rc: { numerator: 8, denominator: 8 }, sp: { numerator: 6, denominator: 8 }, atRisk: 1, onLoa: 0, makeups: 0 },
            delta: { spBp: 500 },
            troopers: [trooper(1), trooper(2, { status: 'missed', atRisk: true, missed: 2 })],
            reasons: new Map([[2, 'sick']]),
            reasonLabels: { sick: 'Sick' },
            isLeaf: true,
        });
        const text = list.map((h) => h.text).join('\n');

        expect(text).toContain('SP up 5 pts to 75% over 30 days, 0 makeups this week.');
        expect(text).toContain('PFC T2 has missed 2 practices in 30 days and is at-risk. Reason: sick.');
        expect(text).toContain('Rollcall signed by 2 of 2');
    });

    it('writes nothing when there are no KPIs', () => {
        expect(highlights({ kpis: null })).toEqual([]);
    });
});

describe('sentenceDiff', () => {
    it('marks added, removed and unchanged sentences', () => {
        expect(sentenceDiff('Good week. Comms weak.', 'Good week. Comms better.')).toEqual([
            { kind: 'same', text: 'Good week.' },
            { kind: 'added', text: 'Comms better.' },
            { kind: 'removed', text: 'Comms weak.' },
        ]);
    });
});
