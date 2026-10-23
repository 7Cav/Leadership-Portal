import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import UnmatchedDrawer from '../UnmatchedDrawer.vue';

enableAutoUnmount(afterEach);
const post = vi.fn();
vi.mock('@/lib/postJson', () => ({ postJson: (...args) => post(...args) }));

const PLAYERS = [
    { platform: 'hll', platformLabel: 'Hell Let Loose', playerName: 'Dubes', platformId: '7656119000000001' },
    { platform: 'hll', platformLabel: 'Hell Let Loose', playerName: 'RandoGuy88', platformId: '7656119000000002' },
];
const TROOPERS = [
    { id: 1, name: 'Dube.R', displayName: 'PFC Dube.R' },
    { id: 2, name: 'Nakamura.T', displayName: 'PVT Nakamura.T' },
];

const factory = (props = {}) =>
    mount(UnmatchedDrawer, { props: { sectionId: 12, week: '2026-10-03', players: PLAYERS, troopers: TROOPERS, ...props } });
const card = (wrapper, id) => wrapper.find(`[data-testid="unmatched-${id}"]`);

describe('UnmatchedDrawer', () => {
    beforeEach(() => post.mockReset());

    it('lists each player with name, platform id and a picker that starts on nobody', () => {
        const wrapper = factory();

        expect(wrapper.text()).toContain('Not matched to a trooper');
        expect(wrapper.text()).toContain('2 seen on the server');
        const first = card(wrapper, '7656119000000001');
        expect(first.find('[data-testid="unmatched-name"]').text()).toBe('Dubes');
        expect(first.find('[data-testid="unmatched-id"]').text()).toBe('7656119000000001');
        expect(first.find('select').element.value).toBe('');
        expect(first.find('[data-testid="unmatched-link"]').attributes('disabled')).toBeDefined();
    });

    it('suggests by name closeness but never picks a trooper', () => {
        const wrapper = factory();
        const first = card(wrapper, '7656119000000001');

        expect(first.find('optgroup[label="Closest by name"]').text()).toContain('PFC Dube.R');
        expect(first.find('select').element.value).toBe('');
        expect(card(wrapper, '7656119000000002').find('optgroup[label="Closest by name"]').exists()).toBe(false);
    });

    it('enables Link only once a trooper is chosen, then posts the choice and emits the answer', async () => {
        post.mockResolvedValue({ ok: true, status: 200, data: { marked: true, trooper: { id: 1, status: 'present', source: 'auto' }, atRisk: [] } });
        const wrapper = factory();
        const first = card(wrapper, '7656119000000001');

        await first.find('select').setValue('1');
        expect(first.find('[data-testid="unmatched-link"]').attributes('disabled')).toBeUndefined();
        await first.find('[data-testid="unmatched-link"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/rollcall/12/unmatched/link', { week: '2026-10-03', platform: 'hll', platform_id: '7656119000000001', trooper_id: 1 });
        expect(wrapper.emitted('linked')[0][0]).toMatchObject({ player: PLAYERS[0], marked: true, trooper: { id: 1 } });
    });

    it('runs beforeLink with the chosen trooper first, and holds the link back when it fails', async () => {
        const beforeLink = vi.fn().mockResolvedValue(false);
        const wrapper = factory({ beforeLink });
        const first = card(wrapper, '7656119000000001');

        await first.find('select').setValue('1');
        await first.find('[data-testid="unmatched-link"]').trigger('click');
        await flushPromises();

        expect(beforeLink).toHaveBeenCalledWith(1);
        expect(post).not.toHaveBeenCalled();
        expect(first.find('[data-testid="unmatched-error"]').text()).toContain('the player was not linked');
        expect(first.find('[data-testid="unmatched-link"]').attributes('disabled')).toBeUndefined();
    });

    it('shows a collision inline and keeps the player listed', async () => {
        post.mockResolvedValue({ ok: false, status: 422, data: { message: 'Already linked to PFC Dube.R on Hell Let Loose.' } });
        const wrapper = factory();
        const first = card(wrapper, '7656119000000001');

        await first.find('select').setValue('2');
        await first.find('[data-testid="unmatched-link"]').trigger('click');
        await flushPromises();

        expect(first.find('[data-testid="unmatched-error"]').text()).toBe('Already linked to PFC Dube.R on Hell Let Loose.');
        expect(wrapper.emitted('linked')).toBeUndefined();
    });

    describe('replace or cancel (#176)', () => {
        const HAS_OTHER = {
            ok: false,
            status: 422,
            data: {
                message: 'That trooper is already linked to DubeMain (7656119000000099) on Hell Let Loose. Replace it, or cancel to keep it.',
                reason: 'has_other_identity',
                current: { playerName: 'DubeMain', platformId: '7656119000000099' },
            },
        };
        const LINKED = { ok: true, status: 200, data: { marked: true, trooper: { id: 1, status: 'present', source: 'auto' }, atRisk: [] } };
        const dialog = () => document.body.querySelector('[data-testid="replace-dialog"]');
        let wrapper;

        async function askToReplace() {
            wrapper = mount(UnmatchedDrawer, { props: { sectionId: 12, week: '2026-10-03', players: PLAYERS, troopers: TROOPERS }, attachTo: document.body });
            const first = card(wrapper, '7656119000000001');
            await first.find('select').setValue('1');
            await first.find('[data-testid="unmatched-link"]').trigger('click');
            await flushPromises();

            return first;
        }

        beforeEach(() => (document.body.innerHTML = ''));

        it('asks with both identities named instead of showing the refusal, and overwrites nothing yet', async () => {
            post.mockResolvedValueOnce(HAS_OTHER);
            const first = await askToReplace();

            expect(dialog()).not.toBeNull();
            expect(dialog().querySelector('[data-testid="replace-question"]').textContent.replace(/\s+/g, ' ').trim()).toBe(
                'PFC Dube.R is linked to DubeMain (7656119000000099) on Hell Let Loose. Replace it with Dubes (7656119000000001)?',
            );
            expect(first.find('[data-testid="unmatched-error"]').exists()).toBe(false);
            expect(post).toHaveBeenCalledTimes(1);
            expect(wrapper.emitted('linked')).toBeUndefined();
        });

        it('sends the link again with replace and the ID it showed when the leader chooses Replace, and emits the answer', async () => {
            post.mockResolvedValueOnce(HAS_OTHER).mockResolvedValueOnce(LINKED);
            await askToReplace();

            dialog().querySelector('[data-testid="replace-confirm"]').click();
            await flushPromises();

            expect(post).toHaveBeenLastCalledWith('/rollcall/12/unmatched/link', {
                week: '2026-10-03', platform: 'hll', platform_id: '7656119000000001', trooper_id: 1, replace: true, replacing: '7656119000000099',
            });
            expect(wrapper.emitted('linked')[0][0]).toMatchObject({ player: PLAYERS[0], marked: true, trooper: { id: 1 } });
            expect(dialog()).toBeNull();
        });

        it('asks again with the new ID when the trooper\'s ID changed before Replace landed, and replaces only that one', async () => {
            const CHANGED = {
                ok: false,
                status: 422,
                data: { ...HAS_OTHER.data, message: 'That trooper is already linked to DubeAlt (7656119000000077) on Hell Let Loose. Replace it, or cancel to keep it.', current: { playerName: 'DubeAlt', platformId: '7656119000000077' } },
            };
            post.mockResolvedValueOnce(HAS_OTHER).mockResolvedValueOnce(CHANGED).mockResolvedValueOnce(LINKED);
            const first = await askToReplace();

            dialog().querySelector('[data-testid="replace-confirm"]').click();
            await flushPromises();

            expect(dialog()).not.toBeNull();
            expect(dialog().querySelector('[data-testid="replace-question"]').textContent.replace(/\s+/g, ' ').trim()).toBe(
                'PFC Dube.R is linked to DubeAlt (7656119000000077) on Hell Let Loose. Replace it with Dubes (7656119000000001)?',
            );
            expect(first.find('[data-testid="unmatched-error"]').exists()).toBe(false);
            expect(wrapper.emitted('linked')).toBeUndefined();

            dialog().querySelector('[data-testid="replace-confirm"]').click();
            await flushPromises();

            expect(post).toHaveBeenCalledTimes(3);
            expect(post).toHaveBeenLastCalledWith('/rollcall/12/unmatched/link', {
                week: '2026-10-03', platform: 'hll', platform_id: '7656119000000001', trooper_id: 1, replace: true, replacing: '7656119000000077',
            });
            expect(wrapper.emitted('linked')).toHaveLength(1);
        });

        it('shows the same ID refused again inline rather than asking in a loop', async () => {
            post.mockResolvedValueOnce(HAS_OTHER).mockResolvedValueOnce(HAS_OTHER);
            const first = await askToReplace();

            dialog().querySelector('[data-testid="replace-confirm"]').click();
            await flushPromises();

            expect(dialog()).toBeNull();
            expect(first.find('[data-testid="unmatched-error"]').text()).toBe(HAS_OTHER.data.message);
        });

        it('leaves everything as it was on Cancel', async () => {
            post.mockResolvedValueOnce(HAS_OTHER);
            const first = await askToReplace();

            dialog().querySelector('[data-testid="replace-cancel"]').click();
            await flushPromises();

            expect(dialog()).toBeNull();
            expect(post).toHaveBeenCalledTimes(1);
            expect(wrapper.emitted('linked')).toBeUndefined();
            expect(first.find('[data-testid="unmatched-error"]').exists()).toBe(false);
            expect(first.find('select').element.value).toBe('1');
            expect(first.find('[data-testid="unmatched-link"]').attributes('disabled')).toBeUndefined();
        });

        it('shows a refusal of the replace itself inline, without asking again', async () => {
            post.mockResolvedValueOnce(HAS_OTHER).mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'Already linked to PVT Nakamura.T on Hell Let Loose.' } });
            const first = await askToReplace();

            dialog().querySelector('[data-testid="replace-confirm"]').click();
            await flushPromises();

            expect(dialog()).toBeNull();
            expect(first.find('[data-testid="unmatched-error"]').text()).toBe('Already linked to PVT Nakamura.T on Hell Let Loose.');
        });
    });

    it('asks before dismissing, then posts and emits', async () => {
        post.mockResolvedValue({ ok: true, status: 200, data: { dismissed: true } });
        const wrapper = factory();
        const second = card(wrapper, '7656119000000002');

        await second.find('[data-testid="unmatched-dismiss"]').trigger('click');
        expect(post).not.toHaveBeenCalled();
        await second.find('[data-testid="confirm-dismiss"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/rollcall/12/unmatched/dismiss', { week: '2026-10-03', platform: 'hll', platform_id: '7656119000000002' });
        expect(wrapper.emitted('dismissed')[0]).toEqual([PLAYERS[1]]);
    });

    describe('the dismissed list (#176)', () => {
        const DISMISSED = [{ platform: 'hll', platformLabel: 'Hell Let Loose', playerName: 'Gone', platformId: '7656119000000009' }];

        it('lists this practice\'s dismissed players under a closed disclosure, with the count', () => {
            const wrapper = factory({ dismissed: DISMISSED });
            const list = wrapper.find('[data-testid="dismissed-list"]');

            expect(list.find('summary').text()).toBe('Dismissed (1)');
            expect(list.attributes('open')).toBeUndefined();
            expect(list.find('[data-testid="dismissed-7656119000000009"]').text()).toContain('Gone');
            expect(list.find('[data-testid="dismissed-7656119000000009"]').text()).toContain('7656119000000009');
        });

        it('is not there with nobody dismissed', () => {
            expect(factory().find('[data-testid="dismissed-list"]').exists()).toBe(false);
        });

        it('undoes a dismissal through the rollcall, showing it is busy until it answers', async () => {
            let answer;
            const restore = vi.fn(() => new Promise((resolve) => (answer = resolve)));
            const wrapper = factory({ dismissed: DISMISSED, restore });
            const undo = wrapper.find('[data-testid="dismissed-7656119000000009"] [data-testid="undo-dismiss"]');

            await undo.trigger('click');
            expect(restore).toHaveBeenCalledWith(DISMISSED[0]);
            expect(undo.attributes('disabled')).toBeDefined();
            await undo.trigger('click');
            expect(restore).toHaveBeenCalledTimes(1);

            answer(true);
            await flushPromises();
            expect(undo.attributes('disabled')).toBeUndefined();
        });

        it('stays open with only dismissed players left, and says nobody is waiting', () => {
            const wrapper = factory({ players: [], dismissed: DISMISSED });

            expect(wrapper.text()).toContain('Everyone seen on the server is linked or dismissed');
            expect(wrapper.find('[data-testid="unmatched-link"]').exists()).toBe(false);
        });

        it('offers no undo when not editable', () => {
            const wrapper = factory({ dismissed: DISMISSED, restore: vi.fn(), editable: false });

            expect(wrapper.find('[data-testid="undo-dismiss"]').attributes('disabled')).toBeDefined();
        });
    });

    it('can back out of a dismissal', async () => {
        const wrapper = factory();
        const second = card(wrapper, '7656119000000002');

        await second.find('[data-testid="unmatched-dismiss"]').trigger('click');
        await second.findAll('button').find((b) => b.text() === 'Keep').trigger('click');

        expect(second.find('[data-testid="unmatched-dismiss"]').exists()).toBe(true);
        expect(post).not.toHaveBeenCalled();
    });

    it('is read-only when not editable', async () => {
        const wrapper = factory({ editable: false, disabledReason: 'Read-only while viewing as Ortega.M' });
        const first = card(wrapper, '7656119000000001');

        expect(first.find('select').attributes('disabled')).toBeDefined();
        expect(first.find('[data-testid="unmatched-dismiss"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Read-only while viewing as Ortega.M');
    });
});
