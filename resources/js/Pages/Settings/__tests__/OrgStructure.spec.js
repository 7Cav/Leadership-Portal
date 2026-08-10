import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// `useForm` needs a real Inertia page context; stub the adapter surface this page touches
// while keeping enough behaviour to assert the submit call it makes (Story 1.8 AC #2, #5;
// Story 1.9 AC #1, #2, #4). The page calls `useForm` twice (add-node form, edit form) — tell
// them apart by their distinct initial shape (`type` only exists on the add form).
const addSubmit = vi.fn();
const editSubmit = vi.fn();
let deleteSpy = vi.fn();
let addFormState = { type: 'battalion', name: '', callsign: '', parent_id: null, processing: false, errors: {} };
let editFormState = { name: '', callsign: '', parent_id: null, processing: false, errors: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance (it injects the head-manager on setup),
    // which this unit test deliberately doesn't stand up — stub it to a no-op like the rest
    // of the adapter surface this page touches.
    Head: { template: '<head></head>' },
    router: { delete: (...args) => deleteSpy(...args) },
    useForm: (initial) => ('type' in initial
        ? { ...addFormState, post: addSubmit }
        : { ...editFormState, patch: editSubmit, clearErrors: vi.fn() }),
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

    // AC #3: the tree must be collapsible. This is `OrgTreeNode`'s entire reason to exist, so
    // its toggle behaviour is asserted from the page that actually wires `collapsed` through.
    it('collapses and re-expands a branch on caret click', async () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });

        expect(wrapper.find('[data-testid="unit-row-2"]').exists()).toBe(true);

        await wrapper.find('[data-testid="unit-row-1"] button').trigger('click');
        expect(wrapper.find('[data-testid="unit-row-2"]').exists()).toBe(false);

        await wrapper.find('[data-testid="unit-row-1"] button').trigger('click');
        expect(wrapper.find('[data-testid="unit-row-2"]').exists()).toBe(true);
    });

    // The single most breakable behaviour on this page is the write — assert the submit path
    // is actually wired to the server route, not just that the form renders.
    it('submits the add-node form to the org-structure store route', async () => {
        addFormState = { type: 'battalion', name: 'New Battalion', callsign: '9-7', parent_id: 1, processing: false, errors: {} };
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="add-unit-form"]').trigger('submit');

        expect(addSubmit).toHaveBeenCalledWith('/settings/org-structure', expect.objectContaining({ onSuccess: expect.any(Function) }));
    });

    it('renders a server-side validation error next to its field', () => {
        addFormState = {
            type: 'section',
            name: '',
            callsign: '',
            parent_id: null,
            processing: false,
            errors: { type: 'A section cannot be placed under a regiment.' },
        };
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        expect(wrapper.find('[data-testid="error-type"]').text()).toBe('A section cannot be placed under a regiment.');
    });

    // Story 1.9 AC #1, #2 — the detail panel becomes an editable form for an Admin, pre-filled
    // from the selected unit (the `watch(selectedUnit, ...)` in OrgStructure.vue).
    it('shows editable name/callsign fields for an Admin, pre-filled with the unit\'s current values', async () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        expect(wrapper.find('[data-testid="edit-unit-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-name"]').element.value).toBe('2nd Battalion');
        expect(wrapper.find('[data-testid="edit-callsign"]').element.value).toBe('2-7');
    });

    it('keeps the detail panel read-only for a non-Admin (AC #5 — UI mirrors the server gate)', async () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        expect(wrapper.find('[data-testid="edit-unit-form"]').exists()).toBe(false);
    });

    it('submits the edit form to the update route for the selected unit', async () => {
        editFormState = { name: '2nd Battalion', callsign: '2-7', parent_id: 1, processing: false, errors: {} };
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="edit-unit-form"]').trigger('submit');

        expect(editSubmit).toHaveBeenCalledWith('/settings/org-structure/2', expect.objectContaining({ preserveScroll: true }));
    });

    it('renders a server-side validation error on the edit form', async () => {
        editFormState = {
            name: '2nd Battalion',
            callsign: '2-7',
            parent_id: 1,
            processing: false,
            errors: { parent_id: 'Cannot re-parent "2nd Battalion" under itself.' },
        };
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        expect(wrapper.find('[data-testid="edit-error-parent_id"]').text()).toBe('Cannot re-parent "2nd Battalion" under itself.');
    });

    // Story 1.9 AC #4 — delete asks for confirmation before dispatching the request.
    it('asks for confirmation and calls router.delete when deleting from the detail panel', async () => {
        vi.stubGlobal('confirm', vi.fn(() => true));
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="delete-unit"]').trigger('click');

        expect(confirm).toHaveBeenCalled();
        expect(deleteSpy).toHaveBeenCalledWith('/settings/org-structure/2', expect.objectContaining({ preserveScroll: true }));
        vi.unstubAllGlobals();
    });

    // A blocked delete (remaining children) 422s with `errors.delete` — the message must
    // actually reach the page, or a refused delete looks like nothing happened at all.
    it('shows the server\'s reason when a delete is refused', async () => {
        deleteSpy = vi.fn((url, { onError }) => onError({ delete: 'Cannot delete "2nd Battalion" — it still has child units.' }));
        vi.stubGlobal('confirm', vi.fn(() => true));
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="delete-unit"]').trigger('click');

        expect(wrapper.find('[data-testid="delete-error"]').text()).toBe('Cannot delete "2nd Battalion" — it still has child units.');
        vi.unstubAllGlobals();
        deleteSpy = vi.fn();
    });

    it('does not delete when the confirmation is declined', async () => {
        deleteSpy.mockClear();
        vi.stubGlobal('confirm', vi.fn(() => false));
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="delete-unit"]').trigger('click');

        expect(deleteSpy).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    // Story 1.9 AC #6 — hover tools per row, Admin-only. Battalion (id 2) is non-root and
    // non-leaf, so it's the one row that should show all three tools.
    it('shows all three inline tools for an Admin, hides them all for a non-Admin', () => {
        const adminWrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });
        expect(adminWrapper.find('[data-testid="tool-edit-2"]').exists()).toBe(true);
        expect(adminWrapper.find('[data-testid="tool-add-2"]').exists()).toBe(true);
        expect(adminWrapper.find('[data-testid="tool-delete-2"]').exists()).toBe(true);

        const nonAdminWrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });
        expect(nonAdminWrapper.find('[data-testid="tool-edit-2"]').exists()).toBe(false);
        expect(nonAdminWrapper.find('[data-testid="tool-add-2"]').exists()).toBe(false);
        expect(nonAdminWrapper.find('[data-testid="tool-delete-2"]').exists()).toBe(false);
    });

    it('gives the root only the rename tool — no add-child, no delete', () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        expect(wrapper.find('[data-testid="tool-edit-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tool-add-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="tool-delete-1"]').exists()).toBe(false);
    });

    it('hides the detail panel\'s delete button for the root, matching the tree\'s tools', async () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-1"]').trigger('click');

        expect(wrapper.find('[data-testid="delete-unit"]').exists()).toBe(false);
    });

    it('pre-fills the add-node form from a row\'s ＋ tool', async () => {
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        // Battalion (id 2) has no children yet, so its ＋ tool adds a Company under it.
        await wrapper.find('[data-testid="tool-add-2"]').trigger('click');

        expect(wrapper.vm.form.type).toBe('company');
        expect(wrapper.vm.form.parent_id).toBe(2);
    });

    // The tree's own 🗑 tool must wire to the same delete flow as the detail panel's button —
    // it emits a raw id (not `{ id }`, unlike `addChild`'s payload), so a mismatch there would
    // silently no-op.
    it('deletes from the tree\'s own 🗑 tool, not just the detail panel', async () => {
        vi.stubGlobal('confirm', vi.fn(() => true));
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="tool-delete-2"]').trigger('click');

        expect(deleteSpy).toHaveBeenCalledWith('/settings/org-structure/2', expect.objectContaining({ preserveScroll: true }));
        vi.unstubAllGlobals();
    });
});
