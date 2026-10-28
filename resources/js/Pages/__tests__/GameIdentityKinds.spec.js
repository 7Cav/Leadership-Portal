import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { mount } from '@vue/test-utils';
import GameIdentities from '../GameIdentities.vue';

// #428: a game can ask for several kinds of player ID; the form asks for the kind first and the ID field follows it.

const page = reactive({ url: '/game-identities', props: { viewAs: null } });
const put = vi.fn();
const formState = reactive({ errors: {} });
const sent = {};

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        router: { delete: vi.fn() },
        usePage: () => page,
        useForm: (data) =>
            reactive({
                ...data,
                get errors() {
                    return formState.errors;
                },
                processing: false,
                put(...args) {
                    Object.assign(sent, { platform_id: this.platform_id, id_kind: this.id_kind });
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
    { value: 'hll', label: 'Hell Let Loose', idKinds: ['Steam ID', 'Epic ID', 'Xbox ID'], retired: false },
    { value: 'arma', label: 'Arma Reforger', idKinds: ['Player ID'], retired: false },
];
const trooper = (id, name, identities = {}) => ({ id, displayName: name, billet: 'Rifleman', section: '1/A', editable: true, identities });
const TROOPERS = [
    trooper(1, 'SGT Ortega.M', { hll: { playerName: 'OrtegaCav', platformId: '0002105c01b748ada8d8cf5b32e70093', idKind: 'Epic ID' }, arma: { playerName: 'Ortega', platformId: 'a41f', idKind: 'Player ID' } }),
    trooper(2, 'PFC Dube.R'),
];

const open = async (id, view = 'everyone') => {
    const wrapper = mount(GameIdentities, { props: { platforms: PLATFORMS, troopers: TROOPERS } });
    await wrapper.get(`[data-testid="view-${view}"]`).trigger('click');
    await wrapper.get(`[data-testid="open-${id}"]`).trigger('click');

    return wrapper;
};

describe('GameIdentities page, ID kinds', () => {
    beforeEach(() => {
        put.mockReset();
        formState.errors = {};
    });

    it('asks for the kind of a game with several, labelling the ID field and its placeholder after it', async () => {
        const wrapper = await open(2);

        expect(wrapper.findAll('[data-testid="identity-kind"] option').map((o) => o.element.value)).toEqual(['Steam ID', 'Epic ID', 'Xbox ID']);
        expect(wrapper.get('label[for="identity-id"]').text()).toBe('Steam ID');
        expect(wrapper.get('[data-testid="identity-id"]').attributes('placeholder')).toBe('Paste the Steam ID');

        await wrapper.get('[data-testid="identity-kind"]').setValue('Epic ID');

        expect(wrapper.get('label[for="identity-id"]').text()).toBe('Epic ID');
        expect(wrapper.get('[data-testid="identity-id"]').attributes('placeholder')).toBe('Paste the Epic ID');
    });

    it('sends the chosen kind with the id', async () => {
        const wrapper = await open(2);
        await wrapper.get('[data-testid="identity-name"]').setValue('Dube');
        await wrapper.get('[data-testid="identity-kind"]').setValue('Xbox ID');
        await wrapper.get('[data-testid="identity-id"]').setValue('2535411234567890');
        await wrapper.get('[data-testid="identity-form"]').trigger('submit');

        expect(put).toHaveBeenCalledWith('/game-identities/2/hll', expect.anything());
        expect(sent).toEqual({ platform_id: '2535411234567890', id_kind: 'Xbox ID' });
    });

    it('shows no kind picker for a game with one kind, which still sends its one kind', async () => {
        const wrapper = await open(2);
        await wrapper.get('[data-testid="identity-game"]').setValue('arma');

        expect(wrapper.find('[data-testid="identity-kind"]').exists()).toBe(false);
        expect(wrapper.get('label[for="identity-id"]').text()).toBe('Player ID');

        await wrapper.get('[data-testid="identity-id"]').setValue('b2c3');
        await wrapper.get('[data-testid="identity-form"]').trigger('submit');

        expect(sent.id_kind).toBe('Player ID');
    });

    it('prefills the kind of an identity being edited, and shows it beside the identity and in the list', async () => {
        const wrapper = await open(1);

        expect(wrapper.get('[data-testid="row-1"]').text()).toContain('Hell Let Loose: OrtegaCav (Epic ID)');
        expect(wrapper.get('[data-testid="row-1"]').text()).not.toContain('(Player ID)');
        expect(wrapper.get('[data-testid="kind-hll"]').text()).toBe('Epic ID');
        expect(wrapper.find('[data-testid="kind-arma"]').exists()).toBe(false);

        await wrapper.get('[data-testid="edit-hll"]').trigger('click');

        expect(wrapper.get('[data-testid="identity-kind"]').element.value).toBe('Epic ID');
        expect(wrapper.get('label[for="identity-id"]').text()).toBe('Epic ID');
    });

    it('shows a refused kind next to the picker', async () => {
        const wrapper = await open(2);
        formState.errors = { id_kind: 'Hell Let Loose has no ID kind "GOG ID".' };
        await nextTick();

        expect(wrapper.get('[data-testid="error-id_kind"]').text()).toBe('Hell Let Loose has no ID kind "GOG ID".');
        expect(wrapper.find('[data-testid="notice"]').exists()).toBe(false);
    });
});
