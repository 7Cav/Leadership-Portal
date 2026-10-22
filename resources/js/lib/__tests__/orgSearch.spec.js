import { describe, expect, it } from 'vitest';
import { ancestorIds, searchUnits } from '../orgSearch';

const UNITS = [
    { id: 1, parent_id: null, type: 'regiment', name: '7th Cavalry Regiment', callsign: null, path: '1/' },
    { id: 2, parent_id: 1, type: 'battalion', name: '2nd Battalion', callsign: '2-7', path: '1/2/' },
    { id: 10, parent_id: 2, type: 'company', name: 'Alpha Company', callsign: 'A/2-7', path: '1/2/10/' },
    { id: 11, parent_id: 10, type: 'platoon', name: '1st Platoon', callsign: 'Nightriders', path: '1/2/10/11/' },
    { id: 12, parent_id: 11, type: 'section', name: '1st Squad', callsign: 'Nightriders-1', path: '1/2/10/11/12/' },
    { id: 3, parent_id: 10, type: 'platoon', name: '2nd Platoon', callsign: null, path: '1/2/10/3/' },
    { id: 4, parent_id: 3, type: 'section', name: '1st Squad', callsign: null, path: '1/2/10/3/4/' },
];

describe('ancestorIds', () => {
    it('reads the ids above a unit from its path, root first', () => {
        expect(ancestorIds(UNITS[4])).toEqual([1, 2, 10, 11]);
    });

    it('is empty for the root', () => {
        expect(ancestorIds(UNITS[0])).toEqual([]);
    });
});

describe('searchUnits', () => {
    it('returns nothing for an empty or blank query', () => {
        expect(searchUnits(UNITS, '')).toEqual({ results: [], total: 0 });
        expect(searchUnits(UNITS, '   ')).toEqual({ results: [], total: 0 });
    });

    it('matches part of a name, ignoring case', () => {
        expect(searchUnits(UNITS, 'ALPHA').results.map((r) => r.id)).toEqual([10]);
    });

    it('matches part of a callsign, ignoring case', () => {
        expect(searchUnits(UNITS, 'nightr').results.map((r) => r.id)).toEqual([11, 12]);
        expect(searchUnits(UNITS, 'a/2').results.map((r) => r.id)).toEqual([10]);
    });

    it('keeps chain-of-command order, by path', () => {
        // Platoon 3 sits under company 10, so numeric path order puts it before platoon 11.
        expect(searchUnits(UNITS, 'platoon').results.map((r) => r.id)).toEqual([3, 11]);
    });

    it('gives each match the names above it, the root left out, so same-named units differ', () => {
        const { results } = searchUnits(UNITS, '1st squad');

        expect(results.map((r) => r.trail)).toEqual(['2nd Battalion / Alpha Company / 2nd Platoon', '2nd Battalion / Alpha Company / 1st Platoon']);
        expect(results[0]).toMatchObject({ id: 4, name: '1st Squad', callsign: null, type: 'section' });
    });

    it('gives the root and a battalion an empty trail', () => {
        expect(searchUnits(UNITS, '7th').results[0].trail).toBe('');
        expect(searchUnits(UNITS, '2nd bat').results[0].trail).toBe('');
    });

    it('caps the list but reports the full count', () => {
        const { results, total } = searchUnits(UNITS, 'a', { limit: 2 });

        expect(results).toHaveLength(2);
        expect(total).toBeGreaterThan(2);
    });

    it('reports no match as an empty list', () => {
        expect(searchUnits(UNITS, 'zulu')).toEqual({ results: [], total: 0 });
    });
});
