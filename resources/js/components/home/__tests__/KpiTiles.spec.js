import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import KpiTiles from '../KpiTiles.vue';

const kpis = (strength, rc, sp, atRisk = 0, onLoa = 0, makeups = 0) => ({
    strength,
    rc: { numerator: rc[0], denominator: rc[1] },
    sp: { numerator: sp[0], denominator: sp[1] },
    atRisk,
    onLoa,
    makeups,
});

const ROSTER = { source: 'milpac', syncedAt: '2026-10-08T09:14:00+00:00', state: 'ok' };
const empty = [
    { week: '2026-09-05', kpis: null },
    { week: '2026-09-12', kpis: null },
    { week: '2026-09-19', kpis: null },
    { week: '2026-09-26', kpis: null },
];
const full = [
    { week: '2026-09-05', kpis: kpis(20, [60, 80], [30, 40]) },
    { week: '2026-09-12', kpis: kpis(21, [64, 84], [30, 40], 2) },
    { week: '2026-09-19', kpis: kpis(21, [70, 84], [31, 40], 1) },
    { week: '2026-09-26', kpis: kpis(22, [74, 88], [32, 40], 1, 1) },
];

const mountTiles = (props) => mount(KpiTiles, { props: { kpis: { current: kpis(24, [88, 96], [36, 40], 3, 2, 4), history: empty, roster: ROSTER, ...props } } });

describe('KpiTiles', () => {
    it('renders the six KPIs in report order with their current values', () => {
        const tiles = mountTiles().findAll('[data-kpi]');

        expect(tiles.map((tile) => tile.find('dt').text())).toEqual(['In roll call', 'RC % · 30d', 'SP % · 30d', 'At-risk', 'On LOA', 'Makeups']);
        expect(tiles.map((tile) => tile.find('dd span').text())).toEqual(['24', '92%', '90%', '3', '2', '4']);
    });

    it('labels the headcount with staff apart from the rollcall figure, on the strength tile only', () => {
        const wrapper = mountTiles({ current: { ...kpis(24, [88, 96], [36, 40]), headcount: 31 } });

        expect(wrapper.find('[data-kpi="strength"] dt').text()).toBe('In roll call');
        expect(wrapper.find('[data-kpi="strength"] dd span').text()).toBe('24');
        expect(wrapper.find('[data-testid="headcount"]').text()).toBe('Headcount 31, with staff');
        expect(wrapper.findAll('[data-testid="headcount"]')).toHaveLength(1);
    });

    it('draws no headcount when the server sent none', () => {
        expect(mountTiles().find('[data-testid="headcount"]').exists()).toBe(false);
    });

    it('shows a dash, never 0%, for a rate with nothing eligible', () => {
        const wrapper = mountTiles({ current: kpis(0, [0, 0], [0, 0]) });

        expect(wrapper.find('[data-kpi="rc"] dd span').text()).toBe('—');
        expect(wrapper.find('[data-kpi="sp"] dd span').text()).toBe('—');
    });

    it('says it is building history, and draws no line, when no week has a sent snapshot', () => {
        const wrapper = mountTiles();

        expect(wrapper.findAll('[data-testid="building-history"]')).toHaveLength(6);
        expect(wrapper.find('[data-testid="sparkline"]').exists()).toBe(false);
    });

    it('draws a sparkline per tile once sent weeks exist, labelled with every value', () => {
        const wrapper = mountTiles({ history: full });

        expect(wrapper.findAll('[data-testid="sparkline"]')).toHaveLength(6);
        expect(wrapper.find('[data-kpi="rc"] [data-testid="sparkline"]').attributes('aria-label')).toBe(
            'RC % · 30d over the last 4 weeks: 75%, 76%, 83%, 84%, 92%',
        );
    });

    it('keeps a gap for a week with no sent snapshot instead of inventing a value', () => {
        const history = [full[0], { week: '2026-09-12', kpis: null }, full[2], full[3]];
        const label = mountTiles({ history }).find('[data-kpi="strength"] [data-testid="sparkline"]').attributes('aria-label');

        expect(label).toBe('In roll call over the last 4 weeks: 20, no sent report, 21, 22, 24');
    });

    it('colours a change by whether it is good for that KPI', () => {
        const wrapper = mountTiles({ history: full });

        // Strength 22 to 24 is up and good; at-risk 1 to 3 is up and bad.
        expect(wrapper.find('[data-kpi="strength"] [data-testid="delta"]').text()).toContain('+2');
        expect(wrapper.find('[data-kpi="strength"] [data-testid="delta"]').classes().join(' ')).toContain('text-success');
        expect(wrapper.find('[data-kpi="atRisk"] [data-testid="delta"]').classes().join(' ')).toContain('text-destructive');
    });

    describe('leave source and freshness (4.3, AD-20)', () => {
        it('names the leave source and last sync under the tiles, since on LOA and at-risk move with it', () => {
            const wrapper = mountTiles({ loaSync: { source: 'Forum thread', lastSyncedAt: '2026-10-08T09:14:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T09:14:00+00:00' } });

            expect(wrapper.get('[data-testid="loa-sync"]').text()).toBe('Leave from Forum thread, synced 08OCT26 09\u200A14Z');
        });

        it('warns when the leave sync is stale', () => {
            const wrapper = mountTiles({ loaSync: { ...{ source: 'Forum thread', lastSyncedAt: '2026-10-08T09:14:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T09:14:00+00:00' }, stale: true, latestStatus: 'failure' } });

            expect(wrapper.get('[data-testid="loa-sync"]').attributes('role')).toBe('status');
        });
    });

    describe('roster source and freshness (AD-20)', () => {
        beforeEach(() => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date('2026-10-08T11:20:00Z'));
        });
        afterEach(() => vi.useRealTimers());

        const note = (roster) => mountTiles({ roster }).find('[data-testid="roster-freshness"]');

        it('names the source and the last successful sync, with how long ago it was', () => {
            expect(note(ROSTER).text()).toBe('Roster synced from milpac 08OCT26 09\u200A14Z (2 h ago)');
            expect(note(ROSTER).find('time').attributes('datetime')).toBe('2026-10-08T09:14:00.000Z');
            expect(note(ROSTER).attributes('role')).toBeUndefined();
        });

        it.each([
            ['stale', 'Roster last synced from milpac 08OCT26 09\u200A14Z (2 h ago), overdue'],
            ['partial', 'Last roster sync was incomplete. Roll call figures are from 08OCT26 09\u200A14Z (2 h ago)'],
            ['failed', 'Last roster sync failed. Roll call figures are from 08OCT26 09\u200A14Z (2 h ago)'],
        ])('surfaces a %s sync as a warning, not a silent gap', (state, text) => {
            const el = note({ ...ROSTER, state });

            expect(el.text()).toContain(text);
            expect(el.attributes('role')).toBe('status');
            expect(el.classes()).toContain('text-warning');
        });

        it('says so when the roster has never synced', () => {
            expect(note({ source: 'milpac', syncedAt: null, state: 'never' }).text()).toBe('The roster has not synced yet');
        });
    });
});
