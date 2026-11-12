import { describe, it, expect, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// Deactivate and reactivate on Org structure: inactive units are hidden until Show inactive, greyed when shown, and the
// people still placed in one are listed for an Admin to move.
let patchSpy = vi.fn();

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: vi.fn(), post: vi.fn(), patch: (...args) => patchSpy(...args) },
    useForm: (initial) =>
        'type' in initial
            ? { ...initial, processing: false, errors: {}, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }
            : { ...initial, processing: false, errors: {}, patch: vi.fn(), clearErrors: vi.fn() },
}));

const UNITS = [
    { id: 1, parent_id: null, type: 'regiment', kind: 'line', name: '7th Cavalry Regiment', callsign: '7CAV', path: '1/', trooper_count: 3, placed_count: 5 },
    { id: 2, parent_id: 1, type: 'group', kind: 'staff', name: 'Chief of Staff', callsign: 'COS', path: '1/2/', trooper_count: 1, placed_count: 3 },
    {
        id: 3,
        parent_id: 2,
        type: 'group',
        kind: 'staff',
        name: 'Recruitment Oversight Office',
        callsign: 'ROO',
        path: '1/2/3/',
        trooper_count: 0,
        placed_count: 2,
        inactive: true,
        deactivated_at: '2026-11-12T10:00:00+00:00',
    },
    { id: 4, parent_id: 3, type: 'group', kind: 'staff', name: 'ROO Desk', callsign: 'ROOD', path: '1/2/3/4/', trooper_count: 0, placed_count: 1, inactive: true },
    { id: 5, parent_id: 2, type: 'group', kind: 'staff', name: 'S1', callsign: 'S1', path: '1/2/5/', trooper_count: 1, placed_count: 1 },
];

const PLACED = [
    { id: 70, displayName: 'SGT Hale.R', billet: 'ROO Officer', unitId: 3 },
    { id: 71, displayName: 'CPL Ives.D', billet: null, unitId: 4 },
];

const inDialog = (testid) => document.body.querySelector(`[data-testid="${testid}"]`);
const mountPage = (props = {}) =>
    mount(OrgStructure, { props: { units: UNITS, isAdmin: true, inactivePlaced: PLACED, ...props }, attachTo: document.body });

describe('Settings/OrgStructure, inactive units', () => {
    beforeEach(() => {
        patchSpy = vi.fn();
        document.body.innerHTML = '';
    });

    it('hides inactive units until Show inactive, then lists them greyed with the deactivated one tagged', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="unit-row-3"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="unit-row-4"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="unit-row-5"]').exists()).toBe(true);

        await wrapper.find('[data-testid="show-inactive-staff"]').trigger('click');

        expect(wrapper.find('[data-testid="unit-row-3"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-testid="unit-row-4"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-testid="unit-inactive-3"]').text()).toBe('Inactive');
        expect(wrapper.find('[data-testid="unit-inactive-4"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('offers no Show inactive when nothing is inactive', () => {
        const wrapper = mountPage({ units: UNITS.filter((unit) => !unit.inactive), inactivePlaced: [] });

        expect(wrapper.find('[data-testid="show-inactive"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="show-inactive-staff"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="inactive-placed-panel"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('deactivates a unit after a confirm that says how many people are placed there, with an optional reason', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="deactivate-unit"]').trigger('click');
        await flushPromises();

        expect(inDialog('activate-dialog').textContent).toContain('Deactivate Chief of Staff?');
        expect(inDialog('activate-dialog').textContent).toContain('3 people are placed in it or under it.');
        expect(inDialog('activate-dialog').textContent).toContain('Optional.');
        expect(patchSpy).not.toHaveBeenCalled();

        inDialog('activate-dialog-confirm').click();
        await flushPromises();

        expect(patchSpy).toHaveBeenCalledWith('/settings/org-structure/2/active', { active: false, reason: null }, expect.objectContaining({ preserveScroll: true }));
        wrapper.unmount();
    });

    it('reactivates a deactivated unit, and offers nothing on a unit only inactive through its parent', async () => {
        const wrapper = mountPage();
        await wrapper.find('[data-testid="show-inactive-staff"]').trigger('click');

        await wrapper.find('[data-testid="unit-row-4"]').trigger('click');
        expect(wrapper.find('[data-testid="inactive-note"]').text()).toContain('Inactive with Recruitment Oversight Office.');
        expect(wrapper.find('[data-testid="reactivate-unit"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deactivate-unit"]').exists()).toBe(false);

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');
        await wrapper.find('[data-testid="reactivate-unit"]').trigger('click');
        await flushPromises();
        const reason = inDialog('activate-dialog-reason');
        reason.value = 'The office is back';
        reason.dispatchEvent(new Event('input'));
        await flushPromises();
        inDialog('activate-dialog-confirm').click();
        await flushPromises();

        expect(patchSpy).toHaveBeenCalledWith('/settings/org-structure/3/active', { active: true, reason: 'The office is back' }, expect.anything());
        wrapper.unmount();
    });

    it('lists the people still placed in an inactive unit, and opens the unit from the list', async () => {
        const wrapper = mountPage();
        const panel = wrapper.find('[data-testid="inactive-placed-panel"]');

        expect(panel.text()).toContain('2 people are placed in an inactive unit');
        const rows = wrapper.findAll('[data-testid="inactive-placed-trooper"]');
        expect(rows[0].text()).toContain('SGT Hale.R, ROO Officer in Recruitment Oversight Office (ROO)');
        expect(rows[1].text()).toContain('CPL Ives.D in ROO Desk (ROOD)');

        await rows[1].find('button').trigger('click');

        expect(wrapper.find('[data-testid="unit-row-4"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="unit-row-4"]').attributes('aria-selected')).toBe('true');
        wrapper.unmount();
    });

    it('never offers an inactive unit as a parent to move to', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-5"]').trigger('click');
        const parents = wrapper.vm.$.setupState.editLegalParents.map((unit) => unit.id);

        expect(parents).toContain(2);
        expect(parents).not.toContain(3);
        expect(parents).not.toContain(4);
        wrapper.unmount();
    });
});
