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
    it('draws two halves, like a roll call and an SP: the report sent on top, the rule met below', () => {
        const cell = mount(WeekCell, { props: { rc: 'staff-sent', sp: 'staff-short', staff: { state: 'short' } } });

        expect(cell.findAll('.roster-h')).toHaveLength(2);
        expect(cell.find('[data-testid="staff-sent"]').classes()).toContain('roster-h--staff-sent');
        expect(cell.find('[data-testid="staff-accountability"]').classes()).toContain('roster-h--staff-short');
    });

    it('marks the halves as staff only for a staff week', () => {
        const cell = mount(WeekCell, { props: { rc: 'ok', sp: 'ok' } });

        expect(cell.find('[data-testid="staff-sent"]').exists()).toBe(false);
    });
});
