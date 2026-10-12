import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import SubordinateUnits from '../SubordinateUnits.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const unit = (id, designation, over = {}) => ({
    id,
    designation,
    name: `${designation} name`,
    type: 'platoon',
    status: 'sent',
    holding: false,
    live: false,
    coverage: null,
    href: `/reports/${id}/2026-10-03`,
    kpis: { strength: 10, rc: { numerator: 9, denominator: 10 }, sp: { numerator: 8, denominator: 10 }, atRisk: 0, onLoa: 0, makeups: 0 },
    ...over,
});

const rows = (wrapper) => wrapper.findAll('tbody tr').map((row) => row.find('a').text());
const mountTable = (units) => mount(SubordinateUnits, { props: { units } });

describe('SubordinateUnits', () => {
    it('lists each unit with its figures and links the row to its report', () => {
        const wrapper = mountTable([unit(1, '1/A/1-7', { kpis: { strength: 24, rc: { numerator: 22, denominator: 24 }, sp: { numerator: 0, denominator: 0 }, atRisk: 2, onLoa: 1, makeups: 0 } })]);
        const cells = wrapper.findAll('tbody td').map((cell) => cell.text());

        expect(cells[0]).toContain('1/A/1-7 name');
        expect(cells.slice(1, 6)).toEqual(['24', '92%', '—', '2', '1']);
        expect(wrapper.find('tbody a').attributes('href')).toBe('/reports/1/2026-10-03');
        expect(wrapper.find('[aria-label="Open the 1/A/1-7 report"]').attributes('href')).toBe('/reports/1/2026-10-03');
    });

    it('says holding, draft, not started or sent, with how many children have sent', () => {
        const wrapper = mountTable([
            unit(1, 'P1', { status: 'draft', holding: true, live: true, coverage: { sent: 1, of: 2 } }),
            unit(2, 'P2', { status: 'draft', live: true, type: 'section' }),
            unit(3, 'P3', { status: 'not_started', live: true, type: 'section' }),
            unit(4, 'P4'),
        ]);

        expect(wrapper.findAll('[data-status]').map((badge) => badge.text())).toEqual(['Holding', 'Draft', 'Not started', 'Sent']);
        expect(wrapper.find('tbody tr').text()).toContain('1 of 2 sent');
    });

    it('sorts by a column, flips on a second press, and exposes the order to assistive tech', async () => {
        const wrapper = mountTable([
            unit(1, 'P1', { kpis: { ...unit(1, 'x').kpis, strength: 30 } }),
            unit(2, 'P2', { kpis: { ...unit(2, 'x').kpis, strength: 10 } }),
            unit(3, 'P3', { kpis: { ...unit(3, 'x').kpis, strength: 20 } }),
        ]);

        await wrapper.find('[data-sort="strength"]').trigger('click');
        expect(rows(wrapper)).toEqual(['P2', 'P3', 'P1']);
        expect(wrapper.find('th[aria-sort="ascending"]').text()).toContain('Strength');

        await wrapper.find('[data-sort="strength"]').trigger('click');
        expect(rows(wrapper).filter((name, index) => index < 3)).toEqual(['P1', 'P3', 'P2']);
        expect(wrapper.find('th[aria-sort="descending"]').exists()).toBe(true);
    });

    it('sorts a rate with nothing eligible last in either direction, never as 0%', async () => {
        const none = { numerator: 0, denominator: 0 };
        const wrapper = mountTable([
            unit(1, 'P1', { kpis: { ...unit(1, 'x').kpis, rc: none } }),
            unit(2, 'P2', { kpis: { ...unit(2, 'x').kpis, rc: { numerator: 5, denominator: 10 } } }),
            unit(3, 'P3', { kpis: { ...unit(3, 'x').kpis, rc: { numerator: 9, denominator: 10 } } }),
        ]);

        await wrapper.find('[data-sort="rc"]').trigger('click');
        expect(rows(wrapper)).toEqual(['P2', 'P3', 'P1']);
        await wrapper.find('[data-sort="rc"]').trigger('click');
        expect(rows(wrapper)).toEqual(['P3', 'P2', 'P1']);
    });

    it('calls a lone section the viewer\'s own instead of a subordinate unit', () => {
        const wrapper = mountTable([unit(5, '1/1/A/1-7', { type: 'section' })]);

        expect(wrapper.find('h2').text()).toBe('Your section');
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    });

    it('shows an empty state, not an empty table, when there are no units', () => {
        const wrapper = mountTable([]);

        expect(wrapper.find('[data-testid="no-units"]').exists()).toBe(true);
        expect(wrapper.find('table').exists()).toBe(false);
    });
});
