import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import Servers from '../Servers.vue';

const routerPost = vi.fn();
const routerPatch = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { post: (...args) => routerPost(...args), patch: (...args) => routerPatch(...args) },
}));

const INTERFACES = [
    { value: 'crcon', label: 'CRCON', credentialsSet: true },
    { value: 'battlemetrics', label: 'BattleMetrics', credentialsSet: true },
    { value: 'nightwatch', label: 'Nightwatch', credentialsSet: false },
];
const TYPES = [
    { value: 'training', label: 'Training' },
    { value: 'public', label: 'Public' },
];
const GAMES = [
    { id: 1, name: 'Hell Let Loose', retired: false, interface: 'crcon', readsEnvServer: false, captureConfigured: true },
    { id: 2, name: 'Arma Reforger', retired: false, interface: 'battlemetrics', readsEnvServer: true, captureConfigured: true },
    { id: 3, name: 'WARDOGS', retired: true, interface: 'nightwatch', readsEnvServer: false, captureConfigured: false },
];
const server = (id, extra = {}) => ({
    id,
    interface: 'battlemetrics',
    externalId: String(100 + id),
    name: `Server ${id}`,
    gameId: null,
    type: 'public',
    selected: false,
    fallbackId: null,
    missing: false,
    credentialsSet: true,
    lastRead: null,
    ...extra,
});
const SERVERS = [
    server(1, { interface: 'crcon', externalId: 'crcon', name: 'HLL Training', gameId: 1, type: 'training', selected: true, lastRead: { succeeded: false, reason: 'crcon: unreachable', at: '2026-10-27T20:00:00Z' } }),
    server(2, { name: 'HLL BM twin', gameId: 1, selected: true }),
    server(3, { name: 'Gone', missing: true }),
];

const factory = (props = {}) => mount(Servers, { props: { servers: SERVERS, games: GAMES, interfaces: INTERFACES, types: TYPES, ...props } });

describe('Settings/Servers', () => {
    beforeEach(() => {
        routerPost.mockReset();
        routerPatch.mockReset();
    });

    it('shows what each game reads: its selected servers with type, interface and last read, or the env server', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="reads-1"]').text()).toContain('HLL Training');
        expect(wrapper.get('[data-testid="reads-1"]').text()).toContain('Training');
        expect(wrapper.get('[data-testid="reads-1"]').text()).toContain('CRCON');
        expect(wrapper.get('[data-testid="last-read-1"]').text()).toContain('crcon: unreachable');
        // A public server is listed under its game but no read is shown: it is never read.
        expect(wrapper.find('[data-testid="last-read-2"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="env-2"]').text()).toBe('No server selected: reads the BattleMetrics server set in env.');
        expect(wrapper.get('[data-testid="not-configured-3"]').exists()).toBe(true);
    });

    it('says which interfaces have their credentials set', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="credentials-crcon"]').text()).toContain('credentials set');
        expect(wrapper.get('[data-testid="credentials-nightwatch"]').text()).toContain('credentials not set');
    });

    it('flags a server the API no longer lists, and offers a fallback only on a CRCON server, from selected BattleMetrics servers', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-testid="missing-3"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="fallback-2"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="fallback-1"]').findAll('option').map((o) => o.text())).toEqual(['No fallback', 'HLL BM twin']);
    });

    it('does not offer a retired game for a server not already on it', () => {
        const options = factory().get('[data-testid="game-3-select"]').findAll('option');

        expect(options.find((o) => o.text().startsWith('WARDOGS')).attributes('disabled')).toBeDefined();
    });

    it('saves a row\'s changed choices, and only once something changed', async () => {
        const wrapper = factory();
        const save = wrapper.get('[data-testid="save-3"]');

        expect(save.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="selected-3"]').setValue(true);
        await wrapper.get('[data-testid="game-3-select"]').setValue('2');
        await wrapper.get('[data-testid="type-3"]').setValue('training');
        await save.trigger('click');

        expect(routerPatch).toHaveBeenCalledWith('/settings/servers/3', { selected: true, game_id: 2, type: 'training', fallback_server_id: null }, expect.any(Object));
    });

    it('shows the server\'s refusal on the row', async () => {
        routerPatch.mockImplementation((url, data, options) => options.onError({ server: 'Pick the game this server is for before selecting it.' }));
        const wrapper = factory();

        await wrapper.get('[data-testid="selected-3"]').setValue(true);
        await wrapper.get('[data-testid="save-3"]').trigger('click');
        await nextTick();

        expect(wrapper.get('[data-testid="row-error-3"]').text()).toBe('Pick the game this server is for before selecting it.');
    });

    it('fetches the servers and reports what each interface did', async () => {
        const wrapper = factory({
            fetchResult: [
                { interface: 'crcon', status: 'listed', listed: 1, added: 0, missing: 0 },
                { interface: 'battlemetrics', status: 'failed', message: 'BattleMetrics answered with an error (HTTP 503).' },
                { interface: 'nightwatch', status: 'not_configured' },
            ],
        });

        expect(wrapper.get('[data-testid="fetch-crcon"]').text()).toBe('CRCON: 1 listed.');
        expect(wrapper.get('[data-testid="fetch-battlemetrics"]').text()).toContain('could not be read. BattleMetrics answered with an error (HTTP 503). Stored servers were left as they are.');
        expect(wrapper.get('[data-testid="fetch-nightwatch"]').text()).toBe('Nightwatch: credentials not set, not asked.');

        await wrapper.get('[data-testid="fetch-servers"]').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/settings/servers/fetch', {}, expect.any(Object));
    });

    it('gives a newly fetched server a row once the list comes back', async () => {
        const wrapper = factory();
        await wrapper.setProps({ servers: [...SERVERS, server(4, { name: 'New one' })] });

        expect(wrapper.get('[data-testid="server-row-4"]').text()).toContain('New one');
        expect(wrapper.get('[data-testid="save-4"]').attributes('disabled')).toBeDefined();
    });
});
