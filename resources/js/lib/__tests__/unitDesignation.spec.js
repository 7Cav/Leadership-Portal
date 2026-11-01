import { describe, it, expect } from 'vitest';
import { childDesignation, ordinal, ordinalOptions } from '../unitDesignation';

describe('unitDesignation', () => {
    it('builds each echelon from its parent the way the org import does', () => {
        expect(childDesignation('battalion', '7CAV', '3')).toEqual({ name: '3rd Battalion', callsign: '3-7' });
        expect(childDesignation('company', '3-7', 'B')).toEqual({ name: 'Bravo Company', callsign: 'B/3-7' });
        expect(childDesignation('company', 'ACD', 'B')).toEqual({ name: 'Baker Company', callsign: 'B/ACD' });
        expect(childDesignation('platoon', 'A/3-7', '1')).toEqual({ name: '1st Platoon', callsign: '1/A/3-7' });
        expect(childDesignation('section', '1/A/3-7', '2')).toEqual({ name: '2nd Section', callsign: '2/1/A/3-7' });
    });

    it('builds nothing from a parent of the wrong shape or an ordinal of the wrong kind', () => {
        expect(childDesignation('platoon', '3-7', '1')).toBeNull();
        expect(childDesignation('section', null, '1')).toBeNull();
        expect(childDesignation('company', '3-7', '2')).toBeNull();
        expect(childDesignation('section', '1/A/3-7', '10')).toBeNull();
    });

    it('spells ordinals', () => {
        expect([1, 2, 3, 4, 11, 12, 13, 21].map(ordinal)).toEqual(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st']);
    });

    it('offers 1st to 9th, leaving out the ones already used and keeping the gaps', () => {
        const platoon = { id: 4, callsign: '1/A/3-7' };
        const units = [
            platoon,
            { id: 10, parent_id: 4, callsign: '1/1/A/3-7' },
            { id: 11, parent_id: 4, callsign: '3/1/A/3-7' },
            // Another platoon's 2nd Section does not take this one's.
            { id: 12, parent_id: 99, callsign: '2/2/A/3-7' },
        ];

        const options = ordinalOptions('section', platoon, units);

        expect(options.map((option) => option.name)).toEqual(['2nd Section', '4th Section', '5th Section', '6th Section', '7th Section', '8th Section', '9th Section']);
        expect(options[0]).toEqual({ value: '2', name: '2nd Section', callsign: '2/1/A/3-7' });
    });

    it('offers company letters A to Z, skipping the ones in use', () => {
        const battalion = { id: 2, callsign: '3-7' };
        const options = ordinalOptions('company', battalion, [{ id: 5, parent_id: 2, callsign: 'A/3-7' }]);

        expect(options[0]).toEqual({ value: 'B', name: 'Bravo Company', callsign: 'B/3-7' });
        expect(options).toHaveLength(25);
    });

    it('offers nothing under a parent with no callsign to build on', () => {
        expect(ordinalOptions('platoon', { id: 3, callsign: null }, [])).toEqual([]);
    });
});
