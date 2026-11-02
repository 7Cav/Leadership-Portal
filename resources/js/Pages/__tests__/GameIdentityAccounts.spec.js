import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import GameIdentities from '../GameIdentities.vue';

// #174: a Steam or Epic ID can be linked to every game that asks for it, and the rows that made removed together.

const page = reactive({ url: '/game-identities', props: { viewAs: null } });
const put = vi.fn();
const post = vi.fn();
const routerDelete = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        router: { delete: (...args) => routerDelete(...args) },
        usePage: () => page,
        useForm: (data) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                put: (...args) => put(...args),
                post: (...args) => post(...args),
                clearErrors() {},
                reset() {
                    Object.assign(this, data);
                },
            }),
    };
});

const STEAM = '76561198000000174';
const PLATFORMS = [
    { value: 'hll', label: 'Hell Let Loose', idKinds: ['Steam ID', 'Epic ID'], accounts: { 'Steam ID': 'steam', 'Epic ID': 'epic' }, retired: false },
    { value: 'wardogs', label: 'WARDOGS', idKinds: ['SteamID64'], accounts: { SteamID64: 'steam' }, retired: false },
    { value: 'arma', label: 'Arma Reforger', idKinds: ['Player ID'], accounts: {}, retired: false },
];
const fromAccount = { playerName: 'OrtegaCav', platformId: STEAM, idKind: 'Steam ID', source: 'from_account' };
const trooper = (id, identities = {}) => ({ id, displayName: `Trooper ${id}`, billet: 'Rifleman', section: '1/A', editable: true, identities });
const TROOPERS = [
    trooper(1, { hll: fromAccount, wardogs: { ...fromAccount, idKind: 'SteamID64' }, arma: { playerName: 'O', platformId: 'a1', idKind: 'Player ID', source: 'from_rollcall' } }),
    trooper(2),
];

const open = async (id) => {
    const wrapper = mount(GameIdentities, { props: { platforms: PLATFORMS, troopers: TROOPERS } });
    await wrapper.get('[data-testid="view-everyone"]').trigger('click');
    await wrapper.get(`[data-testid="open-${id}"]`).trigger('click');

    return wrapper;
};

describe('GameIdentities page, shared accounts', () => {
    beforeEach(() => {
        put.mockReset();
        post.mockReset();
        routerDelete.mockReset();
    });

    it('offers Link to every game for a Steam ID, naming the other games it covers', async () => {
        const wrapper = await open(2);

        expect(wrapper.get('[data-testid="every-game-hint"]').text()).toBe('Also links this Steam ID on WARDOGS.');

        await wrapper.get('[data-testid="identity-kind"]').setValue('Epic ID');
        // No other game asks for an Epic ID, so there is nothing more to link.
        expect(wrapper.find('[data-testid="every-game"]').exists()).toBe(false);
    });

    it('does not offer it for a kind entered per game', async () => {
        const wrapper = await open(2);
        await wrapper.get('[data-testid="identity-game"]').setValue('arma');

        expect(wrapper.find('[data-testid="every-game"]').exists()).toBe(false);
    });

    it('posts to the account route when ticked, and saves one game when not', async () => {
        const wrapper = await open(2);

        await wrapper.get('[data-testid="identity-form"]').trigger('submit');
        expect(put).toHaveBeenCalledWith('/game-identities/2/hll', expect.any(Object));

        await wrapper.get('[data-testid="every-game-input"]').setValue(true);
        await wrapper.get('[data-testid="identity-form"]').trigger('submit');
        expect(post).toHaveBeenCalledWith('/game-identities/2/hll/account', expect.any(Object));
    });

    it('shows how each identity was made', async () => {
        const wrapper = await open(1);

        expect(wrapper.get('[data-testid="source-hll"]').text()).toBe('From account');
        expect(wrapper.get('[data-testid="source-arma"]').text()).toBe('From rollcall');
    });

    it('confirms Remove from every game by naming each game, then removes them in one request', async () => {
        const wrapper = await open(1);

        expect(wrapper.find('[data-testid="remove-account-arma"]').exists()).toBe(false);
        await wrapper.get('[data-testid="remove-account-wardogs"]').trigger('click');

        expect(wrapper.get('[data-testid="remove-account-confirm"]').text()).toContain('Remove this ID from Hell Let Loose and WARDOGS? Past attendance is unchanged.');
        expect(routerDelete).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="confirm-remove-account-wardogs"]').trigger('click');
        expect(routerDelete).toHaveBeenCalledWith('/game-identities/1/wardogs/account', expect.any(Object));
    });
});
