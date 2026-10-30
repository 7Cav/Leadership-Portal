import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StrengthView from '../StrengthView.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// The roster's Strength tab: the unit's chart, its children as small multiples, and for a battalion each company's
// platoons as compact sparkline rows under it, never a full chart per platoon.

const pts = (...values) => values.map((headcount, i) => ({ week: `2026-10-${String(10 + i * 7).padStart(2, '0')}`, headcount }));

const trends = {
    range: '26',
    weeks: pts(40, 42, 41).map((p) => ({ ...p, strength: p.headcount - 5, in: null, out: null, source: 'history' })),
    change: { headcount: 1, strength: 1 },
    children: [
        {
            id: 10,
            designation: 'A/1-7',
            points: pts(20, 22, 21),
            children: [
                { id: 11, designation: '1/A/1-7', points: pts(10, null, 12) },
                { id: 12, designation: '2/A/1-7', points: pts(10, 11, 9) },
            ],
        },
        { id: 20, designation: 'B/1-7', points: pts(20, 20, 20), children: [] },
    ],
};

const mountView = (props = {}) => mount(StrengthView, { props: { unit: { id: 1, name: '1st Battalion', callsign: '1-7' }, trends, ...props } });

describe('StrengthView', () => {
    it('draws one chart per company and a compact row per platoon under it', () => {
        const wrapper = mountView();
        const alpha = wrapper.find('[data-unit="10"]');

        expect(wrapper.findAll('[data-testid="strength-children"] [data-testid="strength-svg"]')).toHaveLength(2);
        expect(alpha.findAll('[data-subunit]')).toHaveLength(2);
        // The platoon rows are sparklines, not charts with axes or tables.
        expect(alpha.find('[data-testid="strength-subunits"] [data-testid="strength-svg"]').exists()).toBe(false);
        expect(alpha.findAll('[data-testid="strength-spark"]')).toHaveLength(2);
        expect(alpha.find('[data-subunit="12"]').text()).toContain('2/A/1-7');
        expect(alpha.find('[data-subunit="12"]').text()).toContain('-1');
        expect(alpha.find('[data-subunit="11"] a').attributes('href')).toBe('/roster/11?view=strength&range=26');
        // An unknown week breaks the sparkline instead of dropping to zero.
        expect(alpha.find('[data-subunit="11"] [data-testid="strength-spark"]').attributes('d').match(/M/g)).toHaveLength(2);
        expect(wrapper.find('[data-unit="20"] [data-testid="strength-subunits"]').exists()).toBe(false);
    });

    it('marks the current range and asks for another', async () => {
        const wrapper = mountView();

        expect(wrapper.find('[data-range="26"]').attributes('aria-pressed')).toBe('true');
        await wrapper.find('[data-range="all"]').trigger('click');

        expect(wrapper.emitted('range')).toEqual([['all']]);
    });

    it('shows a loading note until the numbers arrive', () => {
        expect(mountView({ trends: null }).find('[data-testid="strength-loading"]').exists()).toBe(true);
    });
});
