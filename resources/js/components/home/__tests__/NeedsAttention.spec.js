import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import NeedsAttention from '../NeedsAttention.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const holding = (id, status, unit, holds) => ({
    kind: 'holding', id: `holding-${id}`, unit: { id, designation: unit, name: unit }, status, holds: { id: 99, designation: holds, name: holds }, href: `/reports/${id}/2026-10-03`,
});
const risk = (id, name, missed, section = '1/1/A/1-7') => ({
    kind: 'at_risk', id: `at-risk-${id}`, trooper: { id, displayName: name, rankShort: 'SGT', username: 'x' }, section: section ? { id: 5, designation: section, name: section } : null,
    missed, windowWeeks: 4, href: `/roster/5?trooper=${id}`,
});

const mountList = (items, totals = { holding: items.filter((i) => i.kind === 'holding').length, atRisk: items.filter((i) => i.kind === 'at_risk').length }) =>
    mount(NeedsAttention, { props: { attention: { items, totals } } });

describe('NeedsAttention', () => {
    it('says all clear, with no list, when nothing needs attention', () => {
        const wrapper = mountList([]);

        expect(wrapper.find('[data-testid="all-clear"]').text()).toContain('All clear');
        expect(wrapper.find('ul').exists()).toBe(false);
    });

    it('groups holding reports and at-risk troopers, each row linking to its screen', () => {
        const wrapper = mountList([holding(1, 'draft', '2/1/A/1-7', '1/A/1-7'), holding(2, 'not_started', '1/1/A/1-7', '1/A/1-7'), risk(7, 'SGT Vasquez.R', 3)]);

        expect(wrapper.findAll('[data-group]').map((g) => g.attributes('data-group'))).toEqual(['holding', 'at_risk']);
        expect(wrapper.findAll('a').map((a) => a.attributes('href'))).toEqual(['/reports/1/2026-10-03', '/reports/2/2026-10-03', '/roster/5?trooper=7']);
    });

    it('describes a draft and an unstarted report differently', () => {
        const rows = mountList([holding(1, 'draft', '2/1/A/1-7', '1/A/1-7'), holding(2, 'not_started', '1/1/A/1-7', '1/A/1-7')]).findAll('[data-item="holding"]');

        expect(rows[0].text()).toContain('Draft report, holding up 1/A/1-7');
        expect(rows[0].text()).toContain('Draft');
        expect(rows[1].text()).toContain('Report not started, holding up 1/A/1-7');
        expect(rows[1].text()).toContain('Not started');
    });

    it('names an at-risk trooper by display name with their misses and section', () => {
        const row = mountList([risk(7, 'SGT Vasquez.R', 3)]).find('[data-item="at_risk"]');

        expect(row.text()).toContain('SGT Vasquez.R');
        expect(row.text()).toContain('Missed 3 of the last 4 weeks in 1/1/A/1-7');
        expect(row.text()).toContain('3 missed');
    });

    it('omits the section when the trooper has none rather than printing a blank', () => {
        expect(mountList([risk(7, 'SGT Vasquez.R', 2, null)]).text()).toContain('Missed 2 of the last 4 weeks');
        expect(mountList([risk(7, 'SGT Vasquez.R', 2, null)]).text()).not.toContain(' in ');
    });

    it('counts what the cap left out and points at the full list', () => {
        const wrapper = mountList([risk(1, 'A', 2)], { holding: 0, atRisk: 12 });

        expect(wrapper.find('[data-group="at_risk"]').text()).toContain('11 more');
        expect(wrapper.find('[data-group="at_risk"] p a').attributes('href')).toBe('/roster');
    });
});
