import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Rollcall from '../Rollcall.vue';

const page = { url: '/rollcall/12', props: { auth: { user: { username: 'Vasquez.R', is_admin: false } }, viewAs: null } };
const push = vi.fn((visit) => (page.url = visit.url));
const visit = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { push: (...args) => push(...args), visit: (...args) => visit(...args) },
    usePage: () => page,
}));

const WEEK = {
    start: '2026-10-03T00:00:00+00:00',
    date: '2026-10-03',
    closes: '2026-10-09T23:59:00+00:00',
    isCurrent: true,
    previous: '2026-09-26',
    next: null,
    current: '2026-10-03',
};
const SECTION = { id: 12, designation: '2/1/A/1-7', name: '2nd Section', platoon: '1/A/1-7' };
const SECTIONS = [SECTION, { id: 13, designation: '3/1/A/1-7', name: '3rd Section', platoon: '1/A/1-7' }];

const trooper = (id, rollcall, status, extra = {}) => ({
    id, cavId: 1000 + id, rank: 'PFC', name: `Trooper${id}.A`, rollcall, status, source: status ? 'manual' : null, priorStatus: null, ...extra,
});

function factory(props = {}) {
    return mount(Rollcall, {
        props: {
            section: SECTION,
            sections: SECTIONS,
            week: WEEK,
            troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', null, { priorStatus: 'missed' }), trooper(3, 'unsigned', null)],
            priorWeekMarked: true,
            atRisk: [],
            roster: { source: 'milpac', syncedAt: '2026-10-08T09:14:00+00:00' },
            practice: { lastMarkedAt: null },
            ...props,
        },
        global: { stubs: { Dialog: true, DialogContent: true, SectionPicker: { props: ['sections'], template: '<div data-picker>{{ sections.length }}</div>' } } },
    });
}

describe('Rollcall page', () => {
    beforeEach(() => {
        page.url = '/rollcall/12';
        push.mockClear();
    });

    it('titles the week in forum date convention and shows where the roster came from (AD-20)', () => {
        const wrapper = factory();

        expect(wrapper.find('h1').text()).toBe('Rollcall, week of 03Oct26');
        expect(wrapper.find('[data-testid="roster-provenance"]').text()).toBe('Roster from milpac, synced 08Oct26 09:14Z');
        expect(wrapper.text()).toContain('Signatures close Fri 09Oct26 23:59Z');
    });

    it('says the roster has never synced rather than inventing a time', () => {
        expect(factory({ roster: { source: 'milpac', syncedAt: null } }).find('[data-testid="roster-provenance"]').text()).toBe('Roster from milpac, not synced yet');
    });

    it('opens on step 1 with the unsigned listed first', () => {
        const wrapper = factory();
        const groups = wrapper.findAll('[role="group"][aria-label]').filter((g) => !g.attributes('aria-label').includes(' for '));

        expect(wrapper.find('h2').text()).toBe('Signatures');
        expect(groups.map((g) => g.attributes('aria-label'))).toEqual(['Unsigned', 'Cannot attend', 'Will attend']);
    });

    it('deep-links a step through the URL', async () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory();

        expect(wrapper.find('h2').text()).toBe('Section practice');
        expect(wrapper.find('[data-step="2"]').attributes('aria-current')).toBe('step');

        await wrapper.find('[data-step="3"]').trigger('click');
        expect(push).toHaveBeenCalledWith(expect.objectContaining({ url: '/rollcall/12?step=3', preserveState: true }));
    });

    it('lists "said they cannot attend" first on the practice step, and shows last week\'s mark as the pre-fill', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory();

        expect(wrapper.find('[data-testid="prefill-banner"]').text()).toContain('Pre-filled from last week');
        const first = wrapper.find('[role="group"][aria-label="Said they cannot attend"]');
        expect(first.text()).toContain('Trooper2.A');
        expect(first.find('[data-testid="prior-mark"]').text()).toBe('Last week: Missed');
    });

    it('says nothing was pre-filled when last week has no marks', () => {
        page.url = '/rollcall/12?step=2';

        expect(factory({ priorWeekMarked: false }).find('[data-testid="prefill-banner"]').text()).toBe(
            'Nothing pre-filled: no practice marks were recorded last week.',
        );
    });

    it('locks an LOA row: no status buttons, an LOA lock instead', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory({ troopers: [trooper(4, 'unsigned', 'loa')] });

        const card = wrapper.find('[data-trooper="4"]');
        expect(card.text()).toContain('LOA, nothing to mark');
        expect(card.findAll('button[data-value]')).toHaveLength(0);
    });

    it('renders an empty roster as an empty state, not an error', () => {
        expect(factory({ troopers: [] }).text()).toContain('No troopers placed in this section');
    });

    it('flags a past week', () => {
        const wrapper = factory({ week: { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', next: '2026-10-03' } });

        expect(wrapper.find('[data-testid="past-week-notice"]').exists()).toBe(true);
    });

    it('reviews totals and at-risk troopers on step 3', () => {
        page.url = '/rollcall/12?step=3';
        const wrapper = factory({ atRisk: [{ trooperId: 2, missed: 2, windowWeeks: 4 }] });

        expect(wrapper.find('[data-testid="review-blockers"]').text()).toContain('2 not marked for section practice: Trooper2.A, Trooper3.A');
        expect(wrapper.find('[data-testid="at-risk"]').text()).toContain('PFC Trooper2.A has missed 2 of the last 4 weeks');
    });

    it('shows the section picker when no section is chosen', () => {
        const wrapper = factory({ section: null, troopers: [] });

        expect(wrapper.find('h1').text()).toBe('Choose a section');
        expect(wrapper.find('[data-picker]').text()).toBe('2');
    });

    it('explains an empty scope instead of an empty picker', () => {
        expect(factory({ section: null, sections: [], troopers: [] }).text()).toContain('No sections in your scope');
    });
});
