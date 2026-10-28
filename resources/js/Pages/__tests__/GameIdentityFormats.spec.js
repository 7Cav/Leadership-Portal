import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import GameIdentities from '../GameIdentities.vue';
import Games from '../Settings/Games.vue';

// #450: the server sends the shape each checked kind has and which names mix alphabets; the page only shows them.

const page = reactive({ url: '/game-identities', props: { viewAs: null } });

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        router: { delete: vi.fn(), post: vi.fn() },
        usePage: () => page,
        useForm: (data) => reactive({ ...data, errors: {}, processing: false, put: vi.fn(), post: vi.fn(), patch: vi.fn(), clearErrors() {}, reset() {} }),
    };
});

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));

const STEAM_HINT = 'A Steam ID is 17 digits starting 7656119, for example 76561198012345678.';
const PLATFORMS = [
    { value: 'hll', label: 'Hell Let Loose', idKinds: ['Steam ID', 'Epic ID', 'Xbox ID'], idHints: { 'Steam ID': STEAM_HINT, 'Epic ID': 'An Epic ID is 32 characters.' }, retired: false },
    { value: 'arma', label: 'Arma Reforger', idKinds: ['Player ID'], idHints: {}, retired: false },
];
const MARKUP = '<img src=x onerror="window.pwned=1">';
const trooper = (id, name, identities = {}) => ({ id, displayName: name, billet: 'Rifleman', section: '1/A', editable: true, identities });
const TROOPERS = [
    trooper(1, 'SGT Ortega.M', { hll: { playerName: 'Ortеga', platformId: '76561198012345678', idKind: 'Steam ID', mixedScripts: true } }),
    trooper(2, 'PFC Dube.R', { hll: { playerName: MARKUP, platformId: '76561198012345679', idKind: 'Steam ID', mixedScripts: false } }),
];

const open = async (id) => {
    const wrapper = mount(GameIdentities, { props: { platforms: PLATFORMS, troopers: TROOPERS }, attachTo: document.body });
    await wrapper.get('[data-testid="view-everyone"]').trigger('click');
    await wrapper.get(`[data-testid="open-${id}"]`).trigger('click');

    return wrapper;
};

describe('GameIdentities page, ID shapes and names to check', () => {
    it('shows the hint the server sent for the chosen kind, and none for a kind it does not check', async () => {
        const wrapper = await open(1);
        await wrapper.get('[data-testid="identity-game"]').setValue('hll');

        expect(wrapper.get('[data-testid="identity-id-hint"]').text()).toBe(STEAM_HINT);
        expect(wrapper.get('[data-testid="identity-id"]').attributes('aria-describedby')).toBe('identity-id-hint');

        await wrapper.get('[data-testid="identity-kind"]').setValue('Xbox ID');
        expect(wrapper.find('[data-testid="identity-id-hint"]').exists()).toBe(false);

        await wrapper.get('[data-testid="identity-game"]').setValue('arma');
        expect(wrapper.find('[data-testid="identity-id-hint"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('marks a name that mixes alphabets, and only that one', async () => {
        const wrapper = await open(1);

        expect(wrapper.get('[data-testid="check-name-hll"]').text()).toBe('Check this name');
        expect(wrapper.get('[data-testid="row-1"]').text()).toContain('(check this name)');
        expect(wrapper.get('[data-testid="row-2"]').text()).not.toContain('check this name');
        wrapper.unmount();

        const other = await open(2);
        expect(other.find('[data-testid="check-name-hll"]').exists()).toBe(false);
        other.unmount();
    });

    it('shows a name with markup as text, never as HTML', async () => {
        const wrapper = await open(2);

        expect(wrapper.get('[data-testid="identity-hll"]').text()).toContain(MARKUP);
        expect(wrapper.find('[data-testid="identity-hll"] img').exists()).toBe(false);
        expect(window.pwned).toBeUndefined();
        wrapper.unmount();
    });
});

describe('Settings Games page, identities to check', () => {
    const GAMES = [{ id: 1, slug: 'hll', name: 'Hell Let Loose', idKinds: ['Steam ID', 'Xbox ID'], checkedKinds: ['Steam ID'], interface: 'crcon', retired: false, inUse: true }];
    const INTERFACES = [{ value: 'crcon', label: 'CRCON' }];
    const ROWS = [{ id: 9, trooper: 'SGT Ortega.M', game: 'Hell Let Loose', idKind: 'Steam ID', playerName: `Hid[U+200B]den${MARKUP}`, platformId: '7656', problems: ['This is not a valid Steam ID.'] }];

    it('lists them for an Admin with what to check, markup shown as text', () => {
        const wrapper = mount(Games, { props: { games: GAMES, interfaces: INTERFACES, isAdmin: true, identitiesToCheck: ROWS } });

        const row = wrapper.get('[data-testid="check-9"]');
        expect(row.text()).toContain('SGT Ortega.M');
        expect(row.text()).toContain(`Hid[U+200B]den${MARKUP}`);
        expect(row.text()).toContain('This is not a valid Steam ID.');
        expect(row.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-testid="checked-kinds-hll"]').text()).toBe('Shape checked: Steam ID');
    });

    it('shows no list to someone who is not an Admin, or when nothing needs a check', () => {
        expect(mount(Games, { props: { games: GAMES, interfaces: INTERFACES, isAdmin: false, identitiesToCheck: ROWS } }).find('[data-testid="identities-to-check"]').exists()).toBe(false);
        expect(mount(Games, { props: { games: GAMES, interfaces: INTERFACES, isAdmin: true, identitiesToCheck: [] } }).find('[data-testid="identities-to-check"]').exists()).toBe(false);
    });
});
