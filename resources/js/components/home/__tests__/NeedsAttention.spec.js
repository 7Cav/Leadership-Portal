import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import NeedsAttention from '../NeedsAttention.vue';

const mockPage = { props: { forum: { baseUrl: 'https://7cav.us' } } };

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => mockPage,
}));

const holding = (id, status, unit, holds) => ({
    kind: 'holding', id: `holding-${id}`, unit: { id, designation: unit, name: unit }, status, holds: { id: 99, designation: holds, name: holds }, href: `/reports/${id}/2026-10-03`,
});
const risk = (id, name, missed, section = '1/1/A/1-7', extra = {}) => ({
    kind: 'at_risk', id: `at-risk-${id}`, trooper: { id, displayName: name, rankShort: 'SGT', username: 'x' }, section: section ? { id: 5, designation: section, name: section } : null,
    missed, windowWeeks: 4, href: `/roster/5?trooper=${id}`, ...extra,
});

const review = (id, name, extra = {}) => ({
    kind: 'loa_review', id: `loa-review-${id}`, trooper: { id, displayName: name, rankShort: 'PFC', username: name }, section: { id: 5, designation: '1/1/A/1-7', name: '1st Section' },
    typeLabel: 'LOA', reviewReason: 'The End Date could not be read.', sourceThread: 'https://7cav.us/threads/loa.9/', href: `/rollcall/5?week=2026-10-03&focus=${id}`, ...extra,
});

const mountList = (
    items,
    totals = {
        holding: items.filter((i) => i.kind === 'holding').length,
        atRisk: items.filter((i) => i.kind === 'at_risk').length,
        loaReview: items.filter((i) => i.kind === 'loa_review').length,
    },
) =>
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

    it('sends every item type to the screen that resolves it and says where in the title', () => {
        const rows = mountList([
            holding(1, 'draft', '2/1/A/1-7', '1/A/1-7'),
            holding(2, 'not_started', '1/1/A/1-7', '1/A/1-7'),
            { ...holding(3, 'not_started', '1/A/1-7', 'A/1-7'), href: '/reports/3/2026-10-03' },
            risk(7, 'SGT Vasquez.R', 3),
        ]).findAll('[data-item]');

        expect(rows.map((row) => [row.attributes('data-item'), row.attributes('href'), row.attributes('title')])).toEqual([
            ['holding', '/reports/1/2026-10-03', 'Open the 2/1/A/1-7 report for this week'],
            ['holding', '/reports/2/2026-10-03', 'Open the 1/1/A/1-7 report for this week'],
            ['holding', '/reports/3/2026-10-03', 'Open the 1/A/1-7 report for this week'],
            ['at_risk', '/roster/5?trooper=7', 'Open SGT Vasquez.R on the roster'],
        ]);
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

    describe('makeup PM', () => {
        const missedWeeks = ['2026-09-26', '2026-10-03'];

        it('offers a makeup PM beside an at-risk row: the forum\'s own conversation form, pre-filled, in a new tab', () => {
            const wrapper = mountList([risk(7, 'SGT Vasquez.R', 2, '1/1/A/1-7', { trooper: { id: 7, displayName: 'SGT Vasquez.R', rankShort: 'SGT', username: 'Vasquez.R' }, missedWeeks })]);
            const link = wrapper.get('[data-testid="makeup-pm"]');

            expect(link.attributes('href')).toBe('https://7cav.us/conversations/add?to=Vasquez.R&title=Section%20practice%20makeup%3A%20weeks%20of%2026Sep26%2C%2003Oct26');
            expect(link.attributes('target')).toBe('_blank');
            expect(link.attributes('rel')).toBe('noopener noreferrer');
            expect(link.text()).toContain('Send makeup PM to SGT Vasquez.R');
            // A sibling of the row's link, never nested inside it.
            expect(wrapper.get('[data-item="at_risk"]').find('[data-testid="makeup-pm"]').exists()).toBe(false);
        });

        it('is not offered for a trooper with no forum username', () => {
            const wrapper = mountList([risk(7, 'SGT #4012', 2, '1/1/A/1-7', { trooper: { id: 7, displayName: 'SGT #4012', rankShort: 'SGT', username: null }, missedWeeks })]);

            expect(wrapper.find('[data-testid="makeup-pm"]').exists()).toBe(false);
            expect(wrapper.find('[data-item="at_risk"]').exists()).toBe(true);
        });

        it('is not offered on a report that is holding up the chain', () => {
            const wrapper = mountList([holding(1, 'draft', '2/1/A/1-7', '1/A/1-7')]);

            expect(wrapper.find('[data-testid="makeup-pm"]').exists()).toBe(false);
        });
    });

    describe('LOA posts that need review', () => {
        it('lists them in their own group, after at-risk, each opening the trooper\'s card on the rollcall', () => {
            const wrapper = mountList([risk(7, 'SGT Vasquez.R', 3), review(8, 'Lindqvist.E')]);
            const row = wrapper.get('[data-item="loa_review"]');

            expect(wrapper.findAll('[data-group]').map((g) => g.attributes('data-group'))).toEqual(['at_risk', 'loa_review']);
            expect(wrapper.get('[data-group="loa_review"] h3').text()).toContain('LOA posts that need review');
            expect(row.attributes('href')).toBe('/rollcall/5?week=2026-10-03&focus=8');
            expect(row.attributes('title')).toBe('Open Lindqvist.E on the 1/1/A/1-7 roll call');
            expect(row.text()).toContain('LOA post the sync could not read in 1/1/A/1-7');
            expect(row.get('[data-testid="loa-review-reason"]').text()).toBe('The End Date could not be read.');
            expect(row.text()).toContain('Needs review');
        });

        it('offers the forum post beside the row, in a new tab, and never a makeup PM', () => {
            const wrapper = mountList([review(8, 'Lindqvist.E')]);
            const link = wrapper.get('[data-testid="loa-thread"]');

            expect(link.attributes('href')).toBe('https://7cav.us/threads/loa.9/');
            expect(link.attributes('target')).toBe('_blank');
            expect(link.text()).toContain('View the LOA post of Lindqvist.E (opens the forum in a new tab)');
            expect(wrapper.find('[data-testid="makeup-pm"]').exists()).toBe(false);
        });

        it('draws no thread link when the post has none, and no reason line without a reason', () => {
            const wrapper = mountList([review(8, 'Lindqvist.E', { sourceThread: null, reviewReason: null })]);

            expect(wrapper.find('[data-testid="loa-thread"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="loa-review-reason"]').exists()).toBe(false);
        });

        it('counts the posts the cap left out', () => {
            const wrapper = mountList([review(8, 'Lindqvist.E')], { holding: 0, atRisk: 0, loaReview: 13 });

            expect(wrapper.get('[data-group="loa_review"]').text()).toContain('12 more');
        });
    });
});

// 2026-10-30: a trooper who asked for a make-up on the roll call stays on the list until one is recorded.
describe('NeedsAttention, owed make-ups', () => {
    const owed = (id, name, weeks) => ({
        kind: 'makeup_owed', id: `makeup-owed-${id}`, trooper: { id, displayName: name, rankShort: 'PFC', username: name },
        section: { id: 5, designation: '1/1/A/1-7', name: '1st Section' }, weeks, href: `/rollcall/5?week=${weeks.at(-1)}&focus=${id}`,
    });

    it('lists each trooper who owes a make-up with the weeks, linking to their card on the roll call', () => {
        const wrapper = mount(NeedsAttention, {
            props: { attention: { items: [owed(9, 'PFC Adams.P', ['2026-09-26', '2026-10-03'])], totals: { holding: 0, atRisk: 0, loaReview: 0, makeupOwed: 1 } } },
        });
        const row = wrapper.get('[data-item="makeup_owed"]');

        expect(wrapper.findAll('[data-group]').map((g) => g.attributes('data-group'))).toEqual(['makeup_owed']);
        expect(wrapper.get('[data-group="makeup_owed"] h3').text()).toContain('Owe a make-up');
        expect(row.attributes('href')).toBe('/rollcall/5?week=2026-10-03&focus=9');
        expect(row.attributes('title')).toBe('Open PFC Adams.P on the 1/1/A/1-7 roll call');
        expect(row.text()).toContain('Make-up requested for 26Sep26, 03Oct26 in 1/1/A/1-7');
        expect(row.text()).toContain('Owes a make-up');
    });
});
