import { afterEach, describe, it, expect, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// The org page after the 2026-10-29 changes: the line and the staff in two cards, empty line units hidden until asked
// for, ACD and
// DEVCOM shown again under RDC, DEVCOM's staff under DEVCOM, and who oversees each battalion and the Reservists.

const routerPost = vi.fn();
const routerDelete = vi.fn();

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: (...args) => routerDelete(...args), post: (...args) => routerPost(...args) },
    useForm: (initial) => ({ ...initial, processing: false, errors: {}, post: vi.fn(), patch: vi.fn(), clearErrors: vi.fn() }),
}));

const base = { label: null, own_count: 0, lead: null, lead_vacant: false, empty: false, overseeable: false, oversight: null, oversight_vacant: false };
const UNITS = [
    { ...base, id: 1, parent_id: null, type: 'regiment', kind: 'line', name: '7th Cavalry Regiment', callsign: '7CAV', path: '1/', trooper_count: 9 },
    {
        ...base,
        id: 2,
        parent_id: 1,
        type: 'battalion',
        kind: 'line',
        name: '2nd Battalion',
        callsign: '2-7',
        path: '1/2/',
        trooper_count: 5,
        overseeable: true,
        oversight: { trooperId: 70, displayName: 'MG Rob.Y', serving: true },
    },
    { ...base, id: 3, parent_id: 1, type: 'battalion', kind: 'line', name: '3rd Battalion', callsign: '3-7', path: '1/3/', trooper_count: 2, overseeable: true, oversight_vacant: true },
    { ...base, id: 4, parent_id: 2, type: 'company', kind: 'line', name: 'Echo Company', callsign: 'E/2-7', path: '1/2/4/', trooper_count: 0, empty: true, lead_vacant: true },
    { ...base, id: 5, parent_id: 1, type: 'battalion', kind: 'line', name: 'Auxiliary Combat Detachment', callsign: 'ACD', path: '1/5/', trooper_count: 1, overseeable: true, oversight_vacant: true },
    { ...base, id: 6, parent_id: 1, type: 'battalion', kind: 'line', name: 'DEVCOM', callsign: 'DEVCOM', path: '1/6/', trooper_count: 1, overseeable: true, oversight_vacant: true },
    { ...base, id: 10, parent_id: 1, type: 'group', kind: 'staff', name: 'Executive Officer', callsign: 'XO', path: '1/10/', trooper_count: 3 },
    { ...base, id: 11, parent_id: 10, type: 'group', kind: 'staff', name: 'Reserve & Development Command', callsign: 'RDC', path: '1/10/11/', trooper_count: 3 },
    { ...base, id: 12, parent_id: 11, type: 'group', kind: 'staff', name: 'DEVCOM Staff', callsign: 'DEVCOM STAFF', path: '1/10/11/12/', trooper_count: 2 },
    {
        ...base,
        id: 13,
        parent_id: 11,
        type: 'group',
        kind: 'reserve',
        name: 'Reservists',
        callsign: 'RES',
        path: '1/10/11/13/',
        trooper_count: 1,
        overseeable: true,
        oversight: { trooperId: 71, displayName: 'COL Rogers.S', serving: true },
    },
    { ...base, id: 14, parent_id: 1, type: 'group', kind: 'recruit', name: 'New Recruits', callsign: 'NR', path: '1/14/', trooper_count: 4 },
];
const REFERENCES = [
    { parent_id: 11, unit_id: 5 },
    { parent_id: 11, unit_id: 6 },
    { parent_id: 6, unit_id: 12 },
];

function mountPage(props = {}) {
    return mount(OrgStructure, { props: { units: UNITS, isAdmin: false, references: REFERENCES, ...props }, attachTo: document.body });
}

describe('Settings/OrgStructure cards', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        routerPost.mockReset();
        routerDelete.mockReset();
        document.body.innerHTML = '';
    });

    it('puts the line units in the chain of command and the groups in the staff card', () => {
        const wrapper = mountPage();
        const line = wrapper.find('[data-testid="line-card"]');
        const staff = wrapper.find('[data-testid="staff-card"]');

        expect(line.find('[data-testid="unit-row-2"]').exists()).toBe(true);
        expect(line.find('[data-testid="unit-row-11"]').exists()).toBe(false);
        expect(staff.find('[data-testid="unit-row-10"]').exists()).toBe(true);
        expect(staff.find('[data-testid="unit-row-13"]').exists()).toBe(true);
        expect(staff.find('[data-testid="unit-row-2"]').exists()).toBe(false);
    });

    it('leaves the staff card out when there are no groups', () => {
        const wrapper = mountPage({ units: UNITS.filter((unit) => unit.kind === 'line') });

        expect(wrapper.find('[data-testid="staff-card"]').exists()).toBe(false);
    });

    it('hides empty line units until asked, and shows one muted with no vacant lead', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="unit-row-4"]').exists()).toBe(false);

        await wrapper.find('[data-testid="show-empty"]').setValue(true);
        const row = wrapper.find('[data-testid="unit-row-4"]');
        expect(row.exists()).toBe(true);
        expect(row.classes()).toContain('text-muted-foreground');
        expect(wrapper.find('[data-testid="lead-vacant-4"]').exists()).toBe(false);
    });

    it('offers no empty units toggle when no unit is empty', () => {
        const wrapper = mountPage({ units: UNITS.map((unit) => ({ ...unit, empty: false })) });

        expect(wrapper.find('[data-testid="show-empty"]').exists()).toBe(false);
    });

    it('keeps New Recruits behind their toggle in the staff card', async () => {
        const wrapper = mountPage();
        const staff = wrapper.find('[data-testid="staff-card"]');

        expect(staff.find('[data-testid="unit-row-14"]').exists()).toBe(false);
        await staff.find('[data-testid="show-recruits"]').setValue(true);
        expect(wrapper.find('[data-testid="staff-card"] [data-testid="unit-row-14"]').exists()).toBe(true);
    });

    it('shows ACD and DEVCOM under RDC, and DEVCOM\'s staff under DEVCOM, as reference rows', () => {
        const wrapper = mountPage();
        const staff = wrapper.find('[data-testid="staff-card"]');
        const line = wrapper.find('[data-testid="line-card"]');

        expect(staff.find('[data-testid="unit-ref-11-5"]').text()).toContain('Auxiliary Combat Detachment');
        expect(staff.find('[data-testid="unit-ref-11-5"]').text()).toContain('in the chain of command');
        expect(staff.find('[data-testid="unit-ref-11-6"]').exists()).toBe(true);
        expect(line.find('[data-testid="unit-ref-6-12"]').text()).toContain('DEVCOM Staff');
        expect(line.find('[data-testid="unit-ref-6-12"]').text()).toContain('under RDC');
        // A reference, not a second copy: the unit's own row is in one place only.
        expect(wrapper.findAll('[data-testid="unit-row-5"]')).toHaveLength(1);
        expect(wrapper.findAll('[data-testid="unit-row-12"]')).toHaveLength(1);
    });

    it('opens the same details as the unit\'s own row when a reference row is chosen', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-ref-6-12"]').trigger('click');

        expect(wrapper.find('[data-testid="detail-panel"]').text()).toContain('DEVCOM Staff');
        expect(wrapper.find('[data-testid="unit-row-12"]').attributes('aria-selected')).toBe('true');
    });

    it('shows each battalion\'s overseer or vacant, and the Reservists\' overseer, in both cards', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="oversight-2"]').text()).toBe('Oversight: MG Rob.Y');
        expect(wrapper.find('[data-testid="oversight-3"]').text()).toBe('Oversight: vacant');
        expect(wrapper.find('[data-testid="oversight-13"]').text()).toBe('Oversight: COL Rogers.S');
        expect(wrapper.find('[data-testid="ref-oversight-11-5"]').text()).toBe('Oversight: vacant');
        expect(wrapper.find('[data-testid="oversight-10"]').exists()).toBe(false);
    });

    it('marks an overseer who is no longer serving', () => {
        const units = UNITS.map((unit) => (unit.id === 2 ? { ...unit, oversight: { ...unit.oversight, serving: false } } : unit));
        const wrapper = mountPage({ units });

        expect(wrapper.find('[data-testid="oversight-2"]').text()).toBe('Oversight: MG Rob.Y (not serving)');
    });

    it('names the overseer in the details, with no controls for a non-Admin', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        expect(wrapper.find('[data-testid="detail-oversight"]').text()).toBe('Oversight: MG Rob.Y');
        expect(wrapper.find('[data-testid="oversight"]').exists()).toBe(false);
    });

    it('lets an Admin set an overseer with a reason', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ troopers: [{ id: 72, displayName: 'MG LaCombe.M', departed: false }] }) })));
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');
        const panel = wrapper.find('[data-testid="oversight"]');
        expect(panel.find('[data-testid="oversight-set"]').attributes('disabled')).toBeDefined();
        expect(panel.find('[data-testid="oversight-clear"]').exists()).toBe(false);

        await panel.find('[data-testid="oversight-search"]').setValue('LaC');
        await panel.find('[data-testid="oversight-search"]').trigger('input');
        await flushPromises();
        await wrapper.find('[data-testid="oversight-result"]').trigger('click');
        await wrapper.find('[data-testid="oversight-reason"]').setValue('Named by Regiment.');
        await wrapper.find('[data-testid="oversight-set"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/settings/org-structure/3/oversight', { trooper_id: 72, reason: 'Named by Regiment.' }, expect.any(Object));
    });

    it('lets an Admin clear an overseer only with a reason', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        expect(wrapper.find('[data-testid="oversight-current"]').text()).toBe('MG Rob.Y');
        expect(wrapper.find('[data-testid="oversight-clear"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="oversight-reason"]').setValue('Handed over.');
        await wrapper.find('[data-testid="oversight-clear"]').trigger('click');

        expect(routerDelete).toHaveBeenCalledWith('/settings/org-structure/2/oversight', expect.objectContaining({ data: { reason: 'Handed over.' } }));
    });

    it('shows the server\'s reason when a change is refused', async () => {
        routerPost.mockImplementation((url, data, options) => options.onError({ oversight: 'MG Rob.Y already oversees 2-7.' }));
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ troopers: [{ id: 70, displayName: 'MG Rob.Y', departed: false }] }) })));
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="oversight-search"]').setValue('Rob');
        await wrapper.find('[data-testid="oversight-search"]').trigger('input');
        await flushPromises();
        await wrapper.find('[data-testid="oversight-result"]').trigger('click');
        await wrapper.find('[data-testid="oversight-reason"]').setValue('Again.');
        await wrapper.find('[data-testid="oversight-set"]').trigger('click');

        expect(wrapper.find('[data-testid="oversight-error"]').text()).toBe('MG Rob.Y already oversees 2-7.');
    });

    it('offers no oversight on a unit that takes none', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-11"]').trigger('click');

        expect(wrapper.find('[data-testid="oversight"]').exists()).toBe(false);
    });
});
