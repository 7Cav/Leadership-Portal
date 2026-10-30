import { describe, expect, it } from 'vitest';
import { pickerCollapses, pickerGroups, presenceText } from '@/lib/staffReport';

const section = (id, label, platoon, group = null, own = false) => ({ id, label, platoon, group, own });

describe('pickerGroups', () => {
    it('keeps the server order and groups each unit block by platoon', () => {
        const groups = pickerGroups([
            section(1, '1/1/A/1-7', '1/A/1-7', '1/A/1-7', true),
            section(2, '2/1/A/1-7', '1/A/1-7', '1/A/1-7', true),
            section(3, '1/2/A/1-7', '2/A/1-7', 'A/1-7'),
            section(4, '1/1/B/1-7', '1/B/1-7'),
            section(5, '2/1/B/1-7', '1/B/1-7'),
            section(6, '1/2/B/1-7', '2/B/1-7'),
        ]);

        expect(groups.map((group) => group.label)).toEqual(['In 1/A/1-7', 'In A/1-7', 'Other sections']);
        expect(groups[2].platoons.map((platoon) => [platoon.label, platoon.rows.map((row) => row.label)])).toEqual([
            ['1/B/1-7', ['1/1/B/1-7', '2/1/B/1-7']],
            ['2/B/1-7', ['1/2/B/1-7']],
        ]);
        expect(groups[0].platoons[0].own).toBe(true);
        expect(groups[2].platoons[0].own).toBe(false);
    });

    it('collapses only when the list spans more than four platoons', () => {
        const few = pickerGroups(['1/A', '2/A', '3/A', '4/A'].map((platoon, i) => section(i, `1/${platoon}`, platoon)));
        const many = pickerGroups(['1/A', '2/A', '3/A', '1/B', '2/B'].map((platoon, i) => section(i, `1/${platoon}`, platoon)));

        expect(pickerCollapses(few)).toBe(false);
        expect(pickerCollapses(many)).toBe(true);
    });
});

describe('presenceText', () => {
    it('names the system for a conflict and for no data, and says nothing when it agreed', () => {
        expect(presenceText({ check: 'conflict', source: 'CRCON' })).toBe('Self-report conflicts with CRCON data');
        expect(presenceText({ check: 'no_data', source: 'CRCON' })).toBe('No CRCON data');
        expect(presenceText({ check: 'confirmed', source: 'CRCON' })).toBe('');
        expect(presenceText(null)).toBe('');
    });
});
