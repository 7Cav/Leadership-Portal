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
        key: 'title:regimental deputy chief of staff',
        positionId: 9015,
        acknowledged: false,
        title: 'Regimental Deputy Chief of Staff',
        units: [
            { unitId: 3, positionId: 9015, source: 'admin', label: 'DCOS1' },
            { unitId: 4, positionId: 9016, source: 'admin', label: 'DCOS2' },
        ],
    },
];
const PLACED = {
    kind: 'rule',
    key: 'rule:196',
    positionId: 196,
    acknowledged: false,
    title: 'Commander A/1-7',
    units: [
        { unitId: 11, positionId: 196, source: 'admin', label: 'B/1-7' },
        { unitId: 10, positionId: 196, source: 'command', label: 'A/1-7' },
    ],
};

async function fillReason(dialog, text) {
    const reason = dialog.querySelector('textarea');
    reason.value = text;
    reason.dispatchEvent(new Event('input'));
    await flushPromises();
}

const squash = (text) => text.replace(/\s+/g, ' ').trim();
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

    it('says two billets share one name, why it matters, and offers Fine as it is and each unit', async () => {
        wrapper = mount(BilletNotices, { props: { conflicts: CONFLICTS } });
        const row = wrapper.find('[data-testid="billet-conflict"]');

        expect(row.find('[data-testid="billet-conflict-heading"]').text()).toBe('Same billet name in two units');
        expect(row.find('[data-testid="billet-conflict-text"]').text()).toBe(
            'Two Cav billets are both called "Regimental Deputy Chief of Staff": one in DCOS1, one in DCOS2.',
        );
        expect(row.text()).toContain('Nobody is placed wrong, but rosters and reports show only the name, so people may mix them up.');
        expect(row.findAll('button').map((button) => button.text())).toEqual(['Fine as it is', 'Open DCOS1', 'Open DCOS2']);
        expect(row.text()).not.toMatch(/import rules|the sync/i);

        await row.findAll('[data-testid="billet-conflict-open"]')[1].trigger('click');
        expect(wrapper.emitted('select-unit')).toEqual([[4]]);
    });

    it('marks two billets of one name fine with a reason', async () => {
        wrapper = mount(BilletNotices, { props: { conflicts: CONFLICTS }, attachTo: document.body });
        await wrapper.find('[data-testid="billet-conflict-fine"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[data-testid="billet-resolve-dialog"]');
        expect(dialog.textContent).toContain('Mark as fine?');
        expect(dialog.textContent).toContain('"Regimental Deputy Chief of Staff" stops being flagged until the units its billets are in change.');
        expect(dialog.textContent).toContain('Why is this fine?');
        expect(dialog.querySelector('textarea').placeholder).toBe('Two deputies hold this title, one in each group');
        const confirm = dialog.querySelector('[data-testid="billet-resolve-dialog-confirm"]');
        // No reason, not marked fine.
        expect(confirm.disabled).toBe(true);

        await fillReason(dialog, 'Both Deputy Chiefs of Staff hold this title');
        confirm.click();

        expect(post).toHaveBeenCalledWith(
            '/settings/org-structure/billets/9015/acknowledge',
            { kind: 'title', reason: 'Both Deputy Chiefs of Staff hold this title' },
            expect.any(Object),
        );
    });

    it('says one billet is placed in two units, where its holder is, and offers to keep it in either', () => {
        wrapper = mount(BilletNotices, { props: { conflicts: [PLACED] } });
        const row = wrapper.find('[data-testid="billet-conflict"]');

        expect(row.find('[data-testid="billet-conflict-heading"]').text()).toBe('Billet placed in two units');
        expect(squash(row.find('[data-testid="billet-conflict-text"]').text())).toBe(
            'Commander A/1-7 is set up in both B/1-7 placed by Admin and A/1-7 automatic. Its holder can only be placed in one, and is in B/1-7 now.',
        );
        expect(row.findAll('button').map((button) => button.text())).toEqual(['Keep in B/1-7', 'Keep in A/1-7']);
    });

    it('keeps a billet in the Admin unit by marking it fine, the reason filled in', async () => {
        wrapper = mount(BilletNotices, { props: { conflicts: [PLACED] }, attachTo: document.body });
        await wrapper.findAll('[data-testid="billet-conflict-keep"]')[0].trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[data-testid="billet-resolve-dialog"]');
        expect(dialog.textContent).toContain('Keep in B/1-7?');
        expect(dialog.textContent).toContain('Commander A/1-7 stays in B/1-7 and stops being flagged until its units change.');
        expect(dialog.querySelector('textarea').value).toBe('Kept in B/1-7');
        dialog.querySelector('[data-testid="billet-resolve-dialog-confirm"]').click();

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/196/acknowledge', { kind: 'rule', reason: 'Kept in B/1-7' }, expect.any(Object));
    });

    it('keeps a billet in its automatic unit by removing the Admin placement', async () => {
        wrapper = mount(BilletNotices, { props: { conflicts: [PLACED] }, attachTo: document.body });
        await wrapper.findAll('[data-testid="billet-conflict-keep"]')[1].trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[data-testid="billet-resolve-dialog"]');
        expect(dialog.textContent).toContain("This removes the Admin's placement in B/1-7. The holder moves to A/1-7 on the next roster sync.");
        expect(dialog.querySelector('textarea').value).toBe('Kept in A/1-7');
        await fillReason(dialog, 'Back to Alpha');
        dialog.querySelector('[data-testid="billet-resolve-dialog-confirm"]').click();

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/196/unassign', { reason: 'Back to Alpha' }, expect.any(Object));
    });

    it('offers no Keep for a commanded unit when a rule places the billet elsewhere', () => {
        const three = {
            ...PLACED,
            units: [
                { unitId: 11, positionId: 196, source: 'admin', label: 'B/1-7' },
                { unitId: 12, positionId: 196, source: 'rule', label: 'C/1-7' },
                { unitId: 10, positionId: 196, source: 'command', label: 'A/1-7' },
            ],
        };
        wrapper = mount(BilletNotices, { props: { conflicts: [three] } });
        const row = wrapper.find('[data-testid="billet-conflict"]');

        expect(row.find('[data-testid="billet-conflict-heading"]').text()).toBe('Billet placed in several units');
        expect(squash(row.find('[data-testid="billet-conflict-text"]').text())).toContain('is set up in B/1-7 placed by Admin, C/1-7 automatic and A/1-7 automatic.');
        expect(row.findAll('button').map((button) => button.text())).toEqual(['Keep in B/1-7', 'Keep in C/1-7']);
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
        await fillReason(dialog, 'Not a unit billet');
        dialog.querySelector('[data-testid="billet-leave-dialog-confirm"]').click();

        expect(post).toHaveBeenCalledWith('/settings/org-structure/billets/781/leave-unplaced', { reason: 'Not a unit billet' }, expect.any(Object));
    });
});
