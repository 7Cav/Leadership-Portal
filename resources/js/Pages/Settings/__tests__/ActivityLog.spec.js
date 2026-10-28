import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ActivityLog from '../ActivityLog.vue';

const getSpy = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { get: (...args) => getSpy(...args) },
}));

const VISIT = {
    id: 1,
    user: 'Hale.S',
    url: '/roster?unit=4',
    actingAs: null,
    viewingAs: null,
    visited_at: '2026-10-28T14:05:09+00:00',
};

const VISITS = {
    data: [VISIT],
    total: 1,
    current_page: 1,
    last_page: 1,
    prev_page_url: null,
    next_page_url: null,
};

function makeWrapper(overrides = {}) {
    return mount(ActivityLog, {
        props: {
            visits: VISITS,
            filters: {},
            userOptions: [{ id: 10, username: 'Hale.S' }],
            retentionDays: 30,
            ...overrides,
        },
    });
}

describe('Settings/ActivityLog', () => {
    beforeEach(() => getSpy.mockClear());

    it('lists each visit: the user, the address and a forum-convention time', () => {
        const row = makeWrapper().get('[data-testid="visit-row-1"]');

        expect(row.text()).toContain('Hale.S');
        expect(row.get('[data-testid="visit-url"]').text()).toBe('/roster?unit=4');
        expect(row.text()).toContain('28Oct26');
    });

    it('renders the address as text, never as markup', () => {
        const wrapper = makeWrapper({ visits: { ...VISITS, data: [{ ...VISIT, url: '/roster?q=<b>x</b>' }] } });

        expect(wrapper.get('[data-testid="visit-url"]').text()).toBe('/roster?q=<b>x</b>');
        expect(wrapper.find('[data-testid="visit-url"] b').exists()).toBe(false);
    });

    it('says who an Admin was acting as or viewing as on the visit', () => {
        const wrapper = makeWrapper({
            visits: { ...VISITS, data: [{ ...VISIT, actingAs: 'SGT Vasquez.R' }, { ...VISIT, id: 2, viewingAs: '1/A/1-7' }] },
        });

        expect(wrapper.get('[data-testid="visit-row-1"] [data-testid="acting-as"]').text()).toBe('acting as SGT Vasquez.R');
        expect(wrapper.find('[data-testid="visit-row-1"] [data-testid="viewing-as"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="visit-row-2"] [data-testid="viewing-as"]').text()).toBe('viewing as 1/A/1-7');
    });

    it('states how long visits are kept', () => {
        expect(makeWrapper().get('[data-testid="retention"]').text()).toContain('Kept 30 days');
    });

    it('shows an empty state when no visit matches', () => {
        expect(makeWrapper({ visits: { ...VISITS, data: [], total: 0 } }).text()).toContain('No page visits match these filters.');
    });

    it('filters by user and date range with a GET to the activity-log route', async () => {
        const wrapper = makeWrapper();

        await wrapper.get('[data-testid="filter-user_id"]').setValue('10');
        await wrapper.get('[data-testid="filter-date_from"]').setValue('2026-10-01');
        await wrapper.get('[data-testid="filter-date_to"]').setValue('2026-10-28');
        await wrapper.get('[data-testid="filter-form"]').trigger('submit');

        expect(getSpy).toHaveBeenLastCalledWith(
            '/settings/activity-log',
            { user_id: 10, date_from: '2026-10-01', date_to: '2026-10-28' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('opens with the filters it was given, and Clear resets them all', async () => {
        const wrapper = makeWrapper({ filters: { user_id: 10, date_from: '2026-10-01', date_to: '2026-10-28' } });

        expect(wrapper.get('[data-testid="filter-user_id"]').element.value).toBe('10');

        await wrapper.get('[data-testid="clear-filters"]').trigger('click');

        expect(getSpy).toHaveBeenLastCalledWith('/settings/activity-log', { user_id: null, date_from: '', date_to: '' }, expect.objectContaining({ preserveState: true }));
    });

    it('pages with the server-built links and disables them at the ends', async () => {
        const first = makeWrapper();
        expect(first.get('[data-testid="prev-page"]').attributes('disabled')).toBeDefined();
        expect(first.get('[data-testid="next-page"]').attributes('disabled')).toBeDefined();

        const wrapper = makeWrapper({ visits: { ...VISITS, next_page_url: '/settings/activity-log?user_id=10&page=2' } });
        await wrapper.get('[data-testid="next-page"]').trigger('click');

        expect(getSpy).toHaveBeenCalledWith('/settings/activity-log?user_id=10&page=2', {}, expect.objectContaining({ preserveState: true }));
    });
});
