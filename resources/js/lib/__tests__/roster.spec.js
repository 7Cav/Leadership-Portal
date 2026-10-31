import { describe, expect, it } from 'vitest';
import {
    aggregateWeek,
    compareBillet,
    compareOrbat,
    FILTERS,
    highlightParts,
    historyCell,
    isNewRecruit,
    matchesQuery,
    monthsSince,
    nextOrbatSort,
    ORBAT_SORT_STORAGE_KEY,
    percent,
    percentLabel,
    RANK_NAMES,
    RANK_ORDER,
    rankIndex,
    rankTier,
    RC_STATES,
    readOrbatSort,
    shortDuration,
    SORTS,
    sourceLabel,
    SP_STATES,
    transferLabel,
    weekCell,
    weekLabel,
    writeOrbatSort,
} from '../roster';
import insigniaSource from '../rankInsignia.js?raw';

const mark = (overrides = {}) => ({ rollcall: 'will_attend', status: 'present', source: 'manual', held: true, ...overrides });

describe('weekCell (4.7)', () => {
    it('reads a signed, attended week', () => {
        expect(weekCell(mark(), '2026-09-26')).toEqual({ rc: 'ok', sp: 'ok' });
        expect(weekCell(mark({ rollcall: 'cannot_attend', status: 'makeup' }), '2026-09-26')).toEqual({ rc: 'cn', sp: 'mk' });
        expect(weekCell(mark({ rollcall: 'makeup_requested', status: 'excused' }), '2026-09-26')).toEqual({ rc: 'mr', sp: 'ex' });
        expect(weekCell(mark({ rollcall: 'excused', status: 'missed' }), '2026-09-26')).toEqual({ rc: 'ex', sp: 'bad' });
    });

    it('names the states in the words of 2026-10-30, with no Attached left', () => {
        expect(RC_STATES).toMatchObject({ ok: 'Will attend', cn: 'Will not attend', mr: 'Make-up requested', ex: 'Excused' });
        expect(SP_STATES).toMatchObject({ ok: 'Attended', bad: 'Not attended', mk: 'Make-up complete', ex: 'Excused' });
        expect(SP_STATES).not.toHaveProperty('at');
    });

    it('shows an automatic Excused on each half nobody recorded, and what a leader recorded otherwise', () => {
        expect(weekCell(null, '2026-09-26', { excused: 'transfer' })).toEqual({ rc: 'ex', sp: 'ex' });
        expect(weekCell(mark({ rollcall: 'unsigned', status: null, held: true }), '2026-09-26', { excused: 'recruit' })).toEqual({ rc: 'ex', sp: 'ex' });
        expect(weekCell(mark({ rollcall: 'will_attend', status: 'missed', source: 'manual' }), '2026-09-26', { excused: 'transfer' })).toEqual({ rc: 'ok', sp: 'bad' });
        expect(weekCell(mark({ rollcall: 'unsigned', status: 'missed', source: 'auto' }), '2026-09-26', { excused: 'transfer' })).toEqual({ rc: 'ex', sp: 'ex' });
        // The mark's own `excused` (the roll call's history entries) reads the same.
        expect(weekCell({ ...mark({ status: null, held: false }), excused: 'transfer' }, '2026-09-26').sp).toBe('ex');
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

    // #443: every echelon's command team carries its own tag now, not "SL".
    it('sorts an acting SL where the SL goes, ahead of the ASL and the untagged', () => {
        const acting = { role: 'Acting SL', rank: 'SPC', name: 'Oduya.N' };
        const senior = { role: null, rank: 'MSG', name: 'Ward.T' };

        expect([senior, pvt, asl, acting].sort(compareBillet)).toEqual([acting, asl, senior, pvt]);
    });

    // #444: the command team in chain-of-command order, whatever their ranks, then everyone by rank.
    describe('the command team', () => {
        const senior = { role: null, rank: 'COL', name: 'Abbott.A' };

        it('lists a company, battalion or the regiment as CO, XO, then the senior NCO', () => {
            const co = { role: 'CO', rank: 'CPT', name: 'Zane.Z' };
            const xo = { role: 'XO', rank: 'CPT', name: 'Adams.A' };
            const first = { role: '1SG', rank: '1SG', name: 'Brook.B' };
            expect([senior, first, xo, co].sort(compareBillet)).toEqual([co, xo, first, senior]);

            const rco = { role: 'CO', rank: 'COL', name: 'Young.Y' };
            const rxo = { role: 'XO', rank: 'COL', name: 'Allen.A' };
            const csm = { role: 'CSM', rank: 'CSM', name: 'Baker.B' };
            expect([csm, rxo, rco].sort(compareBillet)).toEqual([rco, rxo, csm]);

            const bnCo = { role: 'CO', rank: 'LTC', name: 'Young.Y' };
            const sgm = { role: 'SGM', rank: 'SGM', name: 'Baker.B' };
            const bnXo = { role: 'XO', rank: 'MAJ', name: 'Allen.A' };
            expect([sgm, bnXo, bnCo].sort(compareBillet)).toEqual([bnCo, bnXo, sgm]);
        });

        it('lists a platoon as PL then PSG, an acting PL where the PL goes', () => {
            const pl = { role: 'PL', rank: '2LT', name: 'Young.Y' };
            const psg = { role: 'PSG', rank: 'SFC', name: 'Allen.A' };
            const acting = { role: 'Acting PL', rank: 'SSG', name: 'Young.Y' };

            expect([senior, psg, pl].sort(compareBillet)).toEqual([pl, psg, senior]);
            expect([senior, psg, acting].sort(compareBillet)).toEqual([acting, psg, senior]);
        });

        it('keeps the XO first while the CO billet is vacant', () => {
            const xo = { role: 'XO', rank: '1LT', name: 'Adams.A' };
            const first = { role: '1SG', rank: '1SG', name: 'Brook.B' };

            expect([senior, first, xo].sort(compareBillet)).toEqual([xo, first, senior]);
        });

        it('leaves a column sort to put the command team where they fall, only breaking ties by it', () => {
            const co = { role: 'CO', rank: 'CPT', name: 'Zane.Z' };
            const xo = { role: 'XO', rank: 'CPT', name: 'Adams.A' };
            const first = { role: '1SG', rank: '1SG', name: 'Brook.B' };
            const list = [co, xo, first, senior];

            expect([...list].sort(compareOrbat({ key: 'name', dir: 'asc' }))).toEqual([senior, xo, first, co]);
            expect([...list].sort(compareOrbat({ key: 'rank', dir: 'asc' }))).toEqual([first, co, xo, senior]);
        });
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
    it('names a week by its Saturday and Friday', () => {
        expect(weekLabel('2026-10-24')).toBe('24OCT26 to 30OCT26');
    });

    it('names the source of a mark', () => {
        expect(sourceLabel(mark({ source: 'auto' }))).toMatch(/^Auto/);
        expect(sourceLabel(mark())).toMatch(/^Manual/);
        expect(sourceLabel(mark({ status: 'loa' }))).toBe('LOA');
        expect(sourceLabel(null)).toBeNull();
    });

    it('tiers ranks for the badge', () => {
        expect(rankTier('CPT')).toBe('officer');
        expect(rankTier('2LT')).toBe('officer');
        expect(rankTier('WO1')).toBe('warrant');
        expect(rankTier('CW5')).toBe('warrant');
        expect(rankTier('CSM')).toBe('nco');
        expect(rankTier('SGT')).toBe('nco');
        expect(rankTier('PFC')).toBe('enlisted');
        expect(rankTier('???')).toBe('enlisted');
    });

    it('draws a General of the Army as the senior-most officer', () => {
        expect(rankTier('GOA')).toBe('officer');
        expect(rankIndex('GOA')).toBeGreaterThan(rankIndex('GEN'));
        expect(RANK_ORDER.at(-1)).toBe('GOA');
        expect(RANK_NAMES.GOA).toBe('General of the Army');
    });

    // rankInsignia.js names every rank the Cav roster has, one per insignia image; each needs a place in the order and
    // a name, or its badge falls to the enlisted look and sorts below a recruit.
    it('knows every rank the Cav roster uses', () => {
        const cavRanks = [...insigniaSource.matchAll(/^\s+\d+: \[[^\]]+\], \/\/ (.+)$/gm)].map((m) => m[1].toLowerCase());
        const named = Object.fromEntries(Object.entries(RANK_NAMES).map(([short, name]) => [name.toLowerCase(), short]));

        expect(cavRanks).toHaveLength(28);
        for (const rank of cavRanks) {
            expect(named[rank], rank).toBeDefined();
            expect(RANK_ORDER, rank).toContain(named[rank]);
        }
        expect(Object.keys(RANK_NAMES).sort()).toEqual([...RANK_ORDER].sort());
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

    it('counts make-up requested as signed, and leaves an excused member out of both shares, like LOA', () => {
        const members = [
            { weeks: [mark({ rollcall: 'makeup_requested', status: 'makeup' })] },
            { weeks: [mark({ rollcall: 'excused', status: 'excused' })] },
            { weeks: [mark({ rollcall: 'unsigned', status: null })], excusedWeeks: { '2026-09-26': 'transfer' } },
            { weeks: [mark({ rollcall: 'unsigned', status: 'missed' })] },
        ];

        expect(aggregateWeek(members, 0, '2026-09-26', false)).toMatchObject({ signed: 1, due: 2, attended: 1, marked: 2 });
    });
});

describe('displayName', () => {
    it('falls back to the milpac id when Cav links no username', async () => {
        const { displayName } = await import('../roster');

        expect(displayName({ name: 'Reyes.M', cavId: 1 })).toBe('Reyes.M');
        expect(displayName({ name: null, cavId: 4012 })).toBe('#4012');
    });
});

describe('order of battle column sorts', () => {
    const t = (name, over = {}) => ({ name, rank: 'PFC', role: null, departed: false, joined: '2024-01-01', tig: '2025-01-01', kpis: { rc: [3, 4], sp: [3, 4] }, ...over });
    const names = (list, sort) => [...list].sort(compareOrbat(sort)).map((x) => x.name);

    it('keeps the billet order with no sort', () => {
        const list = [t('B'), t('A', { role: 'ASL' }), t('C', { role: 'SL' })];

        expect(names(list, null)).toEqual(['C', 'A', 'B']);
        expect(compareOrbat(null)).toBe(compareBillet);
        expect(compareOrbat({ key: 'nope', dir: 'asc' })).toBe(compareBillet);
    });

    it('sorts by rank seniority, most senior first, then reversed', () => {
        const list = [t('Pvt', { rank: 'PVT' }), t('Cpt', { rank: 'CPT' }), t('Sgt', { rank: 'SGT' }), t('Cw2', { rank: 'CW2' })];

        expect(names(list, { key: 'rank', dir: 'desc' })).toEqual(['Cpt', 'Cw2', 'Sgt', 'Pvt']);
        expect(names(list, { key: 'rank', dir: 'asc' })).toEqual(['Pvt', 'Sgt', 'Cw2', 'Cpt']);
    });

    it('puts a General of the Army above a General, and an unknown rank below everyone in the billet order', () => {
        const list = [t('Unknown', { rank: 'XYZ' }), t('Gen', { rank: 'GEN' }), t('Rct', { rank: 'RCT' }), t('Goa', { rank: 'GOA' })];

        expect(names(list, { key: 'rank', dir: 'desc' })).toEqual(['Goa', 'Gen', 'Rct', 'Unknown']);
        expect([...list].sort(compareBillet).map((x) => x.name)).toEqual(['Goa', 'Gen', 'Rct', 'Unknown']);
    });

    it('sorts by name, ignoring case, and by milpac id for a trooper with no name', () => {
        const list = [t('charlie.c'), t('Alpha.A'), t(null, { cavId: 12 }), t('bravo.b')];

        expect(names(list, { key: 'name', dir: 'asc' })).toEqual([null, 'Alpha.A', 'bravo.b', 'charlie.c']);
        expect(names(list, { key: 'name', dir: 'desc' })).toEqual(['charlie.c', 'bravo.b', 'Alpha.A', null]);
    });

    it('sorts by time in service, longest first, and by time in grade the same way', () => {
        const list = [
            t('New', { joined: '2026-06-01', tig: '2026-08-01' }),
            t('Old', { joined: '2021-03-01', tig: '2026-01-01' }),
            t('Mid', { joined: '2024-05-01', tig: '2024-06-01' }),
        ];

        expect(names(list, { key: 'tis', dir: 'desc' })).toEqual(['Old', 'Mid', 'New']);
        expect(names(list, { key: 'tis', dir: 'asc' })).toEqual(['New', 'Mid', 'Old']);
        expect(names(list, { key: 'tig', dir: 'desc' })).toEqual(['Mid', 'Old', 'New']);
        expect(names(list, { key: 'tig', dir: 'asc' })).toEqual(['New', 'Old', 'Mid']);
    });

    it('sorts by RC and SP 30d, lowest first', () => {
        const list = [
            t('Full', { kpis: { rc: [4, 4], sp: [1, 4] } }),
            t('Half', { kpis: { rc: [2, 4], sp: [4, 4] } }),
            t('Most', { kpis: { rc: [3, 4], sp: [2, 4] } }),
        ];

        expect(names(list, { key: 'rc', dir: 'asc' })).toEqual(['Half', 'Most', 'Full']);
        expect(names(list, { key: 'rc', dir: 'desc' })).toEqual(['Full', 'Most', 'Half']);
        expect(names(list, { key: 'sp', dir: 'asc' })).toEqual(['Full', 'Most', 'Half']);
    });

    it('puts unknown values last whichever way the column runs', () => {
        const list = [
            t('NoRank', { rank: 'XYZ' }),
            t('NoDate', { joined: null, tig: null }),
            t('Gone', { departed: true, joined: '2019-01-01', tig: '2019-01-01' }),
            t('NoRc', { kpis: null }),
            t('Known', { rank: 'SGT', joined: '2025-01-01', tig: '2025-01-01', kpis: { rc: [1, 4], sp: [1, 4] } }),
        ];

        for (const dir of ['asc', 'desc']) {
            expect(names(list, { key: 'rank', dir }).at(-1)).toBe('NoRank');
            expect(names(list, { key: 'tis', dir }).slice(-2)).toEqual(['Gone', 'NoDate']);
            expect(names(list, { key: 'tig', dir }).slice(-2)).toEqual(['Gone', 'NoDate']);
            expect(names(list, { key: 'rc', dir }).at(-1)).toBe('NoRc');
        }
    });

    it('breaks ties in the billet order: SL, ASL, rank, then name', () => {
        const list = [t('Zulu.Z'), t('Bravo.B', { rank: 'CPL' }), t('Alpha.A'), t('Yankee.Y', { role: 'SL', rank: 'SGT' }), t('Xray.X', { role: 'ASL' })];

        // Everyone has the same RC 30d, so the default order decides, whichever way the column runs.
        expect(names(list, { key: 'rc', dir: 'asc' })).toEqual(['Yankee.Y', 'Xray.X', 'Bravo.B', 'Alpha.A', 'Zulu.Z']);
        expect(names(list, { key: 'rc', dir: 'desc' })).toEqual(['Yankee.Y', 'Xray.X', 'Bravo.B', 'Alpha.A', 'Zulu.Z']);
        // Same rank: the ASL still comes before their peers, then names A to Z.
        expect(names(list.filter((x) => x.rank === 'PFC'), { key: 'rank', dir: 'desc' })).toEqual(['Xray.X', 'Alpha.A', 'Zulu.Z']);
    });

    it('starts a column its natural way, reverses it on a second click, and goes back to the default on a third', () => {
        expect(nextOrbatSort(null, 'rank')).toEqual({ key: 'rank', dir: 'desc' });
        expect(nextOrbatSort({ key: 'rank', dir: 'desc' }, 'rank')).toEqual({ key: 'rank', dir: 'asc' });
        expect(nextOrbatSort({ key: 'rank', dir: 'asc' }, 'rank')).toBeNull();
        expect(nextOrbatSort(null, 'name')).toEqual({ key: 'name', dir: 'asc' });
        expect(nextOrbatSort(null, 'tis')).toEqual({ key: 'tis', dir: 'desc' });
        expect(nextOrbatSort(null, 'tig')).toEqual({ key: 'tig', dir: 'desc' });
        expect(nextOrbatSort(null, 'rc')).toEqual({ key: 'rc', dir: 'asc' });
        // Another column starts fresh rather than inheriting the direction.
        expect(nextOrbatSort({ key: 'rank', dir: 'asc' }, 'sp')).toEqual({ key: 'sp', dir: 'asc' });
    });

    describe('remembering the choice', () => {
        const memory = () => {
            const data = new Map();

            return { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, String(v)), removeItem: (k) => data.delete(k), data };
        };
        const deny = () => {
            throw new Error('denied');
        };
        const broken = { getItem: deny, setItem: deny, removeItem: deny };

        it('writes and reads it back, and clears it for the default order', () => {
            const storage = memory();

            writeOrbatSort({ key: 'tig', dir: 'desc' }, storage);
            expect(storage.data.get(ORBAT_SORT_STORAGE_KEY)).toBe('{"key":"tig","dir":"desc"}');
            expect(readOrbatSort(storage)).toEqual({ key: 'tig', dir: 'desc' });

            writeOrbatSort(null, storage);
            expect(storage.data.has(ORBAT_SORT_STORAGE_KEY)).toBe(false);
            expect(readOrbatSort(storage)).toBeNull();
        });

        it('ignores a stored value it does not recognise', () => {
            const storage = memory();

            for (const value of ['not json', '{"key":"billet","dir":"asc"}', '{"key":"rank","dir":"up"}', '42', 'null']) {
                storage.setItem(ORBAT_SORT_STORAGE_KEY, value);
                expect(readOrbatSort(storage), value).toBeNull();
            }
        });

        it('ignores a stored key that only names a built-in object property', () => {
            const storage = memory();

            for (const key of ['constructor', 'toString', '__proto__', 'hasOwnProperty']) {
                storage.setItem(ORBAT_SORT_STORAGE_KEY, JSON.stringify({ key, dir: 'asc' }));
                expect(readOrbatSort(storage), key).toBeNull();
            }
            expect(compareOrbat({ key: 'constructor', dir: 'asc' })).toBe(compareBillet);
        });

        it('gets by without storage', () => {
            expect(readOrbatSort(broken)).toBeNull();
            expect(() => writeOrbatSort({ key: 'rank', dir: 'desc' }, broken)).not.toThrow();
            expect(() => writeOrbatSort(null, broken)).not.toThrow();
            expect(readOrbatSort(undefined)).toBeNull();
        });
    });
});

describe('time in service and grade', () => {
    it('counts whole months up to today', () => {
        expect(monthsSince('2024-03-15', '2026-10-16')).toBe(31);
        expect(monthsSince('2024-03-17', '2026-10-16')).toBe(30);
        expect(monthsSince('2026-10-01', '2026-10-16')).toBe(0);
        expect(monthsSince(null, '2026-10-16')).toBeNull();
        expect(monthsSince('2027-01-01', '2026-10-16')).toBeNull();
    });

    it('reads short enough for a column', () => {
        expect(shortDuration(31)).toBe('2y 7m');
        expect(shortDuration(24)).toBe('2y');
        expect(shortDuration(7)).toBe('7m');
        expect(shortDuration(0)).toBe('<1m');
        expect(shortDuration(null)).toBe('—');
    });
});

// Decided 2026-10-27: the milpac and the Sheet show a trooper's moves and their attendance across every unit.
describe('transfers and the attendance history', () => {
    it('words a move, a first placement and a trooper left unplaced, with the forum date', () => {
        expect(transferLabel({ at: '2026-10-12T18:00:00Z', from: '2/1/A/1-7', to: '1/2/A/1-7' })).toBe('Moved from 2/1/A/1-7 to 1/2/A/1-7, 12OCT26');
        expect(transferLabel({ at: '2026-10-12T18:00:00Z', from: null, to: '1/2/A/1-7' })).toBe('Placed in 1/2/A/1-7, 12OCT26');
        expect(transferLabel({ at: '2026-10-12T18:00:00Z', from: '2/1/A/1-7', to: null })).toBe('Left 2/1/A/1-7, 12OCT26');
    });

    const week = (overrides = {}) => ({ week: '2026-09-19', section: { id: 4, label: '2/1/A/1-7' }, rollcall: 'will_attend', status: 'present', source: 'manual', held: true, loa: false, ...overrides });

    it('reads a week at the old section like a roster week', () => {
        expect(historyCell(week())).toEqual({ rc: 'ok', sp: 'ok' });
        expect(historyCell(week({ rollcall: 'unsigned', status: 'missed' }))).toEqual({ rc: 'bad', sp: 'bad' });
    });

    it('shows a first week excused automatically on the milpac, with or without a practice row', () => {
        expect(historyCell(week({ rollcall: null, status: null, held: false, excused: 'recruit' }))).toEqual({ rc: 'ex', sp: 'ex' });
        expect(historyCell(week({ rollcall: 'will_attend', status: null, excused: 'transfer' }))).toEqual({ rc: 'ok', sp: 'ex' });
    });

    it('shows leave over the marks, a week in no section as none (no section that week), and a week with no practice row as nothing posted', () => {
        expect(historyCell(week({ loa: true, status: null }))).toEqual({ rc: 'loa', sp: 'loa' });
        expect(historyCell(week({ section: null, rollcall: null, status: null, held: false }))).toEqual({ rc: 'none', sp: 'none' });
        expect(historyCell(week({ rollcall: null, status: null, held: false }))).toEqual({ rc: 'nil', sp: 'nil' });
        expect(historyCell(week({ rollcall: null, status: null, held: false }), { current: true })).toEqual({ rc: 'open', sp: 'pending' });
    });
});
