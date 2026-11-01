import { afterEach, describe, it, expect, vi } from 'vitest';
import { reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import OrgStructure from '../OrgStructure.vue';

// The game a unit plays on the org page: a unit with no game above it picks it from the Games list, every unit under it
// inherits it, and the tree shows it only on that unit's row, as a chip with the game's abbreviation. Under a unit with
// a game the details panel shows the game locked; a game set there before can only be cleared.

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

const HLLV = { id: 5, name: 'Hell Let Loose: Vietnam', abbreviation: 'HLLV', retired: false, from: null };
const WARDOGS = { id: 3, name: 'WARDOGS', abbreviation: 'WD', retired: false, from: null };
const GAMES = [
    { id: 1, name: 'Hell Let Loose (WW2)', retired: false },
    { id: 2, name: 'Arma Reforger', retired: false },
    { id: 3, name: 'WARDOGS', retired: false },
    { id: 4, name: 'Squad', retired: true },
    { id: 5, name: 'Hell Let Loose: Vietnam', retired: false },
];
const base = { kind: 'line', label: null, own_count: 0, trooper_count: 2, lead: null, lead_vacant: false, can_set_game: true, game_locked_by: null };
const UNITS = [
    { ...base, id: 1, parent_id: null, type: 'regiment', name: '7th Cavalry Regiment', callsign: '7CAV', game_id: null, game: null, path: '1/', sets_game: true },
    { ...base, id: 5, parent_id: 1, type: 'battalion', name: '2nd Battalion', callsign: '2-7', label: 'Team Bravo', game_id: 5, game: HLLV, path: '1/5/', sets_game: true },
    { ...base, id: 6, parent_id: 5, type: 'company', name: 'Alpha Company', callsign: 'A/2-7', game_id: null, game: { ...HLLV, from: '2-7' }, path: '1/5/6/', sets_game: false, game_locked_by: '2-7' },
    // A platoon with a game of its own from before games were picked per battalion.
    { ...base, id: 2, parent_id: 6, type: 'platoon', name: '2nd Platoon', callsign: '2/A/2-7', game_id: 3, game: WARDOGS, path: '1/5/6/2/', sets_game: false, game_locked_by: '2-7' },
    { ...base, id: 3, parent_id: 2, type: 'section', name: '1st Section', callsign: '1/2/A/2-7', game_id: null, game: { ...WARDOGS, from: '2/A/2-7' }, path: '1/5/6/2/3/', sets_game: false, game_locked_by: '2/A/2-7' },
    { ...base, id: 7, parent_id: 1, type: 'battalion', name: '1st Battalion', callsign: '1-7', game_id: 4, game: { id: 4, name: 'Squad', abbreviation: 'SQD', retired: true, from: null }, path: '1/7/', sets_game: true },
    { ...base, id: 8, parent_id: 1, type: 'battalion', name: '3rd Battalion', callsign: '3-7', game_id: null, game: null, path: '1/8/', sets_game: true },
];

function mountPage(props = {}, units = UNITS) {
    return mount(OrgStructure, { props: { units, isAdmin: false, games: GAMES, ...props } });
}

afterEach(() => {
    vi.clearAllMocks();
    gameForm = null;
    document.body.innerHTML = '';
});

describe('Settings/OrgStructure unit game', () => {
    it('shows the game only on a battalion\'s row, as its abbreviation, and no nickname chip', () => {
        const wrapper = mountPage();

        const chip = wrapper.find('[data-testid="unit-game-5"]');
        expect(chip.text()).toBe('HLLV');
        expect(chip.attributes('title')).toBe('Hell Let Loose: Vietnam');
        expect(wrapper.find('[data-testid="unit-row-5"]').text()).not.toContain('Team Bravo');

        // No game text on the regiment or below the battalion, own or inherited.
        for (const id of [1, 6, 2, 3]) expect(wrapper.find(`[data-testid="unit-game-${id}"]`).exists()).toBe(false);
        expect(wrapper.find('[data-testid="unit-row-6"]').text()).not.toContain('Hell Let Loose');
    });

    it('names a retired game in the chip\'s title', () => {
        expect(mountPage().find('[data-testid="unit-game-7"]').attributes('title')).toBe('Squad (retired)');
    });

    it('names the game and where it comes from in the details panel, for everyone', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-6"]').trigger('click');
        expect(wrapper.find('[data-testid="detail-game"]').text()).toBe('Game: Hell Let Loose: Vietnam, from 2-7');

        await wrapper.find('[data-testid="unit-row-1"]').trigger('click');
        expect(wrapper.find('[data-testid="detail-game"]').exists()).toBe(false);
    });

    it('shows no game control to someone who may not change it', async () => {
        const units = UNITS.map((unit) => ({ ...unit, can_set_game: false }));
        const wrapper = mountPage({ isAdmin: true }, units);

        await wrapper.find('[data-testid="unit-row-5"]').trigger('click');
        expect(wrapper.find('[data-testid="game-form"]').exists()).toBe(false);
        await wrapper.find('[data-testid="unit-row-6"]').trigger('click');
        expect(wrapper.find('[data-testid="game-locked"]').exists()).toBe(false);
    });

    it('offers a battalion the games that are not retired, starting on its own game', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-5"]').trigger('click');

        const options = wrapper.findAll('[data-testid="edit-game"] option').map((option) => option.text());
        expect(options).toEqual(['Inherit from the unit above', 'Hell Let Loose (WW2)', 'Arma Reforger', 'WARDOGS', 'Hell Let Loose: Vietnam']);
        expect(wrapper.find('[data-testid="edit-game"]').element.value).toBe('5');
    });

    it('still lists the retired game a battalion has, marked retired', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-7"]').trigger('click');

        expect(wrapper.findAll('[data-testid="edit-game"] option').map((option) => option.text())).toContain('Squad (retired)');
    });

    it('locks a unit under one with a game to the game it inherits, saying where it is set', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-6"]').trigger('click');

        expect(wrapper.find('[data-testid="game-form"]').exists()).toBe(false);
        const select = wrapper.find('[data-testid="game-locked"] [data-testid="edit-game"]');
        expect(select.attributes('disabled')).toBeDefined();
        expect(select.findAll('option').map((option) => option.text())).toEqual(['Hell Let Loose: Vietnam (from 2-7)']);
        expect(wrapper.find('[data-testid="clear-game"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="game-locked"]').text()).toContain('Set by 2-7. Clear the game there to pick one here.');
    });

    it('lets a company pick its own game when no unit above it has one', async () => {
        const units = UNITS.map((unit) => (unit.id === 6 ? { ...unit, game: null, sets_game: true, game_locked_by: null } : unit));
        const wrapper = mountPage({}, units);

        await wrapper.find('[data-testid="unit-row-6"]').trigger('click');

        expect(wrapper.find('[data-testid="game-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="game-locked"]').exists()).toBe(false);
    });

    it('shows a game set under a unit that has one, and lets it be cleared, never changed', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');

        const select = wrapper.find('[data-testid="game-locked"] [data-testid="edit-game"]');
        expect(select.attributes('disabled')).toBeDefined();
        expect(select.findAll('option').map((option) => option.text())).toEqual(['WARDOGS']);

        await wrapper.find('[data-testid="clear-game"]').trigger('click');

        expect(gamePatch).toHaveBeenCalledWith('/settings/org-structure/2/game', expect.any(Object));
        expect(gameForm.game_id).toBeNull();
    });

    it('saves the picked game on a battalion, and the inherit choice as no game', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="unit-row-8"]').trigger('click');
        // Arma Reforger, after the inherit choice and Hell Let Loose.
        wrapper.find('[data-testid="edit-game"]').element.selectedIndex = 2;
        await wrapper.find('[data-testid="edit-game"]').trigger('change');
        await wrapper.find('[data-testid="game-form"]').trigger('submit');

        expect(gamePatch).toHaveBeenCalledWith('/settings/org-structure/8/game', expect.any(Object));
        expect(gameForm.game_id).toBe(2);

        await wrapper.find('[data-testid="unit-row-5"]').trigger('click');
        wrapper.find('[data-testid="edit-game"]').element.selectedIndex = 0;
        await wrapper.find('[data-testid="edit-game"]').trigger('change');
        await wrapper.find('[data-testid="game-form"]').trigger('submit');

        expect(gamePatch).toHaveBeenLastCalledWith('/settings/org-structure/5/game', expect.any(Object));
        expect(gameForm.game_id).toBeNull();
    });

    it('names the inherited game in the inherit choice of a battalion that inherits one', async () => {
        const units = UNITS.map((unit) => (unit.id === 8 ? { ...unit, game: { ...WARDOGS, from: '7CAV' } } : unit));
        const wrapper = mountPage({}, units);

        await wrapper.find('[data-testid="unit-row-8"]').trigger('click');

        expect(wrapper.find('[data-testid="edit-game"] option').text()).toBe('WARDOGS (from 7CAV)');
    });
});

describe('Settings/OrgStructure add unit placement', () => {
    it('adds a battalion under the regiment with nothing selected, the parent shown read-only', async () => {
        const wrapper = mountPage({ isAdmin: true });
        await wrapper.find('[data-testid="add-unit-open"]').trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[data-testid="add-parent"]').textContent).toBe('7th Cavalry Regiment');
        expect(document.body.querySelector('[data-testid="add-type"]').textContent).toBe('battalion');
        expect(document.body.querySelector('#add-parent')).toBeNull();
        expect(wrapper.vm.form.parent_id).toBe(1);
    });

    it('follows the selected unit, one echelon down', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-6"]').trigger('click');
        await wrapper.find('[data-testid="add-unit-open"]').trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[data-testid="add-parent"]').textContent).toBe('Alpha Company');
        expect(wrapper.vm.form.type).toBe('platoon');
        expect(wrapper.vm.form.parent_id).toBe(6);
    });

    it('falls back to a battalion under the regiment when the selected unit takes no units under it', async () => {
        const wrapper = mountPage({ isAdmin: true });

        await wrapper.find('[data-testid="unit-row-3"]').trigger('click');

        expect(wrapper.vm.form.type).toBe('battalion');
        expect(wrapper.vm.form.parent_id).toBe(1);
    });
});

describe('Settings/OrgStructure add unit dialog', () => {
    const body = (testid) => document.body.querySelector(`[data-testid="${testid}"]`);

    async function openFor(rowId) {
        const wrapper = mountPage({ isAdmin: true });
        if (rowId) await wrapper.find(`[data-testid="unit-row-${rowId}"]`).trigger('click');
        await wrapper.find('[data-testid="add-unit-open"]').trigger('click');
        await flushPromises();
        return wrapper;
    }

    it('shows Add unit to a leader only under a unit the server lets them add to', async () => {
        const units = UNITS.map((unit) => ({ ...unit, can_add_child: unit.id === 2 }));
        const wrapper = mountPage({ isAdmin: false }, units);

        expect(wrapper.find('[data-testid="add-unit-open"]').exists()).toBe(false);
        await wrapper.find('[data-testid="unit-row-6"]').trigger('click');
        expect(wrapper.find('[data-testid="add-unit-open"]').exists()).toBe(false);
        await wrapper.find('[data-testid="unit-row-2"]').trigger('click');
        expect(wrapper.find('[data-testid="add-unit-open"]').text()).toBe('Add a section under 2nd Platoon');
    });

    it('puts the echelon first, the parent right under it, then the pick', async () => {
        await openFor(6);
        const html = body('add-unit-dialog').innerHTML;

        expect(html.indexOf('data-testid="add-type"')).toBeLessThan(html.indexOf('data-testid="add-parent"'));
        expect(html.indexOf('data-testid="add-parent"')).toBeLessThan(html.indexOf('data-testid="add-ordinal"'));
    });

    it('offers only the ordinals still free under the parent, gaps included', async () => {
        await openFor(1);
        const names = [...body('add-ordinal').querySelectorAll('option:not([disabled])')].map((option) => option.textContent);

        // 1st, 2nd and 3rd Battalion exist under the regiment.
        expect(names[0]).toBe('4th Battalion');
        expect(names).not.toContain('2nd Battalion');
        expect(names).toHaveLength(6);
    });

    it('shows the derived name and callsign read-only, with no free text for either', async () => {
        const wrapper = await openFor(2);
        expect(body('add-unit-save').disabled).toBe(true);

        wrapper.vm.form.ordinal = '2';
        await flushPromises();

        expect(body('add-name').textContent).toBe('2nd Section');
        expect(body('add-callsign').textContent).toBe('2/2/A/2-7');
        expect(body('add-unit-dialog').querySelectorAll('input')).toHaveLength(0);
        expect(body('add-unit-save').disabled).toBe(false);
    });

    it('sends only the echelon, parent and pick', async () => {
        const wrapper = await openFor(6);
        wrapper.vm.form.ordinal = '1';
        await flushPromises();

        expect(Object.keys(wrapper.vm.form).filter((key) => ['type', 'parent_id', 'ordinal', 'name', 'callsign'].includes(key)).sort())
            .toEqual(['ordinal', 'parent_id', 'type']);
        expect(wrapper.vm.form.type).toBe('platoon');
        expect(wrapper.vm.form.parent_id).toBe(6);
    });
});
