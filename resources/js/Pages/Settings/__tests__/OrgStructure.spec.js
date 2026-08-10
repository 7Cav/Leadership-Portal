import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// `useForm` needs a real Inertia page context; stub the adapter surface this page touches
// while keeping enough behaviour to assert the submit call it makes (Story 1.8 AC #2, #5).
const submit = vi.fn();
let formState = { type: 'battalion', name: '', callsign: '', parent_id: null, processing: false, errors: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance (it injects the head-manager on setup),
    // which this unit test deliberately doesn't stand up — stub it to a no-op like the rest
    // of the adapter surface this page touches.
    Head: { template: '<head></head>' },
    useForm: () => ({
        ...formState,
        post: submit,
    }),
}));

const UNITS = [
    { id: 1, parent_id: null, type: 'regiment', name: '7th Cavalry Regiment', callsign: null, path: '1/', trooper_count: 0 },
    { id: 2, parent_id: 1, type: 'battalion', name: '2nd Battalion', callsign: '2-7', path: '1/2/', trooper_count: 0 },
];

describe('Settings/OrgStructure', () => {
    it('renders every unit in the tree', () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });

        expect(wrapper.text()).toContain('7th Cavalry Regiment');
        expect(wrapper.text()).toContain('2nd Battalion');
    });

    it('hides the add-node control for a non-Admin (AC #5 — UI mirrors the server gate)', () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });

        expect(wrapper.find('[data-testid="add-unit-form"]').exists()).toBe(false);
    });

    it('shows the add-node control for an Admin', () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        expect(wrapper.find('[data-testid="add-unit-form"]').exists()).toBe(true);
    });

    it('shows the detail panel for the selected unit', async () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        expect(wrapper.find('[data-testid="detail-panel"]').text()).toContain('2nd Battalion');
        expect(wrapper.find('[data-testid="detail-panel"]').text()).toContain('2-7');
    });
});
