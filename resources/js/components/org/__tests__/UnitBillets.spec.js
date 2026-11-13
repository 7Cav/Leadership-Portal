import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import UnitBillets from '../UnitBillets.vue';
import { chooseSearch, searchLabels } from '@/testing/searchSelect';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), router: { post: (...args) => post(...args) } }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const BILLETS = [
    { positionId: 201, title: 'Section Leader 1/1/A/1-7', renamedFrom: null, holders: 1, source: 'rule', rule: 'line billet in A/1-7', movable: true, removable: false },
    { positionId: 952, title: 'War of Rights Start-Up', renamedFrom: 'WoR Start-Up', holders: 0, source: 'admin', rule: null, movable: true, removable: true },
    { positionId: 196, title: 'Commander A/1-7', renamedFrom: null, holders: 2, source: 'command', rule: null, movable: true, removable: false },
];
const CATALOG = [
    { positionId: 201, title: 'Section Leader 1/1/A/1-7', group: 'A/1-7', unitId: 10 },
    { positionId: 780, title: 'Section Leader Star Citizen Start-Up', group: 'Start-Up Games', unitId: null },
];
const UNITS = [
    { value: 10, label: '1/1/A/1-7 · 1st Section' },
    { value: 11, label: '2/1/A/1-7 · 2nd Section' },
];

function mountList(props = {}) {
    return mount(UnitBillets, { props: { unitId: 10, billets: BILLETS, catalog: CATALOG, unitOptions: UNITS, ...props }, attachTo: document.body });
}

describe('UnitBillets', () => {
    let wrapper;
    beforeEach(() => post.mockReset());
    afterEach(() => wrapper?.unmount());

    it('opens to each billet with its holders and why it lands here', async () => {
        wrapper = mountList();
        expect(wrapper.find('[data-testid="unit-billets-toggle"]').attributes('aria-expanded')).toBe('false');

        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');

        const rows = wrapper.findAll('[data-testid="unit-billet"]');
        expect(rows).toHaveLength(3);
        expect(rows[0].text()).toContain('1 holder');
        expect(rows.map((row) => row.find('[data-testid="unit-billet-source"]').text())).toEqual(['Rule: line billet in A/1-7', 'Placed by an Admin', 'Commands this unit']);
        expect(rows[1].text()).toContain('vacant');
        // Only an Admin's placement can be removed.
        expect(rows.map((row) => row.find('[data-testid="unit-billet-remove"]').exists())).toEqual([false, true, false]);
    });

    it('shows a rename with old and new name and confirms it', async () => {
        wrapper = mountList();
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="unit-billet-renamed"]').text()).toContain('WoR Start-Up → War of Rights Start-Up');
        await wrapper.find('[data-testid="unit-billet-confirm"]').trigger('click');

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/952/confirm-name', {}, expect.any(Object));
    });

    it('moves a billet to another unit with a reason, never offering the unit it is in', async () => {
        wrapper = mountList();
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');
        await wrapper.find('[data-testid="unit-billet-move"]').trigger('click');

        expect(searchLabels(wrapper, 'unit-billet-move-form-pick')).toEqual(['2/1/A/1-7 · 2nd Section']);
        await chooseSearch(wrapper, 'unit-billet-move-form-pick', 11);
        await wrapper.find('[data-testid="unit-billet-move-form-reason"]').setValue('Cross-trained');
        await wrapper.find('[data-testid="unit-billet-move-form"]').trigger('submit');

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/201/assign', { unit_id: 11, reason: 'Cross-trained' }, expect.any(Object));
    });

    it('adds a billet from the Cav list, and asks before moving one an Admin placed elsewhere', async () => {
        wrapper = mountList();
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');
        await wrapper.find('[data-testid="unit-billet-add"]').trigger('click');

        // A billet already here is not offered.
        expect(searchLabels(wrapper, 'unit-billet-add-form-pick')).toEqual(['Section Leader Star Citizen Start-Up (Start-Up Games)']);
        await chooseSearch(wrapper, 'unit-billet-add-form-pick', 780);
        await wrapper.find('[data-testid="unit-billet-add-form-reason"]').setValue('Star Citizen runs here');
        await wrapper.find('[data-testid="unit-billet-add-form"]').trigger('submit');

        expect(post).toHaveBeenLastCalledWith('/settings/org-structure/billets/780/assign', { unit_id: 10, reason: 'Star Citizen runs here' }, expect.any(Object));

        post.mock.calls.at(-1)[2].onError({ billet_move: 'Section Leader Star Citizen Start-Up is already placed in B/1-7. Move it here instead?' });
        post.mock.calls.at(-1)[2].onFinish();
        await flushPromises();
        expect(wrapper.find('[data-testid="unit-billet-add-form-move"]').text()).toContain('already placed in B/1-7');

        await wrapper.find('[data-testid="unit-billet-add-form-move-confirm"]').trigger('click');
        expect(post).toHaveBeenLastCalledWith('/settings/org-structure/billets/780/assign', { unit_id: 10, reason: 'Star Citizen runs here', confirm_move: true }, expect.any(Object));
    });

    it('warns about a billet in two units, naming them and the unit the sync uses', async () => {
        wrapper = mountList({
            conflicts: [
                {
                    kind: 'rule',
                    title: 'Commander A/1-7',
                    units: [
                        { unitId: 11, positionId: 196, source: 'admin', label: 'B/1-7' },
                        { unitId: 10, positionId: 196, source: 'command', label: 'A/1-7' },
                    ],
                    uses: 'The sync uses B/1-7, where an Admin placed it.',
                },
            ],
        });

        expect(wrapper.find('[data-testid="unit-billets-conflict-icon"]').exists()).toBe(true);
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="unit-billet-conflict"]').text()).toBe(
            'Commander A/1-7 is in B/1-7 (placed by an Admin), A/1-7 (commands it). The sync uses B/1-7, where an Admin placed it.',
        );
    });

    it('marks an acknowledged billet in two units without a warning, and undoes it with a reason', async () => {
        wrapper = mountList({
            conflicts: [
                {
                    kind: 'title',
                    positionId: 9015,
                    acknowledged: true,
                    title: 'Regimental Deputy Chief of Staff',
                    units: [
                        { unitId: 10, positionId: 9015, source: 'admin', label: 'DCOS1' },
                        { unitId: 11, positionId: 9016, source: 'admin', label: 'DCOS2' },
                    ],
                    uses: 'The sync places each holder by their own billet.',
                },
            ],
        });

        expect(wrapper.find('[data-testid="unit-billets-conflict-icon"]').exists()).toBe(false);
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="unit-billet-conflict"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="unit-billet-acknowledged"]').text()).toBe(
            'In two units, acknowledged: Regimental Deputy Chief of Staff, in DCOS1 (placed by an Admin), DCOS2 (placed by an Admin). Undo',
        );

        await wrapper.find('[data-testid="unit-billet-acknowledged-undo"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[data-testid="unit-billet-undo-dialog"]');
        const reason = dialog.querySelector('textarea');
        reason.value = 'One of them is moving';
        reason.dispatchEvent(new Event('input'));
        await flushPromises();
        dialog.querySelector('[data-testid="unit-billet-undo-dialog-confirm"]').click();

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/9015/unacknowledge', { kind: 'title', reason: 'One of them is moving' }, expect.any(Object));
    });

    it('removes an Admin placement with a reason', async () => {
        wrapper = mountList();
        await wrapper.find('[data-testid="unit-billets-toggle"]').trigger('click');
        await wrapper.find('[data-testid="unit-billet-remove"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[data-testid="unit-billet-remove-dialog"]');
        const reason = dialog.querySelector('textarea');
        reason.value = 'Back to the Cav';
        reason.dispatchEvent(new Event('input'));
        await flushPromises();
        dialog.querySelector('[data-testid="unit-billet-remove-dialog-confirm"]').click();

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/952/unassign', { reason: 'Back to the Cav' }, expect.any(Object));
    });
});
