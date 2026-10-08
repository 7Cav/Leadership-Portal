import { describe, expect, it } from 'vitest';
import { countsOf, displayName, fullName, initials, practiceGroups, signatureGroups, stepsOf } from '../rollcall';

const t = (id, rollcall, status, extra = {}) => ({ id, cavId: 1000 + id, rank: 'PFC', name: `Trooper${id}.A`, rollcall, status, source: null, priorStatus: null, ...extra });

describe('rollcall vocabulary', () => {
    it('names a trooper without a portal account by milpac id, never an invented name', () => {
        const nameless = t(1, 'unsigned', null, { name: null, rank: null });

        expect(displayName(nameless)).toBe('Milpac #1001');
        expect(fullName(nameless)).toBe('Milpac #1001');
        expect(initials(nameless)).toBe('#');
        expect(fullName(t(2, 'unsigned', null, { name: 'Vasquez.R', rank: 'Sergeant' }))).toBe('Sergeant Vasquez.R');
        expect(initials(t(2, 'unsigned', null, { name: 'Vasquez.R' }))).toBe('VR');
    });

    it('counts LOA rows apart from everyone expected to sign and attend', () => {
        const counts = countsOf([
            t(1, 'will_attend', 'present'),
            t(2, 'cannot_attend', 'missed'),
            t(3, 'unsigned', null),
            t(4, 'will_attend', 'makeup'),
            t(5, 'unsigned', 'loa'),
        ]);

        expect(counts).toEqual({
            total: 4, loa: 1, willAttend: 2, cannotAttend: 1, unsigned: 1,
            present: 1, missed: 1, makeup: 1, attached: 0, unmarked: 1,
        });
    });

    it('marks a step done only once nothing is left on it', () => {
        expect(stepsOf([t(1, 'unsigned', null)]).map((s) => s.state)).toEqual(['warn', 'warn', 'todo']);
        expect(stepsOf([t(1, 'will_attend', 'present')], '2026-10-08T16:20:00Z').map((s) => s.state)).toEqual(['done', 'done', 'done']);
    });

    it('lists "cannot attend" first on the practice step, LOA last', () => {
        const groups = practiceGroups([t(1, 'will_attend', null), t(2, 'unsigned', null), t(3, 'cannot_attend', null), t(4, 'unsigned', 'loa')]);

        expect(groups.map((g) => g.id)).toEqual(['cannot', 'unsigned', 'will', 'loa']);
    });

    it('narrows the practice step to the rows that still need a look', () => {
        const groups = practiceGroups([t(1, 'will_attend', 'present'), t(2, 'will_attend', 'missed'), t(3, 'will_attend', null), t(4, 'unsigned', 'loa')], true);

        expect(groups.flatMap((g) => g.troopers.map((x) => x.id))).toEqual([2, 3]);
    });

    it('lists the unsigned first on the signature step and drops empty groups', () => {
        expect(signatureGroups([t(1, 'will_attend', null), t(2, 'unsigned', null)]).map((g) => g.id)).toEqual(['unsigned', 'will']);
    });
});
