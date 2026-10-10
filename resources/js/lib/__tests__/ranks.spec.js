import { describe, expect, it } from 'vitest';
import { RANK_ORDER, compareSeniorFirst, rankIndex } from '../ranks';

describe('ranks', () => {
    it('places a rank by seniority, junior low and senior high', () => {
        expect(rankIndex('RCT')).toBe(0);
        expect(rankIndex('SGT')).toBeGreaterThan(rankIndex('SPC'));
        expect(rankIndex('GEN')).toBe(RANK_ORDER.length - 1);
    });

    it('gives an unknown or missing rank -1, below every known one', () => {
        expect(rankIndex('XYZ')).toBe(-1);
        expect(rankIndex(null)).toBe(-1);
        expect(rankIndex(undefined)).toBe(-1);
    });

    it('sorts senior first, then by name', () => {
        const troopers = [
            { rankShort: 'PFC', displayName: 'PFC Svensson.A' },
            { rankShort: 'SPC', displayName: 'SPC Crooks.A' },
            { rankShort: 'SGT', displayName: 'SGT Jay.JH' },
            { rankShort: 'SPC', displayName: 'SPC Adams.P' },
            { rankShort: null, displayName: '#4374' },
        ];

        expect(troopers.sort(compareSeniorFirst).map((t) => t.displayName)).toEqual(['SGT Jay.JH', 'SPC Adams.P', 'SPC Crooks.A', 'PFC Svensson.A', '#4374']);
    });
});
