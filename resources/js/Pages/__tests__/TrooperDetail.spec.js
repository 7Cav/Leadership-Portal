import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TrooperDetail from '../TrooperDetail.vue';

// Stories 6.3 to 6.5: the milpac draws what the server shaped; it decides nothing about who may look.

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
    Head: { template: '<head></head>' },
}));

const trooper = (overrides = {}) => ({
    id: 7,
    cavId: 4374,
    displayName: 'SPC Milkovic.D',
    username: 'Milkovic.D',
    rank: 'Specialist',
    rankShort: 'SPC',
    billet: 'Assistant Section Leader',
    mos: '11B',
    status: 'combat',
    departed: false,
    tis: '2025-01-14',
    tisMonths: 20,
    tig: '2026-04-08',
    tigMonths: 6,
    section: { id: 3, name: 'Nightriders', callsign: '1/1/E/2-7' },
    milpacUrl: 'https://7cav.us/rosters/profile?uniqueid=4374',
    ...overrides,
});

const courses = (overrides = {}) => ({
    groups: [
        { status: 'qualified', label: 'Qualified', courses: [{ code: 'BCT', name: 'Basic Combat Training', awardedAt: '2025-02-02' }, { code: 'NCOA-1', name: 'NCOA Phase I', awardedAt: null }] },
        { status: 'in_progress', label: 'In progress', courses: [{ code: 'NCOA-2', name: 'NCOA Phase II', awardedAt: null }] },
        { status: 'required', label: 'Required', courses: [{ code: 'ODS', name: 'Officer Development School', awardedAt: null }] },
    ],
    qualified: 2,
    total: 4,
    ...overrides,
});

const awards = (items = [], available = true) => ({ available, items });

const sync = (overrides = {}) => ({ source: 'Cav roster', lastSuccessAt: '2026-10-08T09:30:00+00:00', stale: false, lastAttempt: null, ...overrides });

const page = (props = {}) => mount(TrooperDetail, { props: { trooper: trooper(), courses: courses(), awards: awards(), sync: sync(), ...props } });

describe('TrooperDetail', () => {
    describe('ID block (6.3)', () => {
        it('shows the name, billet, section, profile id and a link to the Cav milpac', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="trooper-name"]').text()).toBe('SPC Milkovic.D');
            expect(wrapper.get('[data-testid="trooper-subtitle"]').text()).toContain('Assistant Section Leader');
            expect(wrapper.get('[data-testid="trooper-subtitle"]').text()).toContain('1/1/E/2-7 Nightriders');
            expect(wrapper.get('[data-testid="trooper-subtitle"]').text()).toContain('Profile #4374');
            expect(wrapper.get('[data-testid="milpac-link"]').attributes('href')).toBe('https://7cav.us/rosters/profile?uniqueid=4374');
            expect(wrapper.get('[data-testid="milpac-link"]').attributes('rel')).toContain('noopener');
            expect(wrapper.get('[data-testid="milpac-link"]').text()).toContain('opens in a new tab');
        });

        it('shows rank, MOS, join date in forum convention and the status', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="rank"]').text()).toBe('Specialist');
            expect(wrapper.get('[data-testid="mos"]').text()).toBe('11B');
            expect(wrapper.get('[data-testid="joined"]').text()).toBe('14Jan25');
            expect(wrapper.get('[data-testid="status"]').text()).toBe('Active');
        });

        it('shows time in service and in grade as years and months', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="tis"]').text()).toBe('1y 8m');
            expect(wrapper.get('[data-testid="tig"]').text()).toBe('6m');
        });

        it.each([
            [0, 'Under a month'],
            [12, '1y'],
            [25, '2y 1m'],
            [null, 'Unknown'],
        ])('reads %s months as "%s"', (months, text) => {
            expect(page({ trooper: trooper({ tisMonths: months }) }).get('[data-testid="tis"]').text()).toBe(text);
        });

        it('says explicitly when the trooper is not placed in a section', () => {
            const wrapper = page({ trooper: trooper({ section: null }) });

            expect(wrapper.get('[data-testid="unmapped"]').text()).toContain('Not placed in a section yet');
            expect(wrapper.get('[data-testid="trooper-subtitle"]').text()).not.toContain('Nightriders');
        });

        it('says so when there is no billet, MOS or join date, rather than leaving a gap', () => {
            const wrapper = page({ trooper: trooper({ billet: null, mos: null, tis: null, tisMonths: null }) });

            expect(wrapper.get('[data-testid="billet"]').text()).toBe('No billet on record');
            expect(wrapper.get('[data-testid="mos"]').text()).toBe('None');
            expect(wrapper.find('[data-testid="joined"]').exists()).toBe(false);
        });

        it('shows a departed trooper as departed', () => {
            expect(page({ trooper: trooper({ status: 'inactive', departed: true }) }).get('[data-testid="status"]').text()).toBe('Departed');
        });

        it('shows a departed trooper as an outlined badge, not an error', () => {
            const badge = page({ trooper: trooper({ status: 'inactive', departed: true }) }).get('[data-testid="status"]');

            expect(badge.classes().join(' ')).not.toContain('danger');
            expect(badge.find('svg').exists()).toBe(true);
        });

        it('shows reserve and ELOA troopers by their roster status', () => {
            expect(page({ trooper: trooper({ status: 'reserve' }) }).get('[data-testid="status"]').text()).toBe('Reserve');
            expect(page({ trooper: trooper({ status: 'eloa' }) }).get('[data-testid="status"]').text()).toBe('ELOA');
        });
    });

    describe('page structure', () => {
        it('names the person once: the h1 is the name and the card heading is the billet', () => {
            const wrapper = page();

            expect(wrapper.findAll('h1')).toHaveLength(1);
            expect(wrapper.get('h2').text()).toBe('Assistant Section Leader');
        });

        it('links back to the roster of the trooper\'s section, or to the roster when unplaced', () => {
            expect(page().get('[data-testid="back-to-roster"]').attributes('href')).toBe('/roster/3');
            expect(page().get('[data-testid="back-to-roster"]').text()).toContain('Back to 1/1/E/2-7 Nightriders');
            expect(page({ trooper: trooper({ section: null }) }).get('[data-testid="back-to-roster"]').attributes('href')).toBe('/roster');
        });

        it('opens the Cav milpac as "View on 7cav.us"', () => {
            expect(page().get('[data-testid="milpac-link"]').text()).toContain('View on 7cav.us');
        });
    });

    describe('avatar', () => {
        it.each([
            ['Milkovic.D', 'MD'],
            ['Perry.JW', 'PJ'],
            ['Madonna', 'M'],
            [null, '?'],
        ])('draws %s as %s', (username, letters) => {
            const wrapper = page({ trooper: trooper({ username }) });

            expect(wrapper.get('[data-testid="id-block"] [data-slot="avatar"]').text()).toBe(letters);
        });
    });

    describe('source and freshness (AD-20)', () => {
        it('names the source and the last successful sync in forum convention', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="source-line"]').text()).toContain('Source: Cav roster');
            expect(wrapper.get('[data-testid="last-synced"]').text()).toBe('08Oct26 09:30 UTC');
            expect(wrapper.find('[data-testid="stale-notice"]').exists()).toBe(false);
        });

        it('says the record is the last good copy when a later sync failed', () => {
            const wrapper = page({ sync: sync({ stale: true, lastAttempt: { status: 'failure', at: '2026-10-08T11:00:00+00:00' } }) });

            expect(wrapper.get('[data-testid="stale-notice"]').text()).toContain('failed on 08Oct26 11:00 UTC');
            expect(wrapper.get('[data-testid="stale-notice"]').text()).toContain('last successful sync');
        });

        it('says a partial run was only partly applied', () => {
            const wrapper = page({ sync: sync({ stale: true, lastAttempt: { status: 'partial', at: '2026-10-08T11:00:00+00:00' } }) });

            expect(wrapper.get('[data-testid="stale-notice"]').text()).toContain('only partly applied');
        });

        it('says never synced, with a warning, when no sync has succeeded', () => {
            const wrapper = page({ sync: sync({ lastSuccessAt: null }) });

            expect(wrapper.get('[data-testid="source-line"]').text()).toContain('never synced');
            expect(wrapper.get('[data-testid="stale-notice"]').text()).toContain('No Cav roster sync has succeeded yet');
        });
    });

    describe('courses and qualifications (6.4)', () => {
        it('lists the groups in order, with each course by name and code', () => {
            const wrapper = page();
            const groups = wrapper.findAll('section[data-testid^="group-"]');

            expect(groups.map((group) => group.attributes('data-testid'))).toEqual(['group-qualified', 'group-in_progress', 'group-required']);
            expect(groups[0].text()).toContain('Basic Combat Training');
            expect(groups[0].text()).toContain('BCT');
            expect(groups[2].text()).toContain('Officer Development School');
        });

        it('shows the award date of a qualified course in forum convention, and none when absent', () => {
            const wrapper = page();
            const rows = wrapper.get('[data-testid="group-qualified"]').findAll('[data-testid="course"]');

            expect(rows[0].get('[data-testid="awarded"]').text()).toBe('02Feb25');
            expect(rows[1].find('[data-testid="awarded"]').exists()).toBe(false);
        });

        it('shows how many courses are qualified out of those on record', () => {
            expect(page().get('[data-testid="courses-count"]').text()).toBe('2 / 4');
        });

        it('names the count for screen readers and does not paint progress as a warning', () => {
            const badge = page().get('[data-testid="courses-count"]');

            expect(badge.attributes('aria-label')).toBe('2 of 4 qualified');
            expect(badge.classes().join(' ')).not.toContain('warning');
        });

        it('draws the panel with the shared milpac panel header', () => {
            expect(page().get('[data-testid="courses-panel"] h2').text()).toBe('Courses & qualifications');
        });

        it('suppresses a group that has nothing in it', () => {
            const wrapper = page({ courses: courses({ groups: [courses().groups[0]], total: 2 }) });

            expect(wrapper.find('[data-testid="group-in_progress"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="group-required"]').exists()).toBe(false);
        });

        it('shows an explicit empty state, not a blank panel, when there are no courses', () => {
            const wrapper = page({ courses: courses({ groups: [], qualified: 0, total: 0 }) });

            expect(wrapper.get('[data-testid="courses-empty"]').text()).toBe('No courses on record.');
            expect(wrapper.find('[data-testid="courses-count"]').exists()).toBe(false);
        });
    });

    it('has no controls that write: the milpac is read-only', () => {
        const wrapper = page();

        expect(wrapper.findAll('button, input, select, textarea, form')).toHaveLength(0);
    });

    describe('awards (6.5)', () => {
        const earned = [
            { name: 'Army Achievement Medal', awardedAt: '2026-01-18', citation: 'Sustained SP attendance and mentoring.' },
            { name: 'Good Conduct Medal', awardedAt: null, citation: null },
        ];

        it('lists the earned awards with their dates and citations', () => {
            const wrapper = page({ awards: awards(earned) });
            const rows = wrapper.findAll('[data-testid="award"]');

            expect(rows).toHaveLength(2);
            expect(rows[0].text()).toContain('Army Achievement Medal');
            expect(rows[0].get('[data-testid="award-date"]').text()).toBe('18Jan26');
            expect(rows[0].get('[data-testid="award-citation"]').text()).toBe('Sustained SP attendance and mentoring.');
            expect(wrapper.get('[data-testid="awards-count"]').text()).toBe('2');
            expect(wrapper.find('[data-testid="awards-empty"]').exists()).toBe(false);
        });

        it('leaves out the date and citation an award does not have', () => {
            const row = page({ awards: awards(earned) }).findAll('[data-testid="award"]')[1];

            expect(row.find('[data-testid="award-date"]').exists()).toBe(false);
            expect(row.find('[data-testid="award-citation"]').exists()).toBe(false);
        });

        it('says plainly when a source holds no awards for the trooper', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="awards-empty"]').text()).toBe('No awards on record.');
            expect(wrapper.find('[data-testid="award"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="awards-count"]').exists()).toBe(false);
        });

        it('says the awards are not synced, rather than none, when there is no source yet', () => {
            const wrapper = page({ awards: awards([], false) });

            expect(wrapper.find('[data-testid="awards-empty"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="awards-unavailable"]').text()).toContain('not synced from the Cav roster yet');
            expect(wrapper.get('[data-testid="awards-milpac-link"]').attributes('href')).toBe('https://7cav.us/rosters/profile?uniqueid=4374');
        });

        it('names the count for screen readers and never offers to change an award', () => {
            const panel = page({ awards: awards(earned) }).get('[data-testid="awards-panel"]');

            expect(panel.get('[data-testid="awards-count"]').attributes('aria-label')).toBe('2 awards');
            expect(panel.findAll('button, input, select, textarea, form, a[href]')).toHaveLength(0);
        });
    });
});
