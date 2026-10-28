import { beforeEach, describe, expect, it, vi } from 'vitest';
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
