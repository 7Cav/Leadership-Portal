import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { mount } from '@vue/test-utils';
import Games from '../Games.vue';
import { locate } from '@/Layouts/navigation';

const post = vi.fn();
const patch = vi.fn();
const routerPost = vi.fn();
const routerDelete = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        router: { post: (...args) => routerPost(...args), delete: (...args) => routerDelete(...args) },
        useForm: (data) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                // The fields as they stand when the form is sent, passed last so a test can check what was posted.
                post(...args) {
                    post(...args, { name: this.name, abbreviation: this.abbreviation, id_kinds: this.id_kinds, interface: this.interface });
                },
                patch(...args) {
                    patch(...args, { name: this.name, abbreviation: this.abbreviation, id_kinds: this.id_kinds, interface: this.interface });
                },
                clearErrors() {},
                reset() {
                    Object.assign(this, data);
                },
            }),
    };
});

const INTERFACES = [
    { value: 'crcon', label: 'CRCON' },
    { value: 'battlemetrics', label: 'BattleMetrics' },
    { value: 'nightwatch', label: 'Nightwatch' },
    { value: 'none', label: 'None' },
];
const GAMES = [
    { id: 1, slug: 'hll', name: 'Hell Let Loose', abbreviation: 'HLL', idKinds: ['Steam ID'], interface: 'crcon', retired: false, inUse: true },
    { id: 4, slug: 'squad', name: 'Squad', idKinds: ['EOS ID'], interface: 'none', retired: false, inUse: false },
    { id: 5, slug: 'foxhole', name: 'Foxhole', idKinds: ['Player ID'], interface: 'none', retired: true, inUse: true },
];

const factory = (isAdmin = true) => mount(Games, { props: { games: GAMES, interfaces: INTERFACES, isAdmin } });

describe('Settings/Games', () => {
    beforeEach(() => {
        post.mockReset();
        patch.mockReset();
        routerPost.mockReset();
        routerDelete.mockReset();
    });

    it('lists every game with its player id kinds and the interface that reads it', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="game-row-hll"]').text()).toContain('Steam ID');
        expect(wrapper.get('[data-testid="interface-hll"]').text()).toBe('CRCON');
        expect(wrapper.get('[data-testid="interface-squad"]').text()).toBe('None');
        expect(wrapper.find('[data-testid="retired-foxhole"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="retired-hll"]').exists()).toBe(false);
    });

    it('offers delete only for a game nothing uses, and bring back only for a retired one', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-testid="delete-hll"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="delete-squad"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="retire-hll"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="reinstate-foxhole"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="retire-foxhole"]').exists()).toBe(false);
    });

    it('hides every authoring control from a non-Admin', () => {
        const wrapper = factory(false);

        expect(wrapper.find('[data-testid="game-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="edit-hll"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="retire-hll"]').exists()).toBe(false);
    });

    it('adds a game with the interface picked', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="game-name"]').setValue('Squad 44');
        await wrapper.get('[data-testid="game-id_kind-0"]').setValue('EOS ID');
        await wrapper.get('[data-testid="game-interface"]').setValue('battlemetrics');
        await wrapper.get('[data-testid="game-form"]').trigger('submit');

        expect(post).toHaveBeenCalledWith('/settings/games', expect.objectContaining({ preserveScroll: true }), {
            name: 'Squad 44',
            abbreviation: '',
            id_kinds: ['EOS ID'],
            interface: 'battlemetrics',
        });
        expect(patch).not.toHaveBeenCalled();
    });

    it('edits a game in the same form, saving to that game', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="edit-hll"]').trigger('click');

        expect(wrapper.get('[data-testid="game-name"]').element.value).toBe('Hell Let Loose');
        expect(wrapper.get('[data-testid="game-interface"]').element.value).toBe('crcon');
        expect(wrapper.get('[data-testid="submit-game"]').text()).toBe('Save changes');

        await wrapper.get('[data-testid="game-id_kind-0"]').setValue('Steam64');
        await wrapper.get('[data-testid="game-interface"]').setValue('none');
        await wrapper.get('[data-testid="game-form"]').trigger('submit');
        expect(patch).toHaveBeenCalledWith('/settings/games/1', expect.anything(), { name: 'Hell Let Loose', abbreviation: 'HLL', id_kinds: ['Steam64'], interface: 'none' });

        await wrapper.get('[data-testid="cancel-edit"]').trigger('click');
        expect(wrapper.get('[data-testid="submit-game"]').text()).toBe('Add game');
    });

    it('retires, brings back and deletes through their routes, showing a refusal by the row', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="retire-hll"]').trigger('click');
        await wrapper.get('[data-testid="reinstate-foxhole"]').trigger('click');
        await wrapper.get('[data-testid="delete-squad"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/settings/games/1/retire', expect.anything());
        expect(routerDelete).toHaveBeenCalledWith('/settings/games/5/retire', expect.anything());
        expect(routerDelete).toHaveBeenCalledWith('/settings/games/4', expect.anything());

        routerDelete.mock.lastCall[1].onError({ game: 'Squad is in use, so it cannot be deleted. Retire it instead.' });
        await nextTick();

        expect(wrapper.get('[data-testid="row-error"]').text()).toBe('Squad is in use, so it cannot be deleted. Retire it instead.');
    });

    it('lists a game\'s several ID kinds', () => {
        const games = [{ ...GAMES[0], idKinds: ['Steam ID', 'Epic ID', 'Xbox ID'] }];
        const wrapper = mount(Games, { props: { games, interfaces: INTERFACES, isAdmin: true } });

        expect(wrapper.get('[data-testid="kinds-hll"]').text()).toBe('Steam ID, Epic ID, Xbox ID');
    });

    it('gives a game several ID kinds, adding and removing rows, and sends them in order', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="edit-hll"]').trigger('click');

        expect(wrapper.find('[data-testid="remove-kind-0"]').exists()).toBe(false);

        await wrapper.get('[data-testid="add-kind"]').trigger('click');
        await wrapper.get('[data-testid="add-kind"]').trigger('click');
        await wrapper.get('[data-testid="game-id_kind-1"]').setValue('Epic ID');
        await wrapper.get('[data-testid="game-id_kind-2"]').setValue('Xbox ID');
        await wrapper.get('[data-testid="game-form"]').trigger('submit');

        expect(patch).toHaveBeenLastCalledWith('/settings/games/1', expect.anything(), {
            name: 'Hell Let Loose',
            abbreviation: 'HLL',
            id_kinds: ['Steam ID', 'Epic ID', 'Xbox ID'],
            interface: 'crcon',
        });

        await wrapper.get('[data-testid="remove-kind-1"]').trigger('click');
        await wrapper.get('[data-testid="game-form"]').trigger('submit');

        expect(patch.mock.lastCall[2].id_kinds).toEqual(['Steam ID', 'Xbox ID']);
    });

    it('starts a new game with one empty kind row', () => {
        const wrapper = factory();

        expect(wrapper.findAll('[data-testid^="game-id_kind-"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="game-id_kind-0"]').element.value).toBe('');
    });

    it('does not carry one game\'s kinds into the next edit', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="edit-hll"]').trigger('click');
        await wrapper.get('[data-testid="add-kind"]').trigger('click');
        await wrapper.get('[data-testid="edit-squad"]').trigger('click');

        expect(wrapper.findAll('[data-testid^="game-id_kind-"]').map((input) => input.element.value)).toEqual(['EOS ID']);
        expect(GAMES[0].idKinds).toEqual(['Steam ID']);
    });

    it('shows the abbreviation of each game, and sends one typed in lowercase as uppercase', async () => {
        const wrapper = factory();
        expect(wrapper.get('[data-testid="abbreviation-hll"]').text()).toBe('HLL');

        await wrapper.get('[data-testid="game-name"]').setValue('Squad 44');
        await wrapper.get('[data-testid="game-abbreviation"]').setValue('sq44');
        await wrapper.get('[data-testid="game-id_kind-0"]').setValue('EOS ID');
        await wrapper.get('[data-testid="game-form"]').trigger('submit');

        expect(post.mock.lastCall[2].abbreviation).toBe('SQ44');
    });

    it('is in the Settings navigation', () => {
        expect(locate('/settings/games').item.label).toBe('Games');
        expect(locate('/settings/games').group.id).toBe('settings');
    });
});
