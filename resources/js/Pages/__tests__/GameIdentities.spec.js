import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { mount } from '@vue/test-utils';
import GameIdentities from '../GameIdentities.vue';

const page = reactive({ url: '/game-identities', props: { viewAs: null } });
const put = vi.fn();
const del = vi.fn();
const formState = reactive({ errors: {}, processing: false });
// The fields as they stood when the form was last sent.
const sent = {};

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        router: { delete: (...args) => del(...args) },
        usePage: () => page,
        useForm: (data) =>
            reactive({
                ...data,
                get errors() {
                    return formState.errors;
                },
                processing: false,
                put(...args) {
                    Object.assign(sent, { player_name: this.player_name, platform_id: this.platform_id, id_kind: this.id_kind });
                    return put(...args);
                },
                clearErrors() {
                    formState.errors = {};
                },
                reset() {
                    Object.assign(this, data);
                },
            }),
    };
});

const PLATFORMS = [
    { value: 'hll', label: 'Hell Let Loose', idKinds: ['Steam ID'] },
    { value: 'arma', label: 'Arma Reforger', idKinds: ['Player ID'] },
];
const trooper = (id, name, identities = {}, editable = true) => ({ id, displayName: name, billet: 'Rifleman', section: '1/A', editable, identities });
const TROOPERS = [
    trooper(1, 'SGT Ortega.M', { hll: { playerName: 'OrtegaCav', platformId: '7656' } }),
    trooper(2, 'PFC Dube.R'),
    trooper(3, 'SPC Halvorsen.K', { hll: { playerName: 'Halvy', platformId: '8765' }, arma: { playerName: 'Halvy_7Cav', platformId: 'a41f' } }),
];

const factory = (troopers = TROOPERS) => mount(GameIdentities, { props: { platforms: PLATFORMS, troopers } });

describe('GameIdentities page', () => {
    beforeEach(() => {
        put.mockReset();
        del.mockReset();
        formState.errors = {};
        page.props.viewAs = null;
    });

    it('summarises coverage from the platforms the server sends', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="coverage-linked"]').text()).toBe('3');
        expect(wrapper.get('[data-testid="coverage-slots"]').text()).toBe('6');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('50');
    });

    it('queues a trooper linked on Hell Let Loose (WW2) as missing Hell Let Loose: Vietnam (#431)', async () => {
        const kinds = ['Steam ID', 'Epic ID', 'Xbox ID'];
        const platforms = [
            { value: 'hll', label: 'Hell Let Loose (WW2)', idKinds: kinds },
            { value: 'hll-vietnam', label: 'Hell Let Loose: Vietnam', idKinds: kinds },
        ];
        const troopers = [
            trooper(1, 'SGT Ortega.M', { hll: { playerName: 'OrtegaCav', platformId: '7656', idKind: 'Steam ID' } }),
            trooper(2, 'PFC Austin.C', { hll: { playerName: 'Austin', platformId: '7657', idKind: 'Steam ID' }, 'hll-vietnam': { playerName: 'Austin', platformId: '0002105c', idKind: 'Epic ID' } }),
        ];
        const wrapper = mount(GameIdentities, { props: { platforms, troopers } });

        expect(wrapper.get('[data-testid="row-1"]').text()).toContain('Missing Hell Let Loose: Vietnam');
        expect(wrapper.find('[data-testid="row-2"]').exists()).toBe(false);

        await wrapper.get('[data-testid="open-1"]').trigger('click');

        expect(wrapper.findAll('[data-testid="identity-game"] option').map((option) => option.element.value)).toEqual(['hll', 'hll-vietnam']);
        expect(wrapper.get('[data-testid="identity-game"]').element.value).toBe('hll-vietnam');
    });

    it('offers a retired game for no new link and leaves it out of coverage, but lists and removes its links (#388)', async () => {
        const platforms = [...PLATFORMS, { value: 'squad', label: 'Squad', idKinds: ['EOS ID'], retired: true }];
        const troopers = [trooper(1, 'SGT Ortega.M', { hll: { playerName: 'OrtegaCav', platformId: '7656' }, squad: { playerName: 'Old', platformId: 'eos' } })];
        const wrapper = mount(GameIdentities, { props: { platforms, troopers } });

        expect(wrapper.get('[data-testid="coverage-linked"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="coverage-slots"]').text()).toBe('2');
        expect(wrapper.get('[data-testid="row-1"]').text()).toContain('Missing Arma Reforger');
        expect(wrapper.get('[data-testid="row-1"]').text()).not.toContain('Squad');

        await wrapper.get('[data-testid="open-1"]').trigger('click');

        expect(wrapper.findAll('[data-testid="identity-game"] option').map((option) => option.element.value)).toEqual(['hll', 'arma']);
        expect(wrapper.get('[data-testid="identity-squad"]').text()).toContain('Squad (retired)');
        expect(wrapper.find('[data-testid="edit-squad"]').exists()).toBe(false);

        await wrapper.get('[data-testid="remove-squad"]').trigger('click');
        await wrapper.get('[data-testid="confirm-remove-squad"]').trigger('click');

        expect(del).toHaveBeenCalledWith('/game-identities/1/squad', expect.anything());
    });

    it('offers no link form when every game is retired, but still lists the links to remove', async () => {
        const platforms = PLATFORMS.map((p) => ({ ...p, retired: true }));
        const wrapper = mount(GameIdentities, { props: { platforms, troopers: [TROOPERS[0]] } });
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');
        await wrapper.get('[data-testid="open-1"]').trigger('click');

        expect(wrapper.find('[data-testid="identity-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="no-active-games"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="remove-hll"]').exists()).toBe(true);
    });

    it('queues only troopers missing a game, saying which, and defaults to that view', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="view-needs"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-testid="row-3"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="row-1"]').text()).toContain('Missing Arma Reforger');
        expect(wrapper.get('[data-testid="row-2"]').text()).toContain('Missing Hell Let Loose and Arma Reforger');
    });

    it('lists everyone, linked or not, in the Everyone view', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');

        expect(wrapper.findAll('[data-testid^="row-"]')).toHaveLength(3);
        expect(wrapper.get('[data-testid="open-3"]').text()).toBe('Edit identities');
    });

    it('celebrates an empty queue', () => {
        const wrapper = factory([TROOPERS[2]]);

        expect(wrapper.find('[data-testid="queue-empty"]').exists()).toBe(true);
    });

    it('opens the panel on the first missing game and saves to that platform', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="open-1"]').trigger('click');

        expect(wrapper.get('[data-testid="panel-name"]').text()).toBe('SGT Ortega.M');
        expect(wrapper.get('[data-testid="identity-game"]').element.value).toBe('arma');
        expect(wrapper.get('label[for="identity-id"]').text()).toBe('Player ID');

        await wrapper.get('[data-testid="identity-name"]').setValue('Ortega_7Cav');
        await wrapper.get('[data-testid="identity-id"]').setValue('b2c3');
        await wrapper.get('[data-testid="identity-form"]').trigger('submit');

        expect(put).toHaveBeenCalledWith('/game-identities/1/arma', expect.objectContaining({ preserveScroll: true }));
    });

    it('says why a save was refused in the notice instead of showing the raw error page', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="open-1"]').trigger('click');
        await wrapper.get('[data-testid="identity-name"]').setValue('Ortega_7Cav');
        await wrapper.get('[data-testid="identity-id"]').setValue('b2c3');
        await wrapper.get('[data-testid="identity-form"]').trigger('submit');

        expect(put.mock.lastCall[1].onHttpException({ status: 403 })).toBe(false);
        await nextTick();

        expect(wrapper.get('[data-testid="notice"]').text()).toBe('This was not saved. You are not allowed to do that; reload the page, or exit view as first.');
    });

    it('shows the collision error next to the platform id', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        formState.errors = { platform_id: 'Already linked to SGT Ortega.M on Hell Let Loose.' };
        await nextTick();

        expect(wrapper.get('[data-testid="error-platform_id"]').text()).toBe('Already linked to SGT Ortega.M on Hell Let Loose.');
        expect(wrapper.get('[data-testid="identity-id"]').attributes('aria-invalid')).toBe('true');
    });

    it('prefills an existing identity for editing', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');
        await wrapper.get('[data-testid="open-3"]').trigger('click');
        await wrapper.get('[data-testid="edit-hll"]').trigger('click');

        expect(wrapper.get('[data-testid="identity-name"]').element.value).toBe('Halvy');
        expect(wrapper.get('[data-testid="identity-id"]').element.value).toBe('8765');
        expect(wrapper.get('[data-testid="identity-save"]').text()).toBe('Save changes');
    });

    it('asks before removing, then deletes the platform link', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');
        await wrapper.get('[data-testid="open-3"]').trigger('click');
        await wrapper.get('[data-testid="remove-arma"]').trigger('click');

        expect(del).not.toHaveBeenCalled();
        await wrapper.get('[data-testid="confirm-remove-arma"]').trigger('click');

        expect(del).toHaveBeenCalledWith('/game-identities/3/arma', expect.objectContaining({ preserveScroll: true }));
    });

    it('wraps a linked identity instead of cutting it off, keeping Edit and Remove reachable (#429)', async () => {
        const id = 'a'.repeat(32);
        const troopers = [trooper(1, 'SGT Ortega.M', { hll: { playerName: '=7Cav=PFC.Austin.C', platformId: id } })];
        const wrapper = mount(GameIdentities, { props: { platforms: PLATFORMS, troopers } });
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');
        await wrapper.get('[data-testid="open-1"]').trigger('click');

        const box = wrapper.get('[data-testid="identity-hll"]');
        const [name, platformId] = box.findAll('p').slice(1);

        expect(name.text()).toBe('=7Cav=PFC.Austin.C');
        expect(platformId.text()).toBe(id);
        for (const text of [name, platformId]) {
            expect(text.classes()).not.toContain('truncate');
            expect(text.classes().some((c) => c === 'break-words' || c === 'break-all')).toBe(true);
        }
        // The row wraps, so the actions drop below the text rather than being squeezed out.
        expect(box.get('div').classes()).toContain('flex-wrap');
        expect(box.get('[data-testid="edit-hll"]').element.parentElement.classList.contains('shrink-0')).toBe(true);
        expect(box.find('[data-testid="remove-hll"]').exists()).toBe(true);
    });

    it('closes the panel on Cancel', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        await wrapper.get('[data-testid="identity-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="identity-form"]').exists()).toBe(false);
    });

    it('is read-only while viewing as someone else', async () => {
        page.props.viewAs = { label: 'Vasquez.R' };
        const wrapper = factory();
        await wrapper.get('[data-testid="open-2"]').trigger('click');

        expect(wrapper.get('[data-testid="read-only"]').text()).toContain('Vasquez.R');
        expect(wrapper.get('[data-testid="identity-save"]').attributes('disabled')).toBeDefined();
    });

    it('is read-only for a trooper the viewer only sees through a staff grant', async () => {
        const wrapper = factory([trooper(1, 'SGT Ortega.M', { hll: { playerName: 'OrtegaCav', platformId: '7656' } }, false), trooper(2, 'PFC Dube.R')]);
        await wrapper.get('[data-testid="open-1"]').trigger('click');

        expect(wrapper.get('[data-testid="read-only"]').text()).toContain('a staff grant lets you view this trooper');
        expect(wrapper.get('[data-testid="identity-save"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="remove-hll"]').attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="open-2"]').trigger('click');
        expect(wrapper.find('[data-testid="read-only"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="identity-save"]').attributes('disabled')).toBeUndefined();
    });

    it('shows an empty state when nobody is in scope', () => {
        const wrapper = factory([]);

        expect(wrapper.text()).toContain('No troopers in your scope.');
        expect(wrapper.find('[data-testid="coverage"]').exists()).toBe(false);
    });
});

// #427: the panel follows the leader instead of being hunted for.
describe('GameIdentities panel in view', () => {
    const scrolled = vi.fn();
    const FULL = { hll: { playerName: 'B', platformId: '1' }, arma: { playerName: 'B', platformId: '2' } };
    let wide = false;
    let host;

    const mountAttached = (troopers = TROOPERS, platforms = PLATFORMS) => mount(GameIdentities, { props: { platforms, troopers }, attachTo: host });
    const scrolledEls = () => scrolled.mock.contexts.map((el) => el.dataset.testid);
    const settle = async () => {
        await nextTick();
        await nextTick();
    };
    // Open a trooper, then forget the scrolling the open itself did.
    const openAndReset = async (wrapper, id) => {
        await wrapper.get(`[data-testid="open-${id}"]`).trigger('click');
        await settle();
        scrolled.mockClear();
    };
    // Submit, then hand the page the fresh props the server would, and fire the save's success.
    const saveWith = async (wrapper, troopers) => {
        await wrapper.get('[data-testid="identity-form"]').trigger('submit');
        await wrapper.setProps({ troopers });
        put.mock.calls[0][1].onSuccess();
        await settle();
    };

    beforeEach(() => {
        put.mockReset();
        formState.errors = {};
        page.props.viewAs = null;
        scrolled.mockReset();
        wide = false;
        Element.prototype.scrollIntoView = scrolled;
        window.matchMedia = vi.fn(() => ({ matches: wide }));
        host = document.createElement('div');
        document.body.appendChild(host);
    });

    afterEach(() => host.remove());

    it('sticks the panel below the shell bar on wide screens and lets it scroll inside itself', () => {
        const classes = mountAttached().get('[data-testid="panel"]').classes();

        expect(classes).toContain('lg:sticky');
        expect(classes).toContain('lg:top-[calc(var(--shell-top,3rem)+0.5rem)]');
        expect(classes).toContain('lg:max-h-[calc(100dvh-var(--shell-top,3rem)-1rem)]');
        expect(classes).toContain('lg:overflow-y-auto');
    });

    it('does not clip the card with overflow-hidden, which would stop the panel sticking', () => {
        const card = mountAttached().get('[data-testid="coverage"]').element.parentElement;

        expect(card.classList.contains('overflow-hidden')).toBe(false);
    });

    it('scrolls the panel to the top on narrow screens when a trooper is picked, clear of the shell bar', async () => {
        const wrapper = mountAttached();
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        await settle();

        expect(scrolledEls()).toEqual(['panel']);
        expect(scrolled).toHaveBeenCalledWith({ block: 'start' });
        expect(wrapper.get('[data-testid="panel"]').classes()).toContain('scroll-mt-[calc(var(--shell-top,3rem)+0.5rem)]');
    });

    it('leaves the page alone on wide screens, where the panel is already beside the list', async () => {
        wide = true;
        const wrapper = mountAttached();
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        await settle();

        expect(scrolled).not.toHaveBeenCalled();
    });

    it('moves focus to the first form field when the panel opens', async () => {
        const wrapper = mountAttached();
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        await settle();

        expect(document.activeElement).toBe(wrapper.get('[data-testid="identity-game"]').element);
    });

    it('focuses the panel itself when there is no form to fill (every game retired)', async () => {
        const wrapper = mountAttached(TROOPERS, PLATFORMS.map((p) => ({ ...p, retired: true })));
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');
        await wrapper.get('[data-testid="open-1"]').trigger('click');
        await settle();

        expect(document.activeElement).toBe(wrapper.get('[data-testid="panel"]').element);
    });

    it('returns to the trooper row on Cancel, focusing their button', async () => {
        const wrapper = mountAttached();
        await openAndReset(wrapper, 2);
        await wrapper.get('[data-testid="identity-cancel"]').trigger('click');
        await settle();

        expect(scrolledEls()).toEqual(['row-2']);
        expect(scrolled).toHaveBeenCalledWith({ block: 'nearest' });
        expect(document.activeElement).toBe(wrapper.get('[data-testid="open-2"]').element);
    });

    it('returns to where the row was after a save drops the trooper from the queue', async () => {
        const troopers = [trooper(1, 'A'), trooper(2, 'B'), trooper(3, 'C')];
        const wrapper = mountAttached(troopers);
        await openAndReset(wrapper, 2);
        await saveWith(wrapper, [troopers[0], { ...troopers[1], identities: FULL }, troopers[2]]);

        expect(wrapper.find('[data-testid="row-2"]').exists()).toBe(false);
        expect(scrolledEls()).toEqual(['row-3']);
        expect(document.activeElement).toBe(wrapper.get('[data-testid="open-3"]').element);
    });

    it('returns to the last row when the saved trooper was the last in the queue', async () => {
        const troopers = [trooper(1, 'A'), trooper(2, 'B')];
        const wrapper = mountAttached(troopers);
        await openAndReset(wrapper, 2);
        await saveWith(wrapper, [troopers[0], { ...troopers[1], identities: FULL }]);

        expect(scrolledEls()).toEqual(['row-1']);
    });

    it('starts the panel from its top when a trooper is picked, so the first field is in sight', async () => {
        const wrapper = mountAttached();
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        await settle();
        wrapper.get('[data-testid="panel"]').element.scrollTop = 120;
        await wrapper.get('[data-testid="identity-cancel"]').trigger('click');
        await wrapper.get('[data-testid="open-2"]').trigger('click');
        await settle();

        expect(wrapper.get('[data-testid="panel"]').element.scrollTop).toBe(0);
    });

    it('returns to the row when removing a link finds the trooper gone from scope', async () => {
        del.mockReset();
        const wrapper = mountAttached();
        await wrapper.get('[data-testid="view-everyone"]').trigger('click');
        await openAndReset(wrapper, 1);
        await wrapper.get('[data-testid="remove-hll"]').trigger('click');
        await wrapper.get('[data-testid="confirm-remove-hll"]').trigger('click');
        await wrapper.setProps({ troopers: TROOPERS.slice(1) });
        del.mock.calls[0][1].onSuccess();
        await settle();

        expect(wrapper.get('[data-testid="notice"]').text()).toContain('no longer in your scope');
        expect(scrolledEls()).toEqual(['row-2']);
        expect(document.activeElement).toBe(wrapper.get('[data-testid="open-2"]').element);
    });

    it('does not jump away while a save carries on to the trooper next game', async () => {
        const wrapper = mountAttached();
        await openAndReset(wrapper, 2);
        await saveWith(wrapper, [TROOPERS[0], trooper(2, 'PFC Dube.R', { hll: { playerName: 'D', platformId: '1' } }), TROOPERS[2]]);

        expect(wrapper.find('[data-testid="identity-form"]').exists()).toBe(true);
        expect(scrolled).not.toHaveBeenCalled();
    });
});
