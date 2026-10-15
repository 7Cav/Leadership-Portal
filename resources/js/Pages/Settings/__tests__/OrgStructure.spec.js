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
let postSpy = vi.fn();
let addFormState = { type: 'battalion', name: '', callsign: '', parent_id: null, processing: false, errors: {} };
let editFormState = { name: '', callsign: '', parent_id: null, processing: false, errors: {} };

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { success: (...a) => toastSuccess(...a), error: (...a) => toastError(...a) } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance (it injects the head-manager on setup),
    // which this unit test deliberately doesn't stand up — stub it to a no-op like the rest
    // of the adapter surface this page touches.
    Head: { template: '<head></head>' },
    router: { delete: (...args) => deleteSpy(...args), post: (...args) => postSpy(...args) },
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

    it('says why an add was refused instead of showing the raw error page', async () => {
        toastError.mockReset();
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="add-unit-form"]').trigger('submit');

        expect(addSubmit.mock.lastCall[1].onHttpException({ status: 403 })).toBe(false);
        expect(toastError).toHaveBeenCalledWith('The unit was not added. You are not allowed to do that; reload the page, or exit view as first.');
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

    it('says why an edit failed instead of showing the raw error page', async () => {
        toastError.mockReset();
        const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        await wrapper.find('[data-testid="edit-unit-form"]').trigger('submit');

        expect(editSubmit.mock.lastCall[1].onHttpException({ status: 500 })).toBe(false);
        expect(toastError).toHaveBeenCalledWith('The unit was not saved (error 500). Try again.');
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

    // Story 2.9 — import from the Cav position groups.
    describe('import from Cav roster', () => {
        it('hides the import action for a non-Admin', () => {
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false } });

            expect(wrapper.find('[data-testid="import-from-cav"]').exists()).toBe(false);
        });

        it('posts to the import route when an Admin clicks it', async () => {
            postSpy = vi.fn();
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

            await wrapper.find('[data-testid="import-from-cav"]').trigger('click');

            expect(postSpy).toHaveBeenCalledWith('/settings/org-structure/import', {}, expect.objectContaining({ preserveScroll: true }));
            expect(wrapper.find('[data-testid="import-from-cav"]').text()).toBe('Importing…');
            expect(wrapper.find('[data-testid="import-from-cav"]').attributes('disabled')).toBeDefined();
        });

        it('shows the server error when the import is refused, and re-enables the button', async () => {
            postSpy = vi.fn((url, data, { onError, onFinish }) => {
                onError({ import: 'Could not read the Cav position groups: down.' });
                onFinish();
            });
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

            await wrapper.find('[data-testid="import-from-cav"]').trigger('click');

            expect(wrapper.find('[data-testid="import-error"]').text()).toBe('Could not read the Cav position groups: down.');
            expect(wrapper.find('[data-testid="import-from-cav"]').attributes('disabled')).toBeUndefined();
        });

        it('reports how many nodes and mappings were created, and what was skipped', () => {
            const wrapper = mount(OrgStructure, {
                props: {
                    units: UNITS,
                    isAdmin: true,
                    importResult: { unitsCreated: 31, mappingsCreated: 29, skippedTitles: ['Section Leader 3/2/C/1-7'] },
                },
            });

            const text = wrapper.find('[data-testid="import-result"]').text();
            expect(text).toContain('31 nodes');
            expect(text).toContain('29 section mappings');
            expect(text).toContain('1 position title skipped');
            expect(text).toContain('Section Leader 3/2/C/1-7');
        });

        it('reports existing mappings that now disagree with Cav', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, importResult: { unitsCreated: 0, mappingsCreated: 0, mappingsDiffering: 2, skippedTitles: [] } },
            });

            expect(wrapper.find('[data-testid="import-result"]').text()).toContain('2 existing mappings point at a different section');
        });

        // Story 1.16 adds command-billet mapping counts to the same flash. They appear only when
        // the server sends them, so this page works on either side of that change.
        it('reports command-billet mappings created, repointed and removed when the server sends them', () => {
            const wrapper = mount(OrgStructure, {
                props: {
                    units: UNITS,
                    isAdmin: true,
                    importResult: {
                        unitsCreated: 0,
                        mappingsCreated: 0,
                        skippedTitles: [],
                        commandMappingsCreated: 4,
                        commandMappingsRepointed: 1,
                        commandMappingsRemoved: 2,
                    },
                },
            });

            const text = wrapper.find('[data-testid="import-result"]').text();
            expect(text).toContain('4 command mappings created');
            expect(text).toContain('1 repointed');
            expect(text).toContain('2 removed');
        });

        it('says nothing about command mappings when the server does not send them', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, importResult: { unitsCreated: 1, mappingsCreated: 1, skippedTitles: [] } },
            });

            expect(wrapper.find('[data-testid="import-result"]').text()).not.toContain('command');
        });

        it('falls back to the first server error when there is no import key', async () => {
            postSpy = vi.fn((url, data, { onError, onFinish }) => {
                onError({ other: 'Something else went wrong.' });
                onFinish();
            });
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true } });

            await wrapper.find('[data-testid="import-from-cav"]').trigger('click');

            expect(wrapper.find('[data-testid="import-error"]').text()).toBe('Something else went wrong.');
        });

        it('omits the skipped line when nothing was skipped', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, importResult: { unitsCreated: 0, mappingsCreated: 0, mappingsDiffering: 0, skippedTitles: [] } },
            });

            expect(wrapper.find('[data-testid="import-result"]').text()).not.toContain('skipped');
            expect(wrapper.find('[data-testid="import-result"]').text()).not.toContain('different section');
        });
    });

    it('writes the trooper count in the singular for one trooper and the plural otherwise', () => {
        const units = [
            { id: 1, parent_id: null, type: 'regiment', name: '7th Cavalry Regiment', callsign: null, path: '1/', trooper_count: 1 },
            { id: 2, parent_id: 1, type: 'battalion', name: '2nd Battalion', callsign: '2-7', path: '1/2/', trooper_count: 0 },
        ];

        const text = mount(OrgStructure, { props: { units, isAdmin: false } }).text();

        expect(text).toContain('1 trooper');
        expect(text).not.toContain('1 troopers');
        expect(text).toContain('0 troopers');
    });

    // Story 2.6: troopers the roster sync could not place into a section.
    describe('not yet placed panel', () => {
        const trooper = (overrides = {}) => ({
            id: 1,
            cav_id: 3099,
            username: 'Hollis.D',
            rank: 'Private',
            mos: '11B',
            billet: 'Rifleman, 2nd Platoon',
            position_id: 412,
            ...overrides,
        });

        it('lists each unplaced trooper with name, rank, MOS and the billet Cav reported', () => {
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper()] } });

            const row = wrapper.find('[data-testid="unplaced-trooper"]');
            expect(row.text()).toContain('Private');
            expect(row.text()).toContain('Hollis.D');
            expect(row.text()).toContain('11B');
            expect(row.text()).toContain('Rifleman, 2nd Platoon');
            expect(wrapper.find('[data-testid="unplaced-count"]').text()).toContain('1 synced trooper not yet placed');
        });

        it('counts several troopers in the plural', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper(), trooper({ id: 2, cav_id: 3100, username: 'Vega.R' })] },
            });

            expect(wrapper.findAll('[data-testid="unplaced-trooper"]')).toHaveLength(2);
            expect(wrapper.find('[data-testid="unplaced-count"]').text()).toContain('2 synced troopers not yet placed');
        });

        it('falls back to the Cav id and the billet id when a name or title was not reported', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper({ username: null, billet: null })] },
            });

            expect(wrapper.find('[data-testid="unplaced-trooper"]').text()).toContain('Unknown (Cav #3099)');
            expect(wrapper.find('[data-testid="unplaced-trooper"]').text()).toContain('Billet not mapped (position 412)');
        });

        it('says so when no billet was reported at all', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper({ billet: null, position_id: null })] },
            });

            expect(wrapper.find('[data-testid="unplaced-trooper"]').text()).toContain('No billet reported');
        });

        it('shows an explicit empty state, not a blank panel, when everyone is placed', () => {
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true, unplacedTroopers: [] } });

            expect(wrapper.find('[data-testid="unplaced-panel"]').text()).toContain('All synced troopers are placed');
            expect(wrapper.findAll('[data-testid="unplaced-trooper"]')).toHaveLength(0);
        });

        it('explains why everyone is listed while the chain of command is still empty', () => {
            const wrapper = mount(OrgStructure, { props: { units: [], isAdmin: true, unplacedTroopers: [trooper()] } });

            expect(wrapper.find('[data-testid="unplaced-hint"]').text()).toContain('Import it from the Cav roster first');
        });

        it('leaves the hint out once the chain of command exists', () => {
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper()] } });

            expect(wrapper.find('[data-testid="unplaced-hint"]').exists()).toBe(false);
        });

        it('shows a billet id of zero rather than claiming no billet was reported', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper({ billet: null, position_id: 0 })] },
            });

            expect(wrapper.find('[data-testid="unplaced-trooper"]').text()).toContain('Billet not mapped (position 0)');
        });

        it('reports the true total and that the list is capped when more exist than were sent', () => {
            const wrapper = mount(OrgStructure, {
                props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper()], unplacedTotal: 250 },
            });

            expect(wrapper.find('[data-testid="unplaced-count"]').text()).toContain('250 synced troopers not yet placed');
            expect(wrapper.find('[data-testid="unplaced-capped"]').text()).toContain('Showing the first 1');
        });

        it('shows the full billet on hover through a title', () => {
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: true, unplacedTroopers: [trooper()] } });

            expect(wrapper.find('[data-testid="unplaced-trooper"] [title]').attributes('title')).toBe('Rifleman, 2nd Platoon');
        });

        // Story 2.7: placing an unplaced trooper into a section from their chip.
        describe('assigning a trooper', () => {
            const WITH_SECTIONS = [
                ...UNITS,
                { id: 3, parent_id: 2, type: 'company', name: 'Alpha Company', callsign: 'A/2-7', path: '1/2/3/', trooper_count: 0 },
                { id: 11, parent_id: 3, type: 'section', name: '2nd Section', callsign: '2/1/A/2-7', path: '1/2/3/11/', trooper_count: 0 },
                { id: 4, parent_id: 3, type: 'section', name: '1st Section', callsign: '1/1/A/2-7', path: '1/2/3/4/', trooper_count: 0 },
            ];

            const mountAdmin = (props = {}) =>
                mount(OrgStructure, {
                    props: { units: WITH_SECTIONS, isAdmin: true, unplacedTroopers: [trooper(), trooper({ id: 2, cav_id: 3100, username: 'Vega.R' })], ...props },
                });

            const open = async (wrapper, index = 0) => {
                await wrapper.findAll('[data-testid="assign-trooper"]')[index].trigger('click');
            };

            it('offers an Assign control on every chip', () => {
                expect(mountAdmin().findAll('[data-testid="assign-trooper"]')).toHaveLength(2);
            });

            it('disables Assign, and says why, while the tree has no sections', () => {
                const wrapper = mountAdmin({ units: UNITS });

                const button = wrapper.find('[data-testid="assign-trooper"]');
                expect(button.attributes('disabled')).toBeDefined();
                expect(wrapper.find('[data-testid="assign-disabled-reason"]').text()).toContain('no sections');
            });

            it('opens a form that lists only sections, in chain-of-command order', async () => {
                const wrapper = mountAdmin();
                await open(wrapper);

                const options = wrapper.findAll('[data-testid="assign-section"] option').map((option) => option.text());
                expect(options).toEqual(['Select a section', '1/1/A/2-7 · 1st Section (2nd Battalion / Alpha Company)', '2/1/A/2-7 · 2nd Section (2nd Battalion / Alpha Company)']);
            });

            it('keeps only one form open at a time', async () => {
                const wrapper = mountAdmin();
                await open(wrapper, 0);
                // The open chip drops its Assign button, so the only one left belongs to the second chip.
                await open(wrapper, 0);

                expect(wrapper.findAll('[data-testid="assign-form"]')).toHaveLength(1);
                expect(wrapper.findAll('[data-testid="assign-trooper"]')).toHaveLength(1);
            });

            it('cannot be submitted until a section and a reason are given', async () => {
                const wrapper = mountAdmin();
                await open(wrapper);
                const submit = () => wrapper.find('[data-testid="assign-submit"]');
                expect(submit().attributes('disabled')).toBeDefined();

                await wrapper.find('[data-testid="assign-section"]').setValue('4');
                expect(submit().attributes('disabled')).toBeDefined();

                await wrapper.find('[data-testid="assign-reason"]').setValue('   ');
                expect(submit().attributes('disabled')).toBeDefined();

                await wrapper.find('[data-testid="assign-reason"]').setValue('Confirmed by the section leader');
                expect(submit().attributes('disabled')).toBeUndefined();
            });

            it('posts the section and the trimmed reason to the placement route', async () => {
                postSpy = vi.fn();
                const wrapper = mountAdmin();
                await open(wrapper);
                await wrapper.find('[data-testid="assign-section"]').setValue('4');
                await wrapper.find('[data-testid="assign-reason"]').setValue('  Confirmed by the section leader  ');

                await wrapper.find('[data-testid="assign-form"]').trigger('submit');

                expect(postSpy).toHaveBeenCalledWith(
                    '/settings/org-structure/troopers/1/placement',
                    { section_id: 4, reason: 'Confirmed by the section leader' },
                    expect.objectContaining({ preserveScroll: true }),
                );
            });

            it('closes the form once the placement succeeds', async () => {
                postSpy = vi.fn((url, data, { onSuccess, onFinish }) => {
                    onSuccess();
                    onFinish();
                });
                const wrapper = mountAdmin();
                await open(wrapper);
                await wrapper.find('[data-testid="assign-section"]').setValue('4');
                await wrapper.find('[data-testid="assign-reason"]').setValue('Confirmed');

                await wrapper.find('[data-testid="assign-form"]').trigger('submit');

                expect(wrapper.find('[data-testid="assign-form"]').exists()).toBe(false);
                expect(toastSuccess).toHaveBeenCalledWith('Trooper placed');
            });

            it('says so, inside the form, when the server answers 403 rather than a validation error', async () => {
                let onHttpException;
                postSpy = vi.fn((url, data, options) => {
                    onHttpException = options.onHttpException;
                });
                const wrapper = mountAdmin();
                await open(wrapper);
                await wrapper.find('[data-testid="assign-section"]').setValue('4');
                await wrapper.find('[data-testid="assign-reason"]').setValue('Confirmed');
                await wrapper.find('[data-testid="assign-form"]').trigger('submit');

                expect(onHttpException({ status: 403 })).toBe(false);
                await wrapper.vm.$nextTick();

                expect(wrapper.find('[data-testid="assign-error"]').text()).toBe('Only an Admin can place a trooper.');
            });

            it('shows the server error and keeps the form open when the placement is refused', async () => {
                postSpy = vi.fn((url, data, { onError, onFinish }) => {
                    onError({ placement: 'Trooper 1 is already placed in a section.' });
                    onFinish();
                });
                const wrapper = mountAdmin();
                await open(wrapper);
                await wrapper.find('[data-testid="assign-section"]').setValue('4');
                await wrapper.find('[data-testid="assign-reason"]').setValue('Confirmed');

                await wrapper.find('[data-testid="assign-form"]').trigger('submit');

                expect(wrapper.find('[data-testid="assign-error"]').text()).toBe('Trooper 1 is already placed in a section.');
                expect(wrapper.find('[data-testid="assign-form"]').exists()).toBe(true);
            });

            it('falls back to a field error, then to a generic message', async () => {
                postSpy = vi.fn((url, data, { onError, onFinish }) => {
                    onError({ reason: 'The reason field is required.' });
                    onFinish();
                });
                const wrapper = mountAdmin();
                await open(wrapper);
                await wrapper.find('[data-testid="assign-section"]').setValue('4');
                await wrapper.find('[data-testid="assign-reason"]').setValue('Confirmed');
                await wrapper.find('[data-testid="assign-form"]').trigger('submit');
                expect(wrapper.find('[data-testid="assign-error"]').text()).toBe('The reason field is required.');

                postSpy = vi.fn((url, data, { onError, onFinish }) => {
                    onError({});
                    onFinish();
                });
                await wrapper.find('[data-testid="assign-form"]').trigger('submit');
                expect(wrapper.find('[data-testid="assign-error"]').text()).toContain('The placement failed');
            });

            it('closes without posting when cancelled', async () => {
                postSpy = vi.fn();
                const wrapper = mountAdmin();
                await open(wrapper);

                await wrapper.find('[data-testid="assign-cancel"]').trigger('click');

                expect(wrapper.find('[data-testid="assign-form"]').exists()).toBe(false);
                expect(postSpy).not.toHaveBeenCalled();
            });
        });

        it('is absent when the server withheld the list', () => {
            const wrapper = mount(OrgStructure, { props: { units: UNITS, isAdmin: false, unplacedTroopers: null } });

            expect(wrapper.find('[data-testid="unplaced-panel"]').exists()).toBe(false);
        });
    });
});
