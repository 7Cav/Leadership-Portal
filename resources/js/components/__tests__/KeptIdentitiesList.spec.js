import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import KeptIdentitiesList from '../KeptIdentitiesList.vue';

const routerDelete = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), router: { delete: routerDelete } }));

const KEPT = [
    { id: 7, cavName: 'Atkins.DS', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000011', gamertag: 'Dusty', addedBy: 'Laui.M', addedAt: '2026-11-02T10:00:00Z', conflict: null },
    { id: 8, cavName: 'Atkins.DA', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000012', gamertag: null, addedBy: null, addedAt: null, conflict: 'Another trooper holds this ID on this game.' },
];

afterEach(() => routerDelete.mockReset());

describe('KeptIdentitiesList', () => {
    it('lists each kept identity with its name, game, ID, gamertag, who added it and when, and any conflict', () => {
        const wrapper = mount(KeptIdentitiesList, { props: { kept: KEPT } });
        const row = wrapper.get('[data-testid="kept-row-7"]').text();

        expect(row).toContain('Atkins.DS');
        expect(row).toContain('76561198000000011');
        expect(row).toContain('Dusty');
        expect(row).toContain('Laui.M, 02NOV26');
        expect(wrapper.get('[data-testid="kept-conflict-8"]').text()).toBe('Another trooper holds this ID on this game.');
    });

    it('says so when nothing is kept', () => {
        expect(mount(KeptIdentitiesList, { props: { kept: [] } }).find('[data-testid="kept-empty"]').exists()).toBe(true);
    });

    it('deletes with the optional reason after a confirmation', async () => {
        const wrapper = mount(KeptIdentitiesList, { props: { kept: KEPT } });

        await wrapper.get('[data-testid="kept-delete-7"]').trigger('click');
        await wrapper.get('[data-testid="kept-reason-7"]').setValue('Duplicate');
        await wrapper.get('[data-testid="kept-confirm-7"]').trigger('click');

        expect(routerDelete).toHaveBeenCalledWith('/game-identities/kept/7', expect.objectContaining({ data: { reason: 'Duplicate' } }));
    });
});
