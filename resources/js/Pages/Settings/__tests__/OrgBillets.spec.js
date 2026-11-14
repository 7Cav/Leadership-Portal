import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// The billets on Org structure (2026-11-13), as the page wires them: the summary card for an Admin, the selected unit's
// Billets list, and Add a staff group on the regiment and on a staff group.
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: vi.fn(), post: vi.fn() },
    useForm: (initial) => ({ ...initial, processing: false, errors: {}, post: vi.fn(), patch: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
}));

const UNITS = [
    { id: 1, parent_id: null, type: 'regiment', kind: 'line', name: '7th Cavalry Regiment', callsign: '7CAV', path: '1/', trooper_count: 3 },
    { id: 2, parent_id: 1, type: 'battalion', kind: 'line', name: '2nd Battalion', callsign: '2-7', path: '1/2/', trooper_count: 3 },
    { id: 3, parent_id: 1, type: 'group', kind: 'staff', name: 'Executive Officer', callsign: 'XO', path: '1/3/', trooper_count: 0 },
    { id: 4, parent_id: 1, type: 'group', kind: 'eloa', name: 'Extended Leave of Absence', callsign: 'ELOA', path: '1/4/', trooper_count: 0 },
];

const BILLETS = {
    byUnit: { 2: [{ positionId: 178, title: '2-7 Commanding Officer', renamedFrom: null, holders: 1, source: 'command', rule: null, movable: true, removable: false }] },
    conflicts: [],
    conflictsByUnit: {},
    catalog: [],
    renamed: [],
    new: [{ positionId: 952, title: 'War of Rights Start-Up', group: 'Start-Up Games', holders: 0 }],
};

function mountPage(props = {}) {
    return mount(OrgStructure, { props: { units: UNITS, isAdmin: true, billets: BILLETS, ...props } });
}

describe('Settings/OrgStructure billets', () => {
    it('shows an Admin the billets that wait, and nobody else', () => {
        expect(mountPage().find('[data-testid="billet-notices"]').text()).toContain('War of Rights Start-Up');
        expect(mountPage({ isAdmin: false, billets: null }).find('[data-testid="billet-notices"]').exists()).toBe(false);
    });

    it('lists the selected unit\'s billets in its details', async () => {
        const wrapper = mountPage();
        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="unit-billet"]').text()).toContain('2-7 Commanding Officer');
        expect(mountPage({ isAdmin: false, billets: null }).find('[data-testid="unit-billets"]').exists()).toBe(false);
    });

    it('offers Add a staff group on the regiment and a staff group, never on a line unit or a holding group', async () => {
        const wrapper = mountPage();
        const offered = async (id) => {
            await wrapper.find(`[data-testid="unit-row-${id}"]`).trigger('click');
            return wrapper.find('[data-testid="add-staff-group-open"]').exists();
        };

        expect(await offered(1)).toBe(true);
        expect(await offered(3)).toBe(true);
        expect(await offered(2)).toBe(false);
        expect(await offered(4)).toBe(false);
    });

    it('offers no Add a staff group on an inactive staff group', async () => {
        const units = [...UNITS, { id: 5, parent_id: 1, type: 'group', kind: 'staff', name: 'Old Desk', callsign: null, path: '1/5/', trooper_count: 0, inactive: true, deactivated_at: '2026-11-01T00:00:00Z', placed_count: 0 }];
        const wrapper = mountPage({ units });
        await wrapper.find('[data-testid="show-inactive-staff"]').trigger('click');
        await wrapper.find('[data-testid="unit-row-5"]').trigger('click');

        expect(wrapper.find('[data-testid="add-staff-group-open"]').exists()).toBe(false);
        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');
        expect(wrapper.find('[data-testid="add-staff-group-open"]').exists()).toBe(true);
    });
});
