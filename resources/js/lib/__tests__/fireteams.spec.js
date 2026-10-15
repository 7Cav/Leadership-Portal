import { describe, expect, it } from 'vitest';
import { bySeniority, orderTroopers, shownRole } from '../fireteams';

// The order a section is listed in (the owner's order, 2026-10-16): SL, ASL, FTLs (when shown), then everyone, each by
// rank seniority then name.

const t = (id, name, rankShort, role = null, fireteamLeaderId = null) => ({ id, cavId: 1000 + id, name, rankShort, rank: null, role, fireteamLeaderId });
const names = (rows) => rows.map((row) => row.name);

describe('bySeniority', () => {
    it('puts the senior rank first, officers above warrant officers above NCOs above enlisted', () => {
        const rows = [t(1, 'A', 'PVT'), t(2, 'B', '2LT'), t(3, 'C', 'SSG'), t(4, 'D', 'WO1'), t(5, 'E', 'SPC'), t(6, 'F', '1SG')];

        expect(names([...rows].sort(bySeniority))).toEqual(['B', 'D', 'F', 'C', 'E', 'A']);
    });

    it('breaks a tie of rank by name, ignoring case, then by id', () => {
        const rows = [t(3, 'charlie.c', 'CPL'), t(1, 'Bravo.B', 'CPL'), t(4, 'Alpha.A', 'CPL'), t(2, 'Alpha.A', 'CPL')];

        expect([...rows].sort(bySeniority).map((row) => row.id)).toEqual([2, 4, 1, 3]);
    });

    it('sorts an unknown or missing rank below every known one, and a trooper with no username after the named ones', () => {
        const rows = [t(1, 'Zed.Z', 'RCT'), t(2, 'Odd.O', 'XYZ'), t(3, 'Nil.N', null), t(4, null, 'RCT')];

        expect([...rows].sort(bySeniority).map((row) => row.id)).toEqual([1, 4, 3, 2]);
    });

    it('falls back to the full rank when there is no short one', () => {
        expect(bySeniority({ id: 1, name: 'A', rank: 'SGT' }, { id: 2, name: 'B', rankShort: 'PVT' })).toBeLessThan(0);
    });
});

describe('shownRole', () => {
    it('always names the SL and ASL, and an FTL only while FTLs are shown', () => {
        expect([shownRole({ role: 'sl' }, false), shownRole({ role: 'asl' }, false), shownRole({ role: 'ftl' }, false), shownRole({ role: 'ftl' }, true), shownRole({ role: null }, true)])
            .toEqual(['sl', 'asl', null, 'ftl', null]);
    });
});

describe('orderTroopers', () => {
    // Two FTLs with a fireteam each, one trooper in none, and a member whose leader is no FTL in the section.
    const SECTION = [
        t(1, 'Recruit.A', 'RCT'),
        t(2, 'TeamTwo.B', 'SPC', 'ftl'),
        t(3, 'Member2.C', 'PFC', null, 2),
        t(4, 'Asl.D', 'CPL', 'asl'),
        t(5, 'TeamOne.E', 'CPL', 'ftl'),
        t(6, 'Member1.F', 'PVT', null, 5),
        t(7, 'Sl.G', 'SGT', 'sl'),
        t(8, 'Senior.H', 'SGT'),
        t(9, 'Member1.I', 'SPC', null, 5),
        t(10, 'Orphan.J', 'PFC', null, 99),
    ];

    it('defaults to FTLs hidden: SL, ASL, then everyone by rank, FTLs among them', () => {
        expect(names(orderTroopers(SECTION))).toEqual(['Sl.G', 'Asl.D', 'Senior.H', 'TeamOne.E', 'Member1.I', 'TeamTwo.B', 'Member2.C', 'Orphan.J', 'Member1.F', 'Recruit.A']);
    });

    it('with FTLs shown and all above the rest: SL, ASL, FTLs by rank, then everyone by rank', () => {
        expect(names(orderTroopers(SECTION, { show: true, order: 'leaders_first' }))).toEqual([
            'Sl.G', 'Asl.D', 'TeamOne.E', 'TeamTwo.B', 'Senior.H', 'Member1.I', 'Member2.C', 'Orphan.J', 'Member1.F', 'Recruit.A',
        ]);
    });

    it('by fireteam: each FTL directly above their members by rank, troopers in no fireteam after the fireteams', () => {
        expect(names(orderTroopers(SECTION, { show: true, order: 'by_fireteam' }))).toEqual([
            'Sl.G', 'Asl.D', 'TeamOne.E', 'Member1.I', 'Member1.F', 'TeamTwo.B', 'Member2.C', 'Senior.H', 'Orphan.J', 'Recruit.A',
        ]);
    });

    it('ignores the by-fireteam order while FTLs are hidden', () => {
        expect(names(orderTroopers(SECTION, { show: false, order: 'by_fireteam' }))).toEqual(names(orderTroopers(SECTION)));
    });

    it('keeps every trooper exactly once and leaves the input alone', () => {
        const input = [...SECTION];
        const out = orderTroopers(input, { show: true, order: 'by_fireteam' });

        expect(out).toHaveLength(SECTION.length);
        expect(new Set(out).size).toBe(SECTION.length);
        expect(input).toEqual(SECTION);
    });

    it('lists more than one ASL by rank, and a section with no SL starts at the ASL', () => {
        const rows = [t(1, 'Asl.A', 'CPL', 'asl'), t(2, 'Asl.B', 'SGT', 'asl'), t(3, 'Trooper.C', 'SSG')];

        expect(names(orderTroopers(rows))).toEqual(['Asl.B', 'Asl.A', 'Trooper.C']);
    });
});
