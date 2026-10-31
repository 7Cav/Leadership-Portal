import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Home from '../Home.vue';

// Why enrichment produced nothing this login (Story 1.7); null when enrichment succeeded or never
// ran degraded. Identity, rank, provenance and Sign out moved to the app shell in Story 1.15 and
// are covered by Layouts/__tests__/AppLayout.spec.js.
let mockProfileUnavailable = null;
const reload = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
    Head: { template: '<head></head>' },
    router: { reload: (...args) => reload(...args) },
    usePage: () => ({
        props: { auth: { user: { username: 'Hale.S' }, profile: null, profileUnavailable: mockProfileUnavailable } },
    }),
}));

// The week HomeController sends for Thu 08Oct26: it opened Sat 03Oct26 00:00Z and its report is
// due Sat 10Oct26 23:59Z (HomeWeekTest pins the server side).
const WEEK = { start: '2026-10-03T00:00:00+00:00', reportDue: '2026-10-10T23:59:00+00:00' };

describe('Home page', () => {
    const factory = (profileUnavailable = null) => {
        mockProfileUnavailable = profileUnavailable;

        return mount(Home, { props: { week: WEEK } });
    };

    beforeEach(() => {
        reload.mockReset();
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-08T16:20:30Z'));
    });

    afterEach(() => vi.useRealTimers());

    describe('week bar (real dates, no mock data)', () => {
        it('titles the week by its Friday, its end date, in forum date convention', () => {
            expect(factory().find('h1').text()).toBe('Week Ending 09Oct26');
        });

        it('lays out Saturday to Friday plus the Saturday the report is due', () => {
            const days = factory().findAll('ol[aria-label="Days of the week"] li');

            expect(days.map((day) => day.find('span').text())).toEqual(['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);
            expect(days[7].text()).toContain('10');
        });

        it('marks today, and only today', () => {
            const wrapper = factory();
            const todays = wrapper.findAll('ol[aria-label="Days of the week"] [aria-current="date"]');

            expect(todays).toHaveLength(1);
            expect(todays[0].text()).toContain('Thu');
            expect(wrapper.find('[data-testid="week-bar"]').text()).toContain('Today');
        });

        it('counts down to the report deadline in UTC', () => {
            const wrapper = factory();

            expect(wrapper.text()).toContain('Report due Sat 10Oct26 23:59Z');
            expect(wrapper.find('[data-testid="countdown"]').text()).toBe('2d 07h 38m left');
        });

        it('keeps the countdown ticking', async () => {
            const wrapper = factory();

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(wrapper.find('[data-testid="countdown"]').text()).toBe('2d 06h 38m left');
        });

        it('says the deadline has passed, never a negative time', () => {
            vi.setSystemTime(new Date('2026-10-11T00:00:30Z'));

            expect(factory().find('[data-testid="countdown"]').text()).toBe('Deadline passed');
        });

        it('fetches the next cycle once when the deadline passes in an open tab', async () => {
            vi.setSystemTime(new Date('2026-10-10T23:58:00Z'));
            factory();

            await vi.advanceTimersByTimeAsync(30_000);
            expect(reload).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(90_000);
            expect(reload).toHaveBeenCalledTimes(1);
            // Every prop is that week's: the steps, worklist, KPIs and units come with it, not the week bar alone.
            expect(reload.mock.calls[0][0]).not.toHaveProperty('only');
        });
    });

    describe('weekly-cycle areas without data yet', () => {
        it('renders the step lanes, worklist, section progress and KPIs as empty states', () => {
            const text = factory().text();

            expect(text).toContain('Step tracking starts with roll calls');
            expect(text).toContain('Needs attention');
            expect(text).toContain('No sections reporting yet');
            expect(text).toContain('No units in your scope yet');
        });

        it('shows no invented figures: the only numbers on the page are dates and the countdown', () => {
            const wrapper = factory();
            const emptyStates = wrapper.findAll('[data-slot="empty-state"]');

            expect(emptyStates).toHaveLength(5);
            for (const state of emptyStates) {
                expect(state.text()).not.toMatch(/\d/);
            }
        });

        it('keeps the section-progress columns so the empty table reads as the real one will', () => {
            const headers = factory().findAll('th').map((th) => th.text());

            expect(headers).toEqual(['Unit', 'Roll call posted', 'Roll call signed', 'SP held', 'Attendance marked', 'Report sent']);
        });
    });

    // Story 1.7: a Cav outage must never look like silence (AD-20/NFR8).
    describe('KPI tiles (Story 5.9)', () => {
        const kpis = {
            current: { strength: 7, rc: { numerator: 9, denominator: 12 }, sp: { numerator: 0, denominator: 0 }, atRisk: 1, onLoa: 0, makeups: 2 },
            history: [{ week: '2026-09-26', kpis: null }],
            roster: { source: 'milpac', syncedAt: null, state: 'never' },
        };

        it('renders the tiles and worklist instead of the empty states when the viewer has a scope', () => {
            const attention = { items: [], totals: { holding: 0, atRisk: 0 } };
            const units = [{ id: 1, designation: '1/A/1-7', name: '1st Platoon', type: 'platoon', status: 'sent', holding: false, live: false, coverage: null, href: '/reports/1/2026-10-03', kpis: { strength: 7, rc: { numerator: 9, denominator: 12 }, sp: { numerator: 0, denominator: 0 }, atRisk: 1, onLoa: 0, makeups: 2 } }];
            const wrapper = mount(Home, { props: { week: WEEK, kpis, attention, units, steps: [] } });

            expect(wrapper.find('[data-testid="kpi-tiles"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="all-clear"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="subordinate-units"] tbody tr').exists()).toBe(true);
            expect(wrapper.text()).not.toContain('No units in your scope yet');
        });
    });

    describe('staff report', () => {
        it('reminds a platoon or company HQ staff member of their unsent staff report, with a link to it', () => {
            const staffReport = { week: '2026-10-03', due: '2026-10-10T23:59:00+00:00', href: '/staff-report/7/2026-10-03' };
            const notice = mount(Home, { props: { week: WEEK, staffReport } }).find('[data-testid="home-staff-report"]');

            expect(notice.text()).toContain('Your staff report for the week ending 09Oct26 is not sent yet. It is due 10Oct26 23:59Z.');
            expect(notice.find('[data-testid="home-staff-report-link"]').attributes('href')).toBe('/staff-report/7/2026-10-03');
        });

        it('shows nothing when there is no staff report to send', () => {
            expect(factory().find('[data-testid="home-staff-report"]').exists()).toBe(false);
        });
    });

    describe('counseling waiting for the viewer (#565)', () => {
        it('shows one card per record, with the countdown to answer and a link to it', () => {
            const counseling = [
                { id: 42, type: 'lor', typeLabel: 'Letter of Reprimand', issuer: 'Varga.T', respondBy: '2026-10-09T14:00:00Z', href: '/counseling/42' },
                { id: 43, type: 'dcs', typeLabel: 'Developmental Counseling Statement', issuer: 'Pereira.M', respondBy: '2026-10-08T16:00:00Z', href: '/counseling/43' },
            ];
            const cards = mount(Home, { props: { week: WEEK, counseling } }).findAll('[data-testid="home-counseling"]');

            expect(cards).toHaveLength(2);
            expect(cards[0].text()).toContain('Letter of Reprimand from Varga.T · answer by 9 Oct 1400Z (21 h 39 min left)');
            expect(cards[0].find('[data-testid="home-counseling-link"]').attributes('href')).toBe('/counseling/42');
            expect(cards[1].find('[data-testid="home-counseling-left"]').text()).toBe('(time is up)');
        });

        it('shows none when nothing waits', () => {
            expect(factory().find('[data-testid="home-counseling"]').exists()).toBe(false);
        });
    });

    describe('degraded roster enrichment', () => {
        it('shows no warning when enrichment succeeded or never ran degraded (AC #2)', () => {
            expect(factory(null).find('[role="alert"]').exists()).toBe(false);
        });

        it('shows no warning for a trooper who simply holds no milpac', () => {
            expect(factory('not_on_roster').find('[role="alert"]').exists()).toBe(false);
        });

        it('warns when the Cav roster is unavailable, without a dismiss control (AC #2)', () => {
            const banner = factory('unreachable').find('[role="alert"]');

            expect(banner.exists()).toBe(true);
            expect(banner.text().toLowerCase()).toContain('roster');
            expect(banner.find('button').exists()).toBe(false);
        });

        it.each(['record_unusable', 'http_error', 'malformed', 'missing_credential', 'link_failed', 'unexpected'])(
            'warns for every fault reason the server can report (%s)',
            (reason) => {
                expect(factory(reason).find('[role="alert"]').exists()).toBe(true);
            },
        );
    });
});
