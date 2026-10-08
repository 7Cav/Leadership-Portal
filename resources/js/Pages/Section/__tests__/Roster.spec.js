import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Roster from '../Roster.vue';

const getSpy = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; stub it like the other page specs.
    Head: { template: '<head></head>' },
    router: { get: (...args) => getSpy(...args) },
}));

const SECTION = { id: 11, name: '2nd Section', callsign: '2/1/A/3-7' };
const SECTIONS = [
    { id: 4, name: '1st Section', callsign: '1/1/A/3-7' },
    SECTION,
];
const SYNC = {
    source: 'Cav roster',
    lastSyncedAt: '2026-10-08T14:00:00+00:00',
    stale: false,
    latestStatus: 'success',
    latestFinishedAt: '2026-10-08T14:00:00+00:00',
};
const trooper = (overrides = {}) => ({
    id: 1,
    username: 'Hollis.D',
    cav_id: 3099,
    rank: 'Private',
    mos: '11B',
    status: 'combat',
    placed_by_hand: false,
    ...overrides,
});

const mountRoster = (props = {}) =>
    mount(Roster, { props: { section: SECTION, sections: SECTIONS, troopers: [trooper()], sync: SYNC, ...props } });

describe('Section/Roster', () => {
    it('shows the section, and each trooper with rank, name, MOS and status', () => {
        const wrapper = mountRoster();

        expect(wrapper.find('h1').text()).toContain('2nd Section');
        expect(wrapper.find('h1').text()).toContain('2/1/A/3-7');
        const row = wrapper.find('[data-testid="trooper-row"]');
        expect(row.text()).toContain('Private');
        expect(row.text()).toContain('Hollis.D');
        expect(row.text()).toContain('11B');
        expect(row.find('[data-testid="status"]').text()).toBe('Combat');
    });

    it('names the source and the last successful sync time', () => {
        const wrapper = mountRoster();

        expect(wrapper.find('[data-testid="source-line"]').text()).toContain('Cav roster');
        expect(wrapper.find('[data-testid="last-synced"]').text()).toBe('08Oct26 14:00 UTC');
        expect(wrapper.find('[data-testid="stale-notice"]').exists()).toBe(false);
    });

    it('says the roster is stale after a failed sync, and still shows the last good time and the troopers', () => {
        const wrapper = mountRoster({
            sync: { ...SYNC, stale: true, latestStatus: 'failure', latestFinishedAt: '2026-10-08T16:00:00+00:00' },
        });

        const notice = wrapper.find('[data-testid="stale-notice"]').text();
        expect(notice).toContain('failed');
        expect(notice).toContain('08Oct26 16:00 UTC');
        // The last-synced stamp is the last success, not the failed attempt.
        expect(wrapper.find('[data-testid="last-synced"]').text()).toBe('08Oct26 14:00 UTC');
        expect(wrapper.findAll('[data-testid="trooper-row"]')).toHaveLength(1);
    });

    it('words a partial sync differently from a failed one', () => {
        const wrapper = mountRoster({ sync: { ...SYNC, stale: true, latestStatus: 'partial', latestFinishedAt: '2026-10-08T16:00:00+00:00' } });

        expect(wrapper.find('[data-testid="stale-notice"]').text()).toContain('only partly applied');
    });

    it('says so when the roster has never been synced', () => {
        const wrapper = mountRoster({
            troopers: [],
            sync: { source: 'Cav roster', lastSyncedAt: null, stale: false, latestStatus: null, latestFinishedAt: null },
        });

        expect(wrapper.find('[data-testid="source-line"]').text()).toContain('never synced');
        expect(wrapper.find('[data-testid="stale-notice"]').text()).toContain('has not been synced yet');
        expect(wrapper.find('[data-testid="stale-notice"]').text()).toContain('may be empty or out of date');
    });

    it('distinguishes a departed trooper rather than dropping them, and counts them apart', () => {
        const wrapper = mountRoster({ troopers: [trooper(), trooper({ id: 2, username: 'Vega.R', status: 'inactive' })] });

        const rows = wrapper.findAll('[data-testid="trooper-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[1].find('[data-testid="status"]').text()).toBe('Departed');
        expect(wrapper.find('[data-testid="counts"]').text()).toContain('1 trooper, 1 departed');
    });

    it('labels Reserve and ELOA troopers', () => {
        const wrapper = mountRoster({ troopers: [trooper({ status: 'reserve' }), trooper({ id: 2, status: 'eloa' })] });

        const labels = wrapper.findAll('[data-testid="status"]').map((badge) => badge.text());
        expect(labels).toEqual(['Reserve', 'ELOA']);
    });

    it('falls back for a trooper with no username, rank or MOS', () => {
        const wrapper = mountRoster({ troopers: [trooper({ username: null, rank: null, mos: null })] });

        const row = wrapper.find('[data-testid="trooper-row"]').text();
        expect(row).toContain('Unknown (Cav #3099)');
        expect(row.match(/None/g)).toHaveLength(2);
    });

    it('marks a trooper an Admin placed by hand', () => {
        const wrapper = mountRoster({ troopers: [trooper({ placed_by_hand: true }), trooper({ id: 2, username: 'Vega.R' })] });

        expect(wrapper.findAll('[data-testid="by-hand"]')).toHaveLength(1);
    });

    it('shows an empty state for a section with nobody placed in it', () => {
        const wrapper = mountRoster({ troopers: [] });

        expect(wrapper.text()).toContain('No troopers placed here yet');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('offers a switcher between sections only when there is more than one', () => {
        expect(mountRoster().find('[data-testid="section-switcher"]').exists()).toBe(true);
        expect(mountRoster({ sections: [SECTION] }).find('[data-testid="section-switcher"]').exists()).toBe(false);
    });

    it('opens the chosen section', async () => {
        const wrapper = mountRoster();

        await wrapper.find('[data-testid="section-switcher"]').setValue('4');

        expect(getSpy).toHaveBeenCalledWith('/roster/4');
    });

    it('explains the empty scope when the viewer has no section', () => {
        const wrapper = mountRoster({ section: null, sections: [], troopers: [], sync: null });

        expect(wrapper.text()).toContain('No section in your scope yet');
        expect(wrapper.find('h1').exists()).toBe(false);
    });
});
