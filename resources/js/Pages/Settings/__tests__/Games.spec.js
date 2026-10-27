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
                post: (...args) => post(...args),
                patch: (...args) => patch(...args),
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
    { id: 1, slug: 'hll', name: 'Hell Let Loose', idLabel: 'Steam ID', interface: 'crcon', retired: false, inUse: true },
    { id: 4, slug: 'squad', name: 'Squad', idLabel: 'EOS ID', interface: 'none', retired: false, inUse: false },
    { id: 5, slug: 'foxhole', name: 'Foxhole', idLabel: 'Player ID', interface: 'none', retired: true, inUse: true },
];

const factory = (isAdmin = true) => mount(Games, { props: { games: GAMES, interfaces: INTERFACES, isAdmin } });

describe('Settings/Games', () => {
    beforeEach(() => {
        post.mockReset();
        patch.mockReset();
        routerPost.mockReset();
        routerDelete.mockReset();
    });

    it('lists every game with its player id label and the interface that reads it', () => {
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
        await wrapper.get('[data-testid="game-id_label"]').setValue('EOS ID');
        await wrapper.get('[data-testid="game-interface"]').setValue('battlemetrics');
        await wrapper.get('[data-testid="game-form"]').trigger('submit');

        expect(post).toHaveBeenCalledWith('/settings/games', expect.objectContaining({ preserveScroll: true }));
        expect(patch).not.toHaveBeenCalled();
    });

    it('edits a game in the same form, saving to that game', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="edit-hll"]').trigger('click');

        expect(wrapper.get('[data-testid="game-name"]').element.value).toBe('Hell Let Loose');
        expect(wrapper.get('[data-testid="game-interface"]').element.value).toBe('crcon');
        expect(wrapper.get('[data-testid="submit-game"]').text()).toBe('Save changes');

        await wrapper.get('[data-testid="game-form"]').trigger('submit');
        expect(patch).toHaveBeenCalledWith('/settings/games/1', expect.anything());

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

    it('is in the Settings navigation', () => {
        expect(locate('/settings/games').item.label).toBe('Games');
        expect(locate('/settings/games').group.id).toBe('settings');
    });
});
