import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TrooperDetail from '../TrooperDetail.vue';

// Stories 6.3 to 6.7: the milpac draws what the server shaped; it decides nothing about who may look.

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
    Head: { template: '<head></head>' },
    usePage: () => ({ props: { forum: { baseUrl: 'https://7cav.us' } } }),
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

const timeline = (entries = [], legend = []) => ({ entries, legend });

const sync = (overrides = {}) => ({ source: 'milpac', lastSuccessAt: '2026-10-08T09:30:00+00:00', stale: false, lastAttempt: null, ...overrides });

const page = (props = {}) => mount(TrooperDetail, { props: { trooper: trooper(), courses: courses(), awards: awards(), timeline: timeline(), actions: [], sync: sync(), ...props } });

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

            expect(wrapper.get('[data-testid="source-line"]').text()).toContain('Source: milpac');
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

        it('says the sync is overdue when the last good copy is too old and nothing failed', () => {
            const wrapper = page({ sync: sync({ stale: true, lastAttempt: null }) });

            expect(wrapper.get('[data-testid="stale-notice"]').text()).toContain('has not synced recently');
            expect(wrapper.get('[data-testid="stale-notice"]').text()).not.toContain('failed');
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

        // The same award can be held more than once, with no date on either (repeat Good Conduct Medals).
        it('lists a repeated award once per time it was earned, without a duplicate-key warning', async () => {
            const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
            const repeat = { name: 'Good Conduct Medal', awardedAt: null, citation: null };
            const wrapper = page({ awards: awards([{ name: 'Army Achievement Medal', awardedAt: '2026-01-18', citation: null }]) });
            // Vue checks keys when it patches a list, so update it rather than only mounting.
            await wrapper.setProps({ awards: awards([repeat, { ...repeat }]) });

            expect(wrapper.findAll('[data-testid="award"]')).toHaveLength(2);
            expect(warn.mock.calls.flat().join(' ')).not.toContain('Duplicate keys');
            warn.mockRestore();
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

    describe('service record timeline (6.6)', () => {
        const entries = [
            { type: 'course', date: '2026-03-04', title: 'Squad Advanced Course', detail: 'SAC' },
            { type: 'promotion', date: '2025-11-22', title: 'Current rank: Specialist', detail: null },
        ];
        const legend = [
            { type: 'promotion', label: 'Rank' },
            { type: 'course', label: 'Course' },
        ];

        it('draws each event in the order it was given, with a forum-convention date', () => {
            const wrapper = page({ timeline: timeline(entries, legend) });
            const rows = wrapper.findAll('li[data-testid^="event-"]');

            expect(rows).toHaveLength(2);
            expect(rows[0].get('[data-testid="event-date"]').text()).toBe('04Mar26');
            expect(rows[0].get('[data-testid="event-title"]').text()).toBe('Course: Squad Advanced Course');
            expect(rows[0].get('[data-testid="event-detail"]').text()).toBe('SAC');
            expect(rows[1].get('[data-testid="event-date"]').text()).toBe('22Nov25');
        });

        it('gives each event type its own colour, matching the legend', () => {
            const wrapper = page({ timeline: timeline(entries, legend) });

            expect(wrapper.get('li[data-testid="event-course"] span.rounded-full').classes()).toContain('bg-info');
            expect(wrapper.get('li[data-testid="event-promotion"] span.rounded-full').classes()).toContain('bg-primary');
            expect(wrapper.get('[data-testid="legend-course"] span').classes()).toContain('bg-info');
            expect(wrapper.get('[data-testid="legend-promotion"] span').classes()).toContain('bg-primary');
        });

        it('lists only the types present in the legend', () => {
            const wrapper = page({ timeline: timeline(entries, legend) });

            expect(wrapper.findAll('[data-testid="timeline-legend"] li')).toHaveLength(2);
            expect(wrapper.find('[data-testid="legend-award"]').exists()).toBe(false);
        });

        it('leaves out the detail line an event does not have', () => {
            const wrapper = page({ timeline: timeline(entries, legend) });

            expect(wrapper.get('li[data-testid="event-promotion"]').find('[data-testid="event-detail"]').exists()).toBe(false);
        });

        it('says plainly when there is nothing to show, with no legend', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="timeline-empty"]').text()).toBe('No service record events on file.');
            expect(wrapper.find('[data-testid="timeline-legend"]').exists()).toBe(false);
        });

        it('labels each event with its type for screen readers, since colour alone does not say', () => {
            const rows = page({ timeline: timeline(entries, legend) }).findAll('li[data-testid^="event-"]');

            expect(rows[0].get('[data-testid="event-type"]').classes()).toContain('sr-only');
            expect(rows[0].get('[data-testid="event-type"]').text()).toBe('Course:');
            expect(rows[1].get('[data-testid="event-type"]').text()).toBe('Rank:');
        });

        it('gives a type it does not know a neutral dot and its own name, not a missing colour', () => {
            const odd = [{ type: 'operation', date: '2026-01-01', title: 'Operation Dawn', detail: null }];
            const row = page({ timeline: timeline(odd, []) }).get('li[data-testid="event-operation"]');

            expect(row.get('span.rounded-full').classes()).toContain('bg-muted-foreground');
            expect(row.get('[data-testid="event-type"]').text()).toBe('operation:');
        });

        it('writes each date as a time element with its ISO value, in a keyboard-reachable scroll region', () => {
            const wrapper = page({ timeline: timeline(entries, legend) });

            expect(wrapper.get('[data-testid="event-date"]').element.tagName).toBe('TIME');
            expect(wrapper.get('[data-testid="event-date"]').attributes('datetime')).toBe('2026-03-04');
            expect(wrapper.get('[data-testid="timeline-list"]').attributes('tabindex')).toBe('0');
        });

        it('offers nothing to change in the timeline', () => {
            const panel = page({ timeline: timeline(entries, legend) }).get('[data-testid="timeline-panel"]');

            expect(panel.findAll('button, input, select, textarea, form, a[href]')).toHaveLength(0);
        });
    });

    describe('leadership quick actions (6.7)', () => {
        const actions = [
            { key: 'section-roster', label: 'Open section roster', href: '/roster/3' },
            { key: 'rollcall', label: 'Open in rollcall', href: '/rollcall/3' },
        ];

        it('draws each action the server sent as a link, in order', () => {
            const wrapper = page({ actions });
            const links = wrapper.findAll('[data-testid="quick-actions"] a');

            expect(links.map((a) => a.text())).toEqual(['Open section roster', 'Open in rollcall']);
            expect(links[0].attributes('href')).toBe('/roster/3');
            expect(wrapper.get('[data-testid="action-rollcall"]').attributes('href')).toBe('/rollcall/3');
        });

        it('draws no row at all when the server sent no actions', () => {
            expect(page().find('[data-testid="quick-actions"]').exists()).toBe(false);
        });

        it('is a plain row, not an amber warning bar: warning keeps its meaning for the stale notice', () => {
            const row = page({ actions }).get('[data-testid="quick-actions"]');

            expect(row.classes().join(' ')).not.toContain('warning');
        });

        it('offers nothing that changes a trooper', () => {
            const row = page({ actions }).get('[data-testid="quick-actions"]');

            expect(row.findAll('button, input, select, textarea, form')).toHaveLength(0);
            expect(row.text()).not.toMatch(/promot|medal|recommend|makeup|submit/i);
        });

        it('offers a makeup PM that opens the forum\'s own conversation form, pre-filled, in a new tab', () => {
            const wrapper = page({ actions, makeup: { username: 'Milkovic.D', missedWeeks: ['2026-10-03'] } });
            const link = wrapper.get('[data-testid="action-makeup-pm"]');

            expect(link.attributes('href')).toBe('https://7cav.us/conversations/add?to=Milkovic.D&title=Section%20practice%20makeup%3A%20week%20of%2003Oct26');
            expect(link.attributes('target')).toBe('_blank');
            expect(link.attributes('rel')).toBe('noopener noreferrer');
            expect(link.text()).toContain('Send makeup PM');
            expect(link.text()).toContain('opens the forum in a new tab');
        });

        it('draws the row for a makeup PM alone, and no makeup PM when the server sent none', () => {
            expect(page({ makeup: { username: 'Milkovic.D', missedWeeks: ['2026-10-03'] } }).find('[data-testid="action-makeup-pm"]').exists()).toBe(true);
            expect(page({ actions }).find('[data-testid="action-makeup-pm"]').exists()).toBe(false);
        });
    });
});
