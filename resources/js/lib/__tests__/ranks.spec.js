import { describe, expect, it } from 'vitest';
import { RANK_ORDER, billetOrder, compareByBillet, rankIndex } from '../ranks';

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

    it('puts the leader first, then the assistant leader, whatever their rank', () => {
        expect(billetOrder('Section Leader')).toBe(0);
        expect(billetOrder('Platoon Leader')).toBe(0);
        expect(billetOrder('Asst. Section Leader')).toBe(1);
        expect(billetOrder('Assistant Section Leader')).toBe(1);
        expect(billetOrder('Rifleman')).toBe(2);
        expect(billetOrder(null)).toBe(2);
        expect(billetOrder(undefined)).toBe(2);
    });

    it('sorts as the mockups do: leader, assistant leader, then rank senior first, then name', () => {
        const troopers = [
            { rankShort: 'PFC', displayName: 'PFC Svensson.A', billet: 'Trooper' },
            { rankShort: 'SPC', displayName: 'SPC Crooks.A', billet: 'Trooper' },
            { rankShort: 'SPC', displayName: 'SPC Milkovic.D', billet: 'Asst. Section Leader' },
            { rankShort: 'CPL', displayName: 'CPL Flanagan.L', billet: 'Trooper' },
            { rankShort: 'SGT', displayName: 'SGT Jay.JH', billet: 'Section Leader' },
            { rankShort: 'SPC', displayName: 'SPC Adams.P', billet: 'Trooper' },
            { rankShort: null, displayName: '#4374', billet: null },
        ];

        expect(troopers.sort(compareByBillet).map((t) => t.displayName)).toEqual([
            'SGT Jay.JH', 'SPC Milkovic.D', 'CPL Flanagan.L', 'SPC Adams.P', 'SPC Crooks.A', 'PFC Svensson.A', '#4374',
        ]);
    });

    it('lets a junior leader outrank a senior trooper', () => {
        const troopers = [
            { rankShort: 'SSG', displayName: 'SSG Senior.T', billet: 'Trooper' },
            { rankShort: 'CPL', displayName: 'CPL Lead.R', billet: 'Section Leader' },
        ];

        expect(troopers.sort(compareByBillet)[0].displayName).toBe('CPL Lead.R');
    });
});
