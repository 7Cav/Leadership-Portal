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
        const wrapper = makeWrapper({ filters: { actor_id: 10, action: 'unit.created', unit_id: 3, target_type: 'Unit', target_id: '7', date_from: '2026-05-01', date_to: '2026-05-31' } });

        await wrapper.find('[data-testid="clear-filters"]').trigger('click');

        expect(getSpy).toHaveBeenCalledWith(
            '/settings/audit-log',
            { actor_id: null, action: null, unit_id: null, target_type: '', target_id: '', date_from: '', date_to: '' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('links an entry to what it changed, and keeps the raw target beside it', () => {
        const wrapper = makeWrapper({
            entries: { ...ENTRIES, data: [{ ...ENTRY, link: { href: '/rollcall/7?week=2026-10-03', label: '2/1/A/1-7 rollcall, week of 03Oct26' } }] },
        });
        const link = wrapper.get('[data-testid="target-link-1"]');

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toBe('/rollcall/7?week=2026-10-03');
        expect(link.text()).toBe('2/1/A/1-7 rollcall, week of 03Oct26');
        expect(wrapper.get('[data-testid="entry-row-1"]').text()).toContain('Unit#7');
    });

    it('shows only the raw target for an entry with nothing to link to', () => {
        const wrapper = makeWrapper({ entries: { ...ENTRIES, data: [{ ...ENTRY, link: null }] } });

        expect(wrapper.find('[data-testid="target-link-1"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="entry-row-1"]').text()).toContain('Unit#7');
    });

    it('filters by a unit chosen from the tree, indented under its parent', async () => {
        const wrapper = makeWrapper({
            unitOptions: [
                { id: 1, label: 'A/1-7 Alpha Company', depth: 0 },
                { id: 2, label: '1/A/1-7 1st Platoon', depth: 1 },
            ],
        });
        const select = wrapper.get('[data-testid="filter-unit_id"]');

        expect(select.findAll('option').map((option) => option.element.textContent)).toEqual(['Any unit', 'A/1-7 Alpha Company', `${String.fromCharCode(160).repeat(3)}1/A/1-7 1st Platoon`]);

        await select.setValue('2');
        await wrapper.find('[data-testid="filter-form"]').trigger('submit');

        expect(getSpy).toHaveBeenLastCalledWith('/settings/audit-log', expect.objectContaining({ unit_id: 2 }), expect.objectContaining({ preserveState: true }));
    });

    it('opens with the unit filter it was given', () => {
        const wrapper = makeWrapper({ filters: { unit_id: 2 }, unitOptions: [{ id: 1, label: 'Alpha', depth: 0 }, { id: 2, label: 'Bravo', depth: 0 }] });

        expect(wrapper.get('[data-testid="filter-unit_id"]').element.value).toBe('2');
    });
});
