import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffHqHealth from '../StaffHqHealth.vue';

// The HQ unit health card draws what the server sent. These cover its layout and the weeks line.

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const weeks = ['2026-09-19', '2026-09-26', '2026-10-03', '2026-10-10', '2026-10-17', '2026-10-24', '2026-10-31', '2026-11-07'];

const health = (over = {}) => ({
    unit: { id: 3, label: '1-7' },
    week: '2026-11-07',
    sp: 0.6,
    rc: 0.8,
    headcount: 17,
    inRollcall: 10,
    atRisk: 2,
    staff: { met: 3, total: 5 },
    trend: weeks.map((week) => ({ week, sp: 0.6, rc: 0.8, headcount: 17, inRollcall: 10, atRisk: 2 })),
    ...over,
});

describe('StaffHqHealth', () => {
    it('names the weeks it covers plainly, first day to last', () => {
        const card = mount(StaffHqHealth, { props: { health: health() } });

        expect(card.find('[data-testid="staff-hq-health"]').text()).toContain('Last 8 weeks, 19SEP26 to 13NOV26');
        expect(card.text()).not.toContain('weeks from the week');
    });

    it('says nothing about the weeks when there is no trend', () => {
        const card = mount(StaffHqHealth, { props: { health: health({ trend: [] }) } });

        expect(card.text()).not.toContain('Last');
    });

    it('keeps each value on one line, over its sparkline', () => {
        const card = mount(StaffHqHealth, { props: { health: health() } });
        const strength = card.find('[data-testid="health-strength"]');
        const value = strength.find('dd > span');

        expect(value.text()).toBe('10 of 17');
        expect(value.classes()).toContain('whitespace-nowrap');
        expect(strength.find('dd').classes()).toContain('flex-col');
        expect(card.find('[data-testid="health-staff"] dd').classes()).toContain('whitespace-nowrap');
    });

    it('sets its columns by the card width, so a narrow card stacks two to a row', () => {
        const card = mount(StaffHqHealth, { props: { health: health() } });

        expect(card.find('[data-testid="staff-hq-health"]').classes()).toContain('@container');
        expect(card.find('dl').classes()).toEqual(expect.arrayContaining(['grid-cols-2', '@2xl:grid-cols-5']));
        expect(card.find('dl').classes().some((c) => c.startsWith('sm:'))).toBe(false);
    });
});
