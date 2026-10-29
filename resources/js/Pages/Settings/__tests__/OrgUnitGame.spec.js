import { afterEach, describe, it, expect, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// The game a unit plays on the org page: an Admin picks it from the Games list, a unit under it
// inherits it, and the tree shows an inherited game muted with the unit it comes from.

const gamePatch = vi.fn();
let gameForm = null;

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: vi.fn(), post: vi.fn() },
    useForm: (initial) => {
        const form = reactive({ ...initial, processing: false, errors: {}, post: vi.fn(), patch: vi.fn(), clearErrors: vi.fn() });
        if ('game_id' in initial) {
            form.patch = gamePatch;
            gameForm = form;
        }
        return form;
    },
}));

const WARDOGS = { id: 3, name: 'WARDOGS', retired: false, from: null };
const GAMES = [
    { id: 1, name: 'Hell Let Loose (WW2)', retired: false },
    { id: 2, name: 'Arma Reforger', retired: false },
    { id: 3, name: 'WARDOGS', retired: false },
    { id: 4, name: 'Squad', retired: true },
];
const UNITS = [
    { id: 1, parent_id: null, type: 'regiment', kind: 'line', name: '7th Cavalry Regiment', callsign: '7CAV', label: null, game_id: null, game: null, path: '1/', own_count: 0, trooper_count: 2, lead: null, lead_vacant: false },
    { id: 2, parent_id: 1, type: 'platoon', kind: 'line', name: 'DEVCOM Platoon', callsign: '2/A/1-7', label: 'Team Bravo', game_id: 3, game: WARDOGS, path: '1/2/', own_count: 0, trooper_count: 2, lead: null, lead_vacant: false },
    { id: 3, parent_id: 2, type: 'section', kind: 'line', name: '1st Section', callsign: '1/2/A/1-7', label: null, game_id: null, game: { ...WARDOGS, from: '2/A/1-7' }, path: '1/2/3/', own_count: 2, trooper_count: 2, lead: null, lead_vacant: false },
    { id: 4, parent_id: 1, type: 'platoon', kind: 'line', name: 'Old Platoon', callsign: '9/A/1-7', label: null, game_id: 4, game: { id: 4, name: 'Squad', retired: true, from: null }, path: '1/4/', own_count: 0, trooper_count: 0, lead: null, lead_vacant: false },
];

function mountPage(props = {}) {
    return mount(OrgStructure, { props: { units: UNITS, isAdmin: false, games: GAMES, ...props } });
}

afterEach(() => {
    vi.clearAllMocks();
    gameForm = null;
});

describe('Settings/OrgStructure unit game', () => {
    it('shows a unit\'s own game next to its name, and an inherited one muted with where it comes from', () => {
        const wrapper = mountPage();

        const own = wrapper.find('[data-testid="unit-game-2"]');
        const inherited = wrapper.find('[data-testid="unit-game-3"]');

        expect(own.text()).toBe('WARDOGS');
        expect(own.classes()).toContain('font-medium');
        expect(inherited.text()).toBe('WARDOGS from 2/A/1-7');
        expect(inherited.classes()).toContain('text-muted-foreground/80');
        expect(wrapper.find('[data-testid="unit-game-1"]').exists()).toBe(false);
        // The team label stays beside it.
        expect(wrapper.find('[data-testid="unit-label-2"]').text()).toBe('Team Bravo');
    });

    it('marks a retired game a unit still has', () => {
        expect(mountPage().find('[data-testid="unit-game-4"]').text()).toBe('Squad (retired)');
    });

    it('names the game and where it comes from in the details panel, for everyone', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');
        expect(wrapper.find('[data-testid="detail-game"]').text()).toBe('Game: WARDOGS, from 2/A/1-7');

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        expect(wrapper.find('[data-testid="detail-game"]').text()).toBe('Game: WARDOGS');

        await wrapper.find('[data-testid="unit-row-1"]').trigger('click');
        expect(wrapper.find('[data-testid="detail-game"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="game-form"]').exists()).toBe(false);
    });

    it('offers an Admin the games that are not retired and None (inherits), starting on the unit\'s own game', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        const options = wrapper.findAll('[data-testid="edit-game"] option').map((option) => option.text());
        expect(options).toEqual(['None (inherits)', 'Hell Let Loose (WW2)', 'Arma Reforger', 'WARDOGS']);
        expect(wrapper.find('[data-testid="edit-game"]').element.value).toBe('3');
        expect(wrapper.find('label[for="edit-label"]').text()).toBe('Team label');
    });

    it('starts on None (inherits) for a unit that only inherits a game', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');

        expect(gameForm.game_id).toBeNull();
        expect(wrapper.find('[data-testid="edit-game"]').element.selectedIndex).toBe(0);
    });

    it('still lists the retired game a unit has, marked retired', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-4"]').trigger('click');

        const options = wrapper.findAll('[data-testid="edit-game"] option').map((option) => option.text());
        expect(options).toContain('Squad (retired)');
    });

    it('saves the picked game, and None (inherits) as no game', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');
        // Arma Reforger, after None (inherits) and Hell Let Loose.
        wrapper.find('[data-testid="edit-game"]').element.selectedIndex = 2;
        await wrapper.find('[data-testid="edit-game"]').trigger('change');
        await wrapper.find('[data-testid="game-form"]').trigger('submit');

        expect(gamePatch).toHaveBeenCalledWith('/settings/org-structure/3/game', expect.any(Object));
        expect(gameForm.game_id).toBe(2);

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        wrapper.find('[data-testid="edit-game"]').element.selectedIndex = 0;
        await wrapper.find('[data-testid="edit-game"]').trigger('change');
        await wrapper.find('[data-testid="game-form"]').trigger('submit');

        expect(gamePatch).toHaveBeenLastCalledWith('/settings/org-structure/2/game', expect.any(Object));
        expect(gameForm.game_id).toBeNull();
    });
});
