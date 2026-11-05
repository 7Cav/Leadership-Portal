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
const source = (extra = {}) => ({ interface: 'crcon', configured: true, lastSucceededAt: null, lastFailedAt: null, lastFailureReason: null, ...extra });
const GAMES = [
    // Configured, read a minute ago, after an earlier failure.
    {
        id: 1, name: 'Hell Let Loose', retired: false, interface: 'crcon', readsEnvServer: false, captureConfigured: true,
        connections: [{ label: 'HLL Training', sources: [source({ lastSucceededAt: '2026-10-27T20:05:00+00:00', lastFailedAt: '2026-10-27T19:00:00+00:00', lastFailureReason: 'crcon: unreachable' })] }],
    },
    // Down: its last read failed.
    {
        id: 2, name: 'Arma Reforger', retired: false, interface: 'battlemetrics', readsEnvServer: true, captureConfigured: true,
        connections: [{ label: 'Arma Reforger server', sources: [source({ interface: 'battlemetrics', lastSucceededAt: '2026-10-26T20:00:00+00:00', lastFailedAt: '2026-10-27T23:59:00+00:00', lastFailureReason: 'battlemetrics: http_error' })] }],
    },
    // Unconfigured: the credentials are not set.
    {
        id: 3, name: 'WARDOGS', retired: true, interface: 'nightwatch', readsEnvServer: false, captureConfigured: false,
        connections: [{ label: 'WARDOGS server', sources: [source({ interface: 'nightwatch', configured: false })] }],
    },
    // Read by none.
    { id: 4, name: 'Hell Let Loose: Vietnam', retired: false, interface: 'none', readsEnvServer: false, captureConfigured: false, connections: [] },
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
    ...extra,
});
const SERVERS = [
    server(1, { interface: 'crcon', externalId: 'crcon', name: 'HLL Training', gameId: 1, type: 'training', selected: true }),
    server(2, { name: 'HLL BM twin', gameId: 1, selected: true }),
    server(3, { name: 'Gone', missing: true }),
];

const factory = (props = {}) => mount(Servers, { props: { servers: SERVERS, games: GAMES, interfaces: INTERFACES, types: TYPES, ...props } });

describe('Settings/Servers', () => {
    beforeEach(() => {
        routerPost.mockReset();
        routerPatch.mockReset();
    });

    it('shows what each game reads: its selected servers with type and interface, or the env server', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="reads-1"]').text()).toContain('HLL Training');
        expect(wrapper.get('[data-testid="reads-1"]').text()).toContain('Training');
        expect(wrapper.get('[data-testid="reads-1"]').text()).toContain('CRCON');
        expect(wrapper.get('[data-testid="env-2"]').text()).toBe('No server selected: reads the BattleMetrics server set in env.');
    });

    it('shows a configured game with its last read and an older failure, not in red', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="interface-1"]').text()).toBe('CRCON');
        expect(wrapper.get('[data-testid="configured-1"]').text()).toBe('Configured');
        const row = wrapper.get('[data-testid="source-1-crcon"]');
        expect(row.text()).toContain('HLL Training');
        expect(row.text()).toContain('Last read 27OCT26 20 05Z');
        expect(row.text()).toContain('Last failed 27OCT26 19 00Z: crcon: unreachable');
        expect(row.find('.text-destructive').exists()).toBe(false);
    });

    it('shows a game that is down with its typed reason in red', () => {
        const failed = factory().get('[data-testid="source-2-battlemetrics"]').get('.text-destructive');

        expect(failed.text()).toBe('Last failed 27OCT26 23 59Z: battlemetrics: http_error');
    });

    it('shows an unconfigured game and that its credentials are not set', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="configured-3"]').text()).toBe('Not configured');
        expect(wrapper.get('[data-testid="source-3-nightwatch"]').text()).toContain('credentials not set');
        expect(wrapper.get('[data-testid="source-3-nightwatch"]').text()).toContain('Never read');
    });

    it('says plainly a game read by none is marked by hand, with nothing to test', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="interface-4"]').text()).toBe('None');
        expect(wrapper.get('[data-testid="env-4"]').text()).toBe('Attendance read by none: leaders mark it by hand.');
        expect(wrapper.find('[data-testid="test-4"]').exists()).toBe(false);
    });

    it('tests a game\'s connection and shows each outcome under it', async () => {
        const wrapper = factory({
            testResult: {
                gameId: 2,
                outcomes: [
                    { server: 'Arma Reforger server', interface: 'battlemetrics', status: 'failed', reason: 'battlemetrics: unreachable' },
                    { server: 'Spare', interface: 'battlemetrics', status: 'ok' },
                ],
            },
        });

        await wrapper.get('[data-testid="test-1"]').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/settings/servers/games/1/test', {}, expect.objectContaining({ preserveScroll: true }));
        expect(wrapper.get('[data-testid="test-1"]').text()).toBe('Testing');

        const result = wrapper.get('[data-testid="test-result-2"]').text();
        expect(result).toContain('Arma Reforger server (BattleMetrics): could not be read. battlemetrics: unreachable');
        expect(result).toContain('Spare (BattleMetrics): answered.');
        expect(wrapper.find('[data-testid="test-result-1"]').exists()).toBe(false);
    });

    it('says no server is selected, not that credentials are missing, when only the server ID is', () => {
        const games = [{ ...GAMES[1], captureConfigured: false, connections: [{ label: 'Arma Reforger server', sources: [source({ interface: 'battlemetrics', configured: false, noServer: true })] }] }];
        const wrapper = factory({
            games,
            testResult: { gameId: 2, outcomes: [{ server: 'Arma Reforger server', interface: 'battlemetrics', status: 'no_server' }] },
        });

        const line = wrapper.get('[data-testid="source-2-battlemetrics"]').text();
        expect(line).toContain('no server selected: select a training server');
        expect(line).not.toContain('credentials not set');
        expect(wrapper.get('[data-testid="test-result-2"]').text()).toContain('Arma Reforger server (BattleMetrics): no server selected, not read. Select a training server for this game.');
    });

    it('shows a failed test with its HTTP status and what to check', () => {
        const wrapper = factory({
            testResult: { gameId: 2, outcomes: [{ server: 'Arma Reforger server', interface: 'battlemetrics', status: 'failed', reason: 'battlemetrics: http_error (HTTP 401). The key was rejected.' }] },
        });

        expect(wrapper.get('[data-testid="test-result-2"]').text()).toContain('could not be read. battlemetrics: http_error (HTTP 401). The key was rejected.');
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
        routerPatch.mockImplementation((url, data, options) => options.onError({ server: 'Choose the game this server is for before selecting it.' }));
        const wrapper = factory();

        await wrapper.get('[data-testid="selected-3"]').setValue(true);
        await wrapper.get('[data-testid="save-3"]').trigger('click');
        await nextTick();

        expect(wrapper.get('[data-testid="row-error-3"]').text()).toBe('Choose the game this server is for before selecting it.');
    });

    it('fetches the servers and reports what each interface did', async () => {
        const wrapper = factory({
            fetchResult: [
                { interface: 'crcon', status: 'listed', listed: 1, added: 0, missing: 0 },
                { interface: 'battlemetrics', status: 'failed', message: 'BattleMetrics answered with an error (HTTP 503).' },
                { interface: 'nightwatch', status: 'not_configured' },
            ],
        });
        const empty = factory({ fetchResult: [{ interface: 'nightwatch', status: 'empty' }] });

        expect(empty.get('[data-testid="fetch-nightwatch"]').text()).toBe('Nightwatch: listed no servers, so its stored servers were left as they are.');

        expect(wrapper.get('[data-testid="fetch-crcon"]').text()).toBe('CRCON: 1 listed.');
        expect(wrapper.get('[data-testid="fetch-battlemetrics"]').text()).toContain('could not be read. BattleMetrics answered with an error (HTTP 503). Stored servers were left as they are.');
        expect(wrapper.get('[data-testid="fetch-nightwatch"]').text()).toBe('Nightwatch: credentials not set, not asked.');

        await wrapper.get('[data-testid="fetch-servers"]').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/settings/servers/fetch', {}, expect.any(Object));
    });

    it('keeps unsaved edits in other rows when one row is saved and the list comes back', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="type-3"]').setValue('training');

        // Row 2 was saved as training; row 3's edit has not been saved yet.
        await wrapper.setProps({ servers: SERVERS.map((s) => (s.id === 2 ? { ...s, type: 'training' } : s)) });

        expect(wrapper.get('[data-testid="type-3"]').element.value).toBe('training');
        expect(wrapper.get('[data-testid="type-2"]').element.value).toBe('training');
        expect(wrapper.get('[data-testid="save-2"]').attributes('disabled')).toBeDefined();
    });

    it('gives a newly fetched server a row once the list comes back', async () => {
        const wrapper = factory();
        await wrapper.setProps({ servers: [...SERVERS, server(4, { name: 'New one' })] });

        expect(wrapper.get('[data-testid="server-row-4"]').text()).toContain('New one');
        expect(wrapper.get('[data-testid="save-4"]').attributes('disabled')).toBeDefined();
    });
});
