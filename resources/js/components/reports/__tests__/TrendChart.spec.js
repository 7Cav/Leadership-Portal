import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TrendChart from '../TrendChart.vue';
import Sparkline from '../../home/Sparkline.vue';

const point = (week, reported, rc, sp = rc, current = false) => ({ week, current, reported, rc, sp });

describe('TrendChart', () => {
    it('says "no data" for a sent week with nothing to divide by, and "no sent report" only without a report', () => {
        const wrapper = mount(TrendChart, {
            props: {
                points: [
                    point('2026-09-26T00:00:00+00:00', false, null),
                    point('2026-10-03T00:00:00+00:00', true, { numerator: 6, denominator: 8 }),
                    point('2026-10-10T00:00:00+00:00', true, { numerator: 0, denominator: 0 }, { numerator: 0, denominator: 0 }, true),
                ],
            },
        });
        const rows = wrapper.findAll('tbody tr').map((row) => row.text());

        expect(rows[0]).toContain('no sent report');
        expect(rows[1]).toContain('75%');
        expect(rows[2]).toContain('no data');
        expect(rows[2]).not.toContain('no sent report');
    });
});

describe('TrendChart ticks', () => {
    it("names each week by its end date on the axis, and gives the whole week in the tooltip and the table", async () => {
        const wrapper = mount(TrendChart, {
            props: {
                points: [
                    point('2026-09-19T00:00:00+00:00', true, { numerator: 6, denominator: 8 }),
                    point('2026-09-26T00:00:00+00:00', true, { numerator: 7, denominator: 8 }),
                    point('2026-11-07T00:00:00+00:00', true, { numerator: 8, denominator: 8 }, undefined, true),
                ],
            },
        });
        const ticks = wrapper.findAll('svg text').map((t) => t.text()).filter((t) => /[A-Z]/.test(t));

        expect(ticks).toEqual(['25SEP', '02OCT', '13NOV']);
        expect(wrapper.findAll('tbody th').map((th) => th.text())[0]).toBe('19SEP26 to 25SEP26');

        await wrapper.get('svg[role="img"]').trigger('pointermove', { clientX: 30 });
        expect(wrapper.get('.pointer-events-none').text()).toContain('19SEP26 to 25SEP26');
    });
});

describe('Home Sparkline', () => {
    it('says "no data" for a reported week with no value', () => {
        const wrapper = mount(Sparkline, {
            props: {
                label: 'RC %',
                format: (v) => `${v}%`,
                series: [
                    { week: 'a', value: null, reported: false, live: false },
                    { week: 'b', value: null, reported: true, live: false },
                    { week: null, value: 80, reported: true, live: true },
                ],
            },
        });

        expect(wrapper.attributes('aria-label')).toBe('RC % over the last 2 weeks: no sent report, no data, 80%');
    });
});
