import { describe, expect, it } from 'vitest';
import { normalizeName, similarity, suggestTroopers } from '../unmatched';

const troopers = [
    { id: 1, name: 'Dube.R' },
    { id: 2, name: 'Nakamura.T' },
    { id: 3, name: 'Ortega.M' },
    { id: 4, name: 'Halvorsen.K' },
];

describe('unmatched suggestions', () => {
    it('drops clan tags and punctuation from a name', () => {
        expect(normalizeName('[7CAV] Dubes_01')).toBe('dubes01');
        expect(normalizeName(null)).toBe('');
    });

    it('scores one name containing the other highly, and unrelated names low', () => {
        expect(similarity('[7CAV] Naka', 'Nakamura')).toBeGreaterThanOrEqual(0.9);
        expect(similarity('RandoGuy88', 'Ortega')).toBeLessThan(0.6);
        expect(similarity('', 'Ortega')).toBe(0);
    });

    it('suggests the closest troopers, best first, and nobody for a stranger', () => {
        expect(suggestTroopers('Dubes', troopers).map((t) => t.id)).toEqual([1]);
        expect(suggestTroopers('[7CAV] Naka', troopers).map((t) => t.id)).toEqual([2]);
        expect(suggestTroopers('Halvorsen_K', troopers).map((t) => t.id)).toEqual([4]);
        expect(suggestTroopers('RandoGuy88', troopers)).toEqual([]);
    });

    it('tolerates a typo within the surname', () => {
        expect(suggestTroopers('Ortgea', troopers).map((t) => t.id)).toEqual([3]);
    });

    it('offers at most three', () => {
        const many = ['Smith.A', 'Smith.B', 'Smith.C', 'Smith.D'].map((name, id) => ({ id, name }));
        expect(suggestTroopers('Smith', many)).toHaveLength(3);
    });
});
