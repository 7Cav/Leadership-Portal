import { afterEach, describe, it, expect, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// Staff, ELOA and New Recruit placement on the org page (decided 2026-10-24): HQ staff counted in their unit, New
// Recruits hidden until asked for, an ELOA trooper's "was", a unit's team label and a vacant lead.

const labelPatch = vi.fn();
const routerPost = vi.fn();
const routerDelete = vi.fn();

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: (...args) => routerDelete(...args), post: (...args) => routerPost(...args) },
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

        expect(wrapper.find('[data-testid="unit-row-2"]').text()).toContain('Headcount 3');
        expect(wrapper.find('[data-testid="unit-row-3"]').text()).toContain('Headcount 2');
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

    it('shows a unit\'s team label next to its name', () => {
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

    describe('acting lead', () => {
        afterEach(() => {
            vi.unstubAllGlobals();
            routerPost.mockReset();
            routerDelete.mockReset();
        });

        it('shows an acting lead as the unit\'s lead, marked acting', async () => {
            const units = UNITS.map((unit) =>
                unit.id === 2 ? { ...unit, lead: { displayName: 'SGT Taylor.C', billet: 'DEVCOM Lead', source: 'acting' }, lead_vacant: false, acting_lead: { id: 7, displayName: 'SGT Taylor.C' } } : unit,
            );
            const wrapper = mountPage({ units });

            expect(wrapper.find('[data-testid="lead-vacant-2"]').exists()).toBe(false);
            await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
            expect(wrapper.find('[data-testid="detail-lead"]').text()).toBe('Lead: SGT Taylor.C (acting)');
            // Not an Admin: no acting-lead controls.
            expect(wrapper.find('[data-testid="acting-lead"]').exists()).toBe(false);
        });

        it('lets an Admin search for a trooper and make them acting lead, with a reason', async () => {
            vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ troopers: [{ id: 7, displayName: 'SGT Taylor.C', departed: false }, { id: 8, displayName: 'PVT Gone.X', departed: true }] }) })));
            const wrapper = mountPage({ isAdmin: true });

            await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
            const panel = wrapper.find('[data-testid="acting-lead"]');
            expect(panel.text()).toContain('DEVCOM starter platoon');
            expect(panel.find('[data-testid="acting-assign"]').attributes('disabled')).toBeDefined();

            await panel.find('[data-testid="acting-search"]').setValue('Tay');
            await panel.find('[data-testid="acting-search"]').trigger('input');
            await flushPromises();
            const results = wrapper.findAll('[data-testid="acting-result"]');
            // A departed trooper cannot lead, so is not offered.
            expect(results.map((result) => result.text())).toEqual(['SGT Taylor.C']);

            await results[0].trigger('click');
            await wrapper.find('[data-testid="acting-reason"]').setValue('Runs the 1st starter platoon.');
            await wrapper.find('[data-testid="acting-assign"]').trigger('click');

            expect(routerPost).toHaveBeenCalledWith('/settings/org-structure/2/acting-lead', { trooper_id: 7, reason: 'Runs the 1st starter platoon.' }, expect.any(Object));
        });

        it('lets an Admin remove an acting lead only with a reason', async () => {
            const units = UNITS.map((unit) => (unit.id === 2 ? { ...unit, acting_lead: { id: 7, displayName: 'SGT Taylor.C' } } : unit));
            const wrapper = mountPage({ isAdmin: true, units });

            await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
            expect(wrapper.find('[data-testid="acting-lead-current"]').text()).toBe('SGT Taylor.C');
            expect(wrapper.find('[data-testid="acting-remove"]').attributes('disabled')).toBeDefined();

            await wrapper.find('[data-testid="acting-reason"]').setValue('Handed over.');
            await wrapper.find('[data-testid="acting-remove"]').trigger('click');

            expect(routerDelete).toHaveBeenCalledWith('/settings/org-structure/2/acting-lead', expect.objectContaining({ data: { reason: 'Handed over.' } }));
        });

        it('offers no acting lead on a group or the regiment', async () => {
            const wrapper = mountPage({ isAdmin: true });

            await wrapper.find('[data-testid="unit-row-4"]').trigger('click');
            expect(wrapper.find('[data-testid="acting-lead"]').exists()).toBe(false);
            await wrapper.find('[data-testid="unit-row-1"]').trigger('click');
            expect(wrapper.find('[data-testid="acting-lead"]').exists()).toBe(false);
        });
    });
});
