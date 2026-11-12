import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import WeekCell from '../WeekCell.vue';

enableAutoUnmount(afterEach);

describe('WeekCell', () => {
    it('dims a pending SP mark and says when it counts (#634)', () => {
        const half = mount(WeekCell, { props: { rc: 'ok', sp: 'ok', pending: 'Counts once the practice ends at 2100Z' } }).findAll('.roster-h')[1];

        expect(half.classes()).toEqual(expect.arrayContaining(['roster-h--ok', 'roster-h--waiting']));
        expect(half.attributes('title')).toBe('Counts once the practice ends at 2100Z');
    });

    it('renders a counted mark plainly', () => {
        const half = mount(WeekCell, { props: { rc: 'ok', sp: 'ok' } }).findAll('.roster-h')[1];

        expect(half.classes()).not.toContain('roster-h--waiting');
        expect(half.attributes('title')).toBeUndefined();
    });
});

describe('WeekCell for a line HQ staff week', () => {
    it('draws one block the height of both halves, its staff report state', () => {
        const cell = mount(WeekCell, { props: { rc: 'staff-short', sp: 'staff-short', staff: { state: 'short' } } });

        expect(cell.findAll('.roster-h')).toHaveLength(1);
        expect(cell.find('.roster-h').classes()).toEqual(expect.arrayContaining(['roster-h--whole', 'roster-h--staff-short']));
    });

    it('keeps the two LOA halves for a staff week on leave', () => {
        const cell = mount(WeekCell, { props: { rc: 'loa', sp: 'loa', staff: { state: 'loa' } } });

        expect(cell.findAll('.roster-h')).toHaveLength(2);
    });
});
