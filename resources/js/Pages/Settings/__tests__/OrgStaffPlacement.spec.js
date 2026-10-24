import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// Staff, ELOA and New Recruit placement on the org page (decided 2026-10-24): HQ staff counted in their unit, New
// Recruits hidden until asked for, an ELOA trooper's "was", a unit's game label and a vacant lead.

const labelPatch = vi.fn();

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: vi.fn(), post: vi.fn() },
    useForm: (initial) => ({ ...initial, processing: false, errors: {}, post: vi.fn(), patch: 'label' in initial ? labelPatch : vi.fn(), clearErrors: vi.fn() }),
}));

const UNITS = [
    { id: 1, parent_id: null, type: 'regiment', kind: 'line', name: '7th Cavalry Regiment', callsign: '7CAV', label: null, path: '1/', own_count: 0, trooper_count: 4, lead: null, lead_vacant: false },
    { id: 2, parent_id: 1, type: 'platoon', kind: 'line', name: 'DEVCOM Platoon', callsign: '1/D/DEVCOM', label: 'WARDOGS', path: '1/2/', own_count: 1, trooper_count: 3, lead: null, lead_vacant: true },
    { id: 3, parent_id: 2, type: 'section', kind: 'line', name: '1st Section', callsign: '1/1/D/DEVCOM', label: null, path: '1/2/3/', own_count: 2, trooper_count: 2, lead: { displayName: 'SGT Abernathy.L', billet: 'Section Leader 1/1/D/DEVCOM' }, lead_vacant: false },
    { id: 4, parent_id: 1, type: 'group', kind: 'eloa', name: 'Extended Leave of Absence', callsign: 'ELOA', label: null, path: '1/4/', own_count: 1, trooper_count: 1, lead: null, lead_vacant: false },
    { id: 5, parent_id: 1, type: 'group', kind: 'recruit', name: 'New Recruits', callsign: 'NR', label: null, path: '1/5/', own_count: 1, trooper_count: 1, lead: null, lead_vacant: false },
];

const MEMBERS = {
    4: [{ id: 40, displayName: 'SPC Marlowe.E', billet: 'ELOA', was: '1/1/D/DEVCOM' }],
    5: [{ id: 50, displayName: 'RCT Quill.R', billet: 'New Recruit', was: null }],
};

function mountPage(props = {}) {
    return mount(OrgStructure, { props: { units: UNITS, isAdmin: false, groupMembers: MEMBERS, ...props } });
}

describe('Settings/OrgStructure placement', () => {
    it('counts HQ staff in their unit: the platoon shows its own PSG plus its section', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="unit-row-2"]').text()).toContain('3 troopers');
        expect(wrapper.find('[data-testid="unit-row-3"]').text()).toContain('2 troopers');
    });

    it('hides New Recruits by default and shows them on the toggle', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="unit-row-5"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="unit-row-4"]').exists()).toBe(true);

        await wrapper.find('[data-testid="show-recruits"]').setValue(true);
        expect(wrapper.find('[data-testid="unit-row-5"]').text()).toContain('1 trooper');
    });

    it('offers no toggle when there is no New Recruits group', () => {
        const wrapper = mountPage({ units: UNITS.filter((unit) => unit.kind !== 'recruit') });

        expect(wrapper.find('[data-testid="show-recruits"]').exists()).toBe(false);
    });

    it('lists an ELOA trooper with the unit they were in', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-4"]').trigger('click');

        const member = wrapper.find('[data-testid="group-member"]');
        expect(member.text()).toContain('SPC Marlowe.E');
        expect(member.find('[data-testid="member-was"]').text()).toBe('was: 1/1/D/DEVCOM');
    });

    it('shows no "was" for a trooper with none', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="show-recruits"]').setValue(true);
        await wrapper.find('[data-testid="unit-row-5"]').trigger('click');

        expect(wrapper.find('[data-testid="group-member"]').text()).toContain('RCT Quill.R');
        expect(wrapper.find('[data-testid="member-was"]').exists()).toBe(false);
    });

    it('shows a unit\'s game label next to its name', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="unit-label-2"]').text()).toBe('WARDOGS');
        expect(wrapper.find('[data-testid="unit-label-3"]').exists()).toBe(false);
    });

    it('shows "Lead: vacant" for a unit whose lead billet nobody holds, and the holder otherwise', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="lead-vacant-2"]').text()).toBe('Lead: vacant');
        expect(wrapper.find('[data-testid="lead-vacant-3"]').exists()).toBe(false);

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');
        expect(wrapper.find('[data-testid="detail-lead"]').text()).toBe('Lead: SGT Abernathy.L');
    });

    it('lets an Admin save a unit\'s label', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="label-form"]').trigger('submit');

        expect(labelPatch).toHaveBeenCalledWith('/settings/org-structure/2/label', expect.any(Object));
    });

    it('offers HQ nodes and groups as placement targets, not only sections', async () => {
        const wrapper = mountPage({ isAdmin: true, unplacedTroopers: [{ id: 9, cav_id: 9, username: 'Doe.J', rank: 'PFC', mos: null, billet: 'S1 Clerk', position_id: 1 }], unplacedTotal: 1 });

        await wrapper.find('[data-testid="assign-trooper"]').trigger('click');
        const options = wrapper.findAll('[data-testid="assign-section"] option').map((option) => option.text());

        expect(options.some((text) => text.includes('1/D/DEVCOM'))).toBe(true);
        expect(options.some((text) => text.includes('Extended Leave of Absence'))).toBe(true);
    });
});
