import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AuditLog from '../AuditLog.vue';

const getSpy = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { get: (...args) => getSpy(...args) },
}));

const ENTRY = {
    id: 1,
    actor: 'jsmith',
    action: 'unit.created',
    target_type: 'Unit',
    target_id: '7',
    reason: 'Reorganizing the chain of command',
    content_digest: 'abc123',
    created_at: '2026-05-30T14:22:00Z',
};

const ENTRIES = {
    data: [ENTRY],
    total: 1,
    current_page: 1,
    last_page: 1,
    prev_page_url: null,
    next_page_url: null,
};

const ACTOR_OPTIONS = [{ id: 10, username: 'jsmith' }];
const ACTION_OPTIONS = ['unit.created'];

function makeWrapper(overrides = {}) {
    return mount(AuditLog, {
        props: {
            entries: ENTRIES,
            filters: {},
            actorOptions: ACTOR_OPTIONS,
            actionOptions: ACTION_OPTIONS,
            ...overrides,
        },
    });
}

describe('Settings/AuditLog', () => {
    it('keeps the column headers in view while the log scrolls', () => {
        expect(makeWrapper().find('[data-slot="table-container"]').attributes('data-sticky-head')).toBeDefined();
    });

    it('renders every entry with a forum-convention timestamp', () => {
        const wrapper = makeWrapper();

        expect(wrapper.text()).toContain('jsmith');
        expect(wrapper.text()).toContain('unit.created');
        expect(wrapper.text()).toContain('Unit#7');
        expect(wrapper.text()).toContain('Reorganizing the chain of command');
        expect(wrapper.text()).toContain('abc123');
        expect(wrapper.text()).toContain('30May26');
    });

    it('shows an empty state when there are no entries', () => {
        const wrapper = makeWrapper({ entries: { ...ENTRIES, data: [] } });

        expect(wrapper.text()).toContain('No audit entries match these filters.');
    });

    it('says no reason was given for a null reason, rather than leaving the cell blank', () => {
        const wrapper = makeWrapper({ entries: { ...ENTRIES, data: [{ ...ENTRY, reason: null }] } });

        expect(wrapper.find('[data-testid="entry-row-1"]').text()).toContain('None given');
    });

    it('submits the filter form as a GET to the audit-log route', async () => {
        const wrapper = makeWrapper();

        await wrapper.find('[data-testid="filter-form"]').trigger('submit');

        expect(getSpy).toHaveBeenCalledWith('/settings/audit-log', expect.any(Object), expect.objectContaining({ preserveState: true }));
    });

    it('disables Previous on the first page and Next on the last page', () => {
        const wrapper = makeWrapper();

        expect(wrapper.find('[data-testid="prev-page"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="next-page"]').attributes('disabled')).toBeDefined();
    });

    it('requests the next page URL when Next is clicked', async () => {
        const wrapper = makeWrapper({ entries: { ...ENTRIES, next_page_url: '/settings/audit-log?page=2' } });

        await wrapper.find('[data-testid="next-page"]').trigger('click');

        expect(getSpy).toHaveBeenCalledWith('/settings/audit-log?page=2', {}, expect.objectContaining({ preserveState: true }));
    });

    it('resets every filter field and re-applies when Clear is clicked', async () => {
        const wrapper = makeWrapper({ filters: { actor_id: 10, action: 'unit.created', target_type: 'Unit', target_id: '7', date_from: '2026-05-01', date_to: '2026-05-31' } });

        await wrapper.find('[data-testid="clear-filters"]').trigger('click');

        expect(getSpy).toHaveBeenCalledWith(
            '/settings/audit-log',
            { actor_id: null, action: null, target_type: '', target_id: '', date_from: '', date_to: '' },
            expect.objectContaining({ preserveState: true }),
        );
    });
});
