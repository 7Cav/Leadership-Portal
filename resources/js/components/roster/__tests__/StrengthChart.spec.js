import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import StrengthChart from '../StrengthChart.vue';

// The strength trends chart: two lines with a gap wherever a week is not known, and a table with the same numbers.

const week = (date, headcount, strength, source, extra = {}) => ({ week: date, headcount, strength, in: null, out: null, source, ...extra });

const points = [
    week('2026-10-10', null, null, null),
    week('2026-10-17', 12, 9, 'report'),
    week('2026-10-24', 13, 10, 'history', { in: 2, out: 1 }),
    week('2026-10-31', null, null, null),
    week('2026-11-07', 11, 8, 'live', { in: 0, out: 2 }),
];

describe('StrengthChart', () => {
    it('draws both series and breaks the line at an unknown week', () => {
        const wrapper = mount(StrengthChart, { props: { points } });

        const d = wrapper.find('[data-testid="strength-line-headcount"]').attributes('d');
        // Two runs: one move-to per unbroken stretch, never a drop to zero.
        expect(d.match(/M/g)).toHaveLength(2);
        expect(wrapper.find('[data-testid="strength-line-strength"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="strength-legend"]').text()).toContain('In roll call');
    });

    it('lists every week in the table, unknown weeks as not known', () => {
        const wrapper = mount(StrengthChart, { props: { points } });
        const row = (date) => wrapper.find(`[data-testid="strength-table"] tr[data-week="${date}"]`).text();

        expect(wrapper.findAll('[data-testid="strength-table"] tbody tr')).toHaveLength(5);
        expect(row('2026-10-24')).toContain('13');
        expect(row('2026-10-24')).toContain('Placement history');
        expect(row('2026-10-17')).toContain('Sent report');
        expect(row('2026-10-31')).toContain('Not known');
        expect(row('2026-10-31')).not.toContain('0');
    });

    it('says so when nothing is known', () => {
        const wrapper = mount(StrengthChart, { props: { points: [week('2026-10-10', null, null, null)] } });

        expect(wrapper.find('[data-testid="strength-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="strength-svg"]').exists()).toBe(false);
    });

    it('draws a small multiple without a table', () => {
        const wrapper = mount(StrengthChart, { props: { points, series: ['headcount'], compact: true, height: 96 } });

        expect(wrapper.find('[data-testid="strength-table"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="strength-line-strength"]').exists()).toBe(false);
    });
});
