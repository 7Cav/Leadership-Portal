import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Connections from '../Connections.vue';

const routerPost = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { post: (...args) => routerPost(...args) },
}));

const INTERFACES = [
    { value: 'crcon', label: 'CRCON' },
    { value: 'battlemetrics', label: 'BattleMetrics' },
    { value: 'nightwatch', label: 'Nightwatch' },
    { value: 'none', label: 'None' },
];
const source = (extra = {}) => ({ interface: 'crcon', configured: true, lastSucceededAt: null, lastFailedAt: null, lastFailureReason: null, ...extra });
const GAMES = [
    // Configured, read a minute ago, after an earlier failure.
    {
        id: 1, name: 'Hell Let Loose', retired: false, interface: 'crcon', configured: true,
        servers: [{ label: 'HLL Training', sources: [source({ lastSucceededAt: '2026-10-27T20:05:00+00:00', lastFailedAt: '2026-10-27T19:00:00+00:00', lastFailureReason: 'crcon: unreachable' })] }],
    },
    // Down: its last read failed.
    {
        id: 2, name: 'Arma Reforger', retired: false, interface: 'battlemetrics', configured: true,
        servers: [{ label: 'Arma Reforger server', sources: [source({ interface: 'battlemetrics', lastSucceededAt: '2026-10-26T20:00:00+00:00', lastFailedAt: '2026-10-27T23:59:00+00:00', lastFailureReason: 'battlemetrics: http_error' })] }],
    },
    // Unconfigured: the credentials are not set.
    {
        id: 3, name: 'WARDOGS', retired: false, interface: 'nightwatch', configured: false,
        servers: [{ label: 'WARDOGS server', sources: [source({ interface: 'nightwatch', configured: false })] }],
    },
    // Read by none.
    { id: 4, name: 'Hell Let Loose: Vietnam', retired: false, interface: 'none', configured: false, servers: [] },
];

const factory = (props = {}) => mount(Connections, { props: { games: GAMES, interfaces: INTERFACES, ...props } });

describe('Settings/Connections', () => {
    beforeEach(() => routerPost.mockReset());

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

        expect(wrapper.get('[data-testid="none-4"]').text()).toBe('Attendance read by none: leaders mark it by hand.');
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
        expect(routerPost).toHaveBeenCalledWith('/settings/connections/1/test', {}, expect.objectContaining({ preserveScroll: true }));
        expect(wrapper.get('[data-testid="test-1"]').text()).toBe('Testing');

        const result = wrapper.get('[data-testid="test-result-2"]').text();
        expect(result).toContain('Arma Reforger server (BattleMetrics): could not be read. battlemetrics: unreachable');
        expect(result).toContain('Spare (BattleMetrics): answered.');
        expect(wrapper.find('[data-testid="test-result-1"]').exists()).toBe(false);
    });
});
