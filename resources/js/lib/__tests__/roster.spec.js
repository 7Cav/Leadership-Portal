import { describe, expect, it } from 'vitest';
import {
    aggregateWeek,
    compareBillet,
    FILTERS,
    highlightParts,
    isNewRecruit,
    matchesQuery,
    percent,
    percentLabel,
    rankTier,
    SORTS,
    sourceLabel,
    weekCell,
    weekLabel,
} from '../roster';

const mark = (overrides = {}) => ({ rollcall: 'will_attend', status: 'present', source: 'manual', held: true, ...overrides });

describe('weekCell (4.7)', () => {
    it('reads a signed, attended week', () => {
        expect(weekCell(mark(), '2026-09-26')).toEqual({ rc: 'ok', sp: 'ok' });
        expect(weekCell(mark({ rollcall: 'cannot_attend', status: 'makeup' }), '2026-09-26')).toEqual({ rc: 'cn', sp: 'mk' });
        expect(weekCell(mark({ status: 'attached' }), '2026-09-26').sp).toBe('at');
    });

    it('slashes a missed SP and a past unsigned rollcall, but leaves this week\'s unsigned open', () => {
        expect(weekCell(mark({ rollcall: 'unsigned', status: 'missed' }), '2026-09-26')).toEqual({ rc: 'bad', sp: 'bad' });
        expect(weekCell(mark({ rollcall: 'unsigned', status: null }), '2026-10-03', { current: true })).toEqual({ rc: 'open', sp: 'open' });
    });

    it('renders an LOA week as LOA in both halves, never as a miss', () => {
        expect(weekCell(mark({ status: 'loa', rollcall: 'unsigned' }), '2026-09-26')).toEqual({ rc: 'loa', sp: 'loa' });
    });

    it('renders a week with no practice row as no data, not a miss', () => {
        expect(weekCell(null, '2026-09-26')).toEqual({ rc: 'nil', sp: 'nil' });
        expect(weekCell(null, '2026-10-03', { current: true })).toEqual({ rc: 'open', sp: 'pending' });
    });

    it('tells an unmarked trooper in a held practice from a practice not held', () => {
        expect(weekCell(mark({ status: null, held: true }), '2026-09-26').sp).toBe('open');
        expect(weekCell(mark({ status: null, held: false }), '2026-09-26').sp).toBe('nil');
    });

    it('greys out weeks that ended before the trooper joined', () => {
        expect(weekCell(mark(), '2026-09-19', { joined: '2026-09-28' })).toEqual({ rc: 'out', sp: 'out' });
        expect(weekCell(mark(), '2026-09-26', { joined: '2026-09-28' }).rc).toBe('ok');
    });
});

describe('percentages', () => {
    it('rounds a ratio, and says n/a rather than 0% when nothing was eligible', () => {
        expect(percent([3, 4])).toBe(75);
        expect(percent([0, 0])).toBeNull();
        expect(percentLabel([0, 0])).toBe('n/a');
        expect(percentLabel([1, 3])).toBe('33%');
    });
});

describe('ordering and matching', () => {
    const sl = { role: 'SL', rank: 'SGT', name: 'Vasquez.R' };
    const asl = { role: 'ASL', rank: 'CPL', name: 'Lindqvist.E' };
    const spc = { role: null, rank: 'SPC', name: 'Mbeki.K' };
    const pvt = { role: null, rank: 'PVT', name: 'Doyle.A' };

    it('pins SL then ASL, then sorts by rank and name', () => {
        expect([pvt, spc, asl, sl].sort(compareBillet)).toEqual([sl, asl, spc, pvt]);
    });

    it('sorts at-risk first by most misses', () => {
        const risky = { ...pvt, kpis: { atRisk: true, missed: 3 } };
        const lessRisky = { ...spc, kpis: { atRisk: true, missed: 2 } };

        expect([sl, lessRisky, risky].sort(SORTS.risk.compare)).toEqual([risky, lessRisky, sl]);
    });

    it('matches on rank, rank title, name and billet', () => {
        const t = { rank: 'SGT', rankFull: 'Sergeant', name: 'Vasquez.R', billet: 'Section Leader' };

        expect(matchesQuery(t, 'vasq')).toBe(true);
        expect(matchesQuery(t, 'sergeant')).toBe(true);
        expect(matchesQuery(t, 'leader')).toBe(true);
        expect(matchesQuery(t, 'medic')).toBe(false);
        expect(matchesQuery(t, '  ')).toBe(true);
    });

    it('splits text around every hit for highlighting', () => {
        expect(highlightParts('Abernathy.L', 'a')).toEqual([
            { text: 'A', hit: true },
            { text: 'bern', hit: false },
            { text: 'a', hit: true },
            { text: 'thy.L', hit: false },
        ]);
    });

    it('filters by flag, keeping departed troopers out of All', () => {
        expect(FILTERS.all({ departed: true })).toBe(false);
        expect(FILTERS.departed({ departed: true })).toBe(true);
        expect(FILTERS.risk({ kpis: { atRisk: true } })).toBe(true);
        expect(FILTERS.loa({ kpis: { onLoa: false } })).toBe(false);
    });
});

describe('labels', () => {
    it('formats a week in the forum convention', () => {
        expect(weekLabel('2026-10-03')).toBe('03Oct26');
    });

    it('names the source of a mark', () => {
        expect(sourceLabel(mark({ source: 'auto' }))).toMatch(/^Auto/);
        expect(sourceLabel(mark())).toMatch(/^Manual/);
        expect(sourceLabel(mark({ status: 'loa' }))).toBe('LOA');
        expect(sourceLabel(null)).toBeNull();
    });

    it('tiers ranks for the badge', () => {
        expect(rankTier('CPT')).toBe('officer');
        expect(rankTier('SGT')).toBe('nco');
        expect(rankTier('PFC')).toBe('enlisted');
        expect(rankTier('???')).toBe('enlisted');
    });

    it('calls a trooper who joined in the last 8 weeks a new recruit', () => {
        expect(isNewRecruit({ joined: '2026-09-01' }, '2026-10-03')).toBe(true);
        expect(isNewRecruit({ joined: '2026-01-01' }, '2026-10-03')).toBe(false);
        expect(isNewRecruit({ joined: null }, '2026-10-03')).toBe(false);
    });
});

describe('aggregateWeek', () => {
    it('buckets a section week by signed and attended share', () => {
        const members = [
            { weeks: [mark()] },
            { weeks: [mark()] },
            { weeks: [mark({ rollcall: 'unsigned', status: 'missed' })] },
        ];

        const week = aggregateWeek(members, 0, '2026-09-26', false);

        expect([week.signed, week.due, week.attended, week.marked]).toEqual([2, 3, 2, 3]);
        expect(week.rc).toBe('g2');
    });

    it('counts a held practice\'s unmarked member as eligible, as the KPI service does', () => {
        const members = [{ weeks: [mark()] }, { weeks: [mark({ status: null, held: true })] }];

        expect(aggregateWeek(members, 0, '2026-09-26', false)).toMatchObject({ attended: 1, marked: 2 });
    });

    it('shows no data for a week nobody had a practice row', () => {
        expect(aggregateWeek([{ weeks: [null] }], 0, '2026-09-26', false)).toMatchObject({ rc: 'nil', sp: 'nil' });
    });
});

describe('displayName', () => {
    it('falls back to the milpac id when Cav links no username', async () => {
        const { displayName } = await import('../roster');

        expect(displayName({ name: 'Reyes.M', cavId: 1 })).toBe('Reyes.M');
        expect(displayName({ name: null, cavId: 4012 })).toBe('#4012');
    });
});
