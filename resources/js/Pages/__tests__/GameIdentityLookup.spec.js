import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import GameIdentities from '../GameIdentities.vue';

// "Fill in from game server" and the gamertag: shown beside the ID with where it came from, typed on the form for a
// console or Epic kind, and filled in from the game server's answer.

const page = reactive({ url: '/game-identities', props: { viewAs: null } });
const routerPost = vi.fn();
const postJson = vi.fn();

vi.mock('@/lib/postJson', () => ({ postJson: (...args) => postJson(...args) }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        router: { delete: vi.fn(), post: (...args) => routerPost(...args) },
        usePage: () => page,
        useForm: (data) => reactive({ ...data, errors: {}, processing: false, put: vi.fn(), post: vi.fn(), transform() {}, clearErrors() {}, reset() {} }),
    };
});

const PLATFORMS = [
    { value: 'hll', label: 'Hell Let Loose', idKinds: ['Steam ID', 'Epic ID', 'Xbox ID', 'PlayStation ID'], retired: false, lookup: true },
    { value: 'arma', label: 'Arma Reforger', idKinds: ['Player ID'], retired: false, lookup: false },
];
const XBOX = 'e677aaaabbbbccccddddeeeeffff0001';
const trooper = (id, name, identities = {}) => ({ id, displayName: name, billet: 'Rifleman', section: '1/A', editable: true, identities });
const TROOPERS = [
    trooper(1, 'SGT Ortega.M', { hll: { platformId: XBOX, idKind: 'Xbox ID', source: 'typed', gamertag: 'Cain J', gamertagFromServer: true } }),
    trooper(2, 'PFC Dube.R', { hll: { platformId: XBOX.replace('1', '2'), idKind: 'Xbox ID', source: 'typed', gamertag: null, gamertagFromServer: false } }),
    trooper(3, 'PVT Novak.E'),
];

const open = async (id) => {
    const wrapper = mount(GameIdentities, { props: { platforms: PLATFORMS, troopers: TROOPERS } });
    await wrapper.get('[data-testid="view-everyone"]').trigger('click');
    // Add identity when a game is missing, else the name, which shows their identities.
    await (wrapper.find(`[data-testid="open-${id}"]`).exists() ? wrapper.get(`[data-testid="open-${id}"]`) : wrapper.get(`[data-testid="name-${id}"]`)).trigger('click');

    return wrapper;
};

describe('GameIdentities page, gamertag and game server lookup', () => {
    beforeEach(() => {
        routerPost.mockReset();
        postJson.mockReset();
    });

    it('shows a gamertag the game server gave with that label, and offers no fill when there is nothing to fill', async () => {
        const wrapper = await open(1);

        expect(wrapper.get('[data-testid="gamertag-hll"]').text()).toBe('Gamertag (from game server): Cain J');
        expect(wrapper.find('[data-testid="fill-hll"]').exists()).toBe(false);
    });

    it('offers Fill in from game server on a console identity without a gamertag, and posts it', async () => {
        const wrapper = await open(2);

        await wrapper.get('[data-testid="fill-hll"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/game-identities/2/hll/fill', {}, expect.any(Object));
    });

    it('asks for a gamertag only for a console or Epic kind', async () => {
        const wrapper = await open(3);
        expect(wrapper.find('[data-testid="identity-gamertag"]').exists()).toBe(false);

        await wrapper.get('[data-testid="identity-kind"]').setValue('PlayStation ID');

        expect(wrapper.find('[data-testid="identity-gamertag"]').exists()).toBe(true);
    });

    it('fills the kind and gamertag in from the game server after a 32-character ID', async () => {
        postJson.mockResolvedValue({ ok: true, data: { status: 'found', kind: 'Xbox ID', platform: 'xbl', name: 'Cain J' } });
        const wrapper = await open(3);
        expect(wrapper.find('[data-testid="identity-lookup"]').exists()).toBe(false);

        await wrapper.get('[data-testid="identity-id"]').setValue(XBOX);
        await wrapper.get('[data-testid="identity-lookup"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(postJson).toHaveBeenCalledWith('/game-identities/3/hll/lookup', { platform_id: XBOX });
        expect(wrapper.get('[data-testid="identity-kind"]').element.value).toBe('Xbox ID');
        expect(wrapper.get('[data-testid="identity-gamertag"]').element.value).toBe('Cain J');
        expect(wrapper.get('[data-testid="identity-lookup-note"]').text()).toBe('Xbox ID (from game server), gamertag Cain J.');
    });

    it('says when no server has seen the player', async () => {
        postJson.mockResolvedValue({ ok: true, data: { status: 'not_seen', kind: null, platform: null, name: null } });
        const wrapper = await open(3);

        await wrapper.get('[data-testid="identity-id"]').setValue(XBOX);
        await wrapper.get('[data-testid="identity-lookup"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="identity-lookup-note"]').text()).toBe('Not seen on our servers. Choose the kind yourself.');
    });
});
