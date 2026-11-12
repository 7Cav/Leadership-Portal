import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import BilletNotices from '../BilletNotices.vue';
import { chooseSearch } from '@/testing/searchSelect';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), router: { post: (...args) => post(...args) } }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const RENAMED = [{ positionId: 202, title: 'ASL 1/1/A/1-7 (Acting)', renamedFrom: 'Assistant Section Leader 1/1/A/1-7', unitId: 10, unitLabel: '1/1/A/1-7' }];
const NEW = [
    { positionId: 952, title: 'War of Rights Start-Up', group: 'Start-Up Games', holders: 2 },
    { positionId: 781, title: 'Assistant Section Leader Star Citizen Start-Up', group: 'Start-Up Games', holders: 0 },
];
const CONFLICTS = [
    {
        kind: 'title',
        title: 'Regimental Deputy Chief of Staff',
        units: [
            { unitId: 3, positionId: 9015, source: 'admin', label: 'DCOS1' },
            { unitId: 4, positionId: 9016, source: 'admin', label: 'DCOS2' },
        ],
        uses: 'The sync places each holder by their own billet.',
    },
];
const UNITS = [{ value: 10, label: 'A/1-7 · Alpha Company' }];

describe('BilletNotices', () => {
    let wrapper;
    beforeEach(() => post.mockReset());
    afterEach(() => wrapper?.unmount());

    it('stays hidden while no billet waits', () => {
        wrapper = mount(BilletNotices, { props: {} });
        expect(wrapper.find('[data-testid="billet-notices"]').exists()).toBe(false);
    });

    it('sums every billet waiting, and lists renames with old and new name and their unit', async () => {
        wrapper = mount(BilletNotices, { props: { renamed: RENAMED, conflicts: CONFLICTS, newBillets: NEW, unitOptions: UNITS } });

        expect(wrapper.find('h2').text()).toBe('4 billets need an Admin');
        const renamed = wrapper.find('[data-testid="billet-renamed"]');
        expect(renamed.text()).toContain('Assistant Section Leader 1/1/A/1-7 → ASL 1/1/A/1-7 (Acting)');
        expect(renamed.text()).toContain('in 1/1/A/1-7');

        await renamed.find('button').trigger('click');
        expect(wrapper.emitted('select-unit')).toEqual([[10]]);

        await wrapper.find('[data-testid="billet-renamed-confirm"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/202/confirm-name', {}, expect.any(Object));
    });

    it('names both units of a billet in two at once', () => {
        wrapper = mount(BilletNotices, { props: { conflicts: CONFLICTS } });

        expect(wrapper.find('[data-testid="billet-conflict"]').text()).toBe(
            'Regimental Deputy Chief of Staff is in DCOS1 (placed by an Admin), DCOS2 (placed by an Admin). The sync places each holder by their own billet.',
        );
    });

    it('assigns a new billet to a unit with a reason', async () => {
        wrapper = mount(BilletNotices, { props: { newBillets: NEW, unitOptions: UNITS }, attachTo: document.body });
        const rows = wrapper.findAll('[data-testid="billet-new"]');
        expect(rows[0].text()).toContain('2 holders');
        expect(rows[1].text()).toContain('vacant');

        await rows[0].find('[data-testid="billet-new-assign"]').trigger('click');
        await chooseSearch(wrapper, 'billet-new-form-pick', 10);
        await wrapper.find('[data-testid="billet-new-form-reason"]').setValue('Runs out of Alpha');
        await wrapper.find('[data-testid="billet-new-form"]').trigger('submit');

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/952/assign', { unit_id: 10, reason: 'Runs out of Alpha' }, expect.any(Object));
    });

    it('leaves a new billet unplaced with a reason', async () => {
        wrapper = mount(BilletNotices, { props: { newBillets: NEW, unitOptions: UNITS }, attachTo: document.body });
        await wrapper.findAll('[data-testid="billet-new-leave"]')[1].trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[data-testid="billet-leave-dialog"]');
        const reason = dialog.querySelector('textarea');
        reason.value = 'Not a unit billet';
        reason.dispatchEvent(new Event('input'));
        await flushPromises();
        dialog.querySelector('[data-testid="billet-leave-dialog-confirm"]').click();

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/781/leave-unplaced', { reason: 'Not a unit billet' }, expect.any(Object));
    });
});
