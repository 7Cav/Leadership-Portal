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
