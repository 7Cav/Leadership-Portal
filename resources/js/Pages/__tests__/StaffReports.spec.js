import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffReports from '../StaffReports.vue';

// The staff reports overview draws what the server sent; it decides nothing about who is listed.

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const WEEK = { date: '2026-10-03', start: '2026-10-03T00:00:00+00:00', due: '2026-10-10T23:59:00+00:00', previous: '2026-09-26', next: '2026-10-10', ended: true };

const row = (over) => ({
    trooperId: 7,
    name: 'SSG Brandt.L',
    billet: 'Platoon Sergeant',
    hq: '1/A/1-7',
    status: 'sent',
    attended: true,
    practices: ['1/1/A/1-7', '2/1/A/1-7'],
    metRule: true,
    href: '/staff-report/7/2026-10-03',
    ...over,
});

describe('StaffReports', () => {
    it('lists each HQ with its staff, status, practices and the rule, linked to the report', () => {
        const groups = [{ id: 4, label: '1/A/1-7', rows: [row(), row({ trooperId: 8, name: 'Mercer.J', status: 'notSent', attended: null, practices: [], metRule: null, href: '/staff-report/8/2026-10-03' })] }];
        const page = mount(StaffReports, { props: { week: WEEK, groups } });

        const sent = page.find('[data-testid="staff-reports-row-7"]');
        expect(sent.text()).toContain('Platoon Sergeant');
        expect(sent.text()).toContain('Sent');
        expect(sent.text()).toContain('Yes: 1/1/A/1-7, 2/1/A/1-7');
        expect(sent.find('[data-testid="row-rule"]').text()).toBe('1 SP met');
        expect(sent.find('a').attributes('href')).toBe('/staff-report/7/2026-10-03');
        expect(page.find('[data-testid="staff-reports-row-8"]').text()).toContain('Not sent');
        expect(page.find('[data-testid="staff-reports-hq-4"]').text()).toContain('1/A/1-7 HQ');
        expect(page.find('a[aria-label^="Previous week"]').attributes('href')).toBe('/staff-reports?week=2026-09-26');
    });

    it('reads the running week as not due', () => {
        const groups = [{ id: 4, label: '1/A/1-7', rows: [row({ status: 'notDue', attended: null, practices: [], metRule: null })] }];
        const page = mount(StaffReports, { props: { week: { ...WEEK, ended: false, next: null }, groups } });

        expect(page.find('[data-testid="staff-reports-row-7"]').text()).toContain('Not due yet');
        expect(page.find('[data-testid="due-line"]').text()).toBe('Sent once the week ends');
    });

    it('puts the viewer\'s own unsent report on top with a link to send it', () => {
        const page = mount(StaffReports, { props: { week: WEEK, own: row({ status: 'notSent', attended: null, practices: [], metRule: null }), groups: [] } });

        expect(page.find('[data-testid="own-staff-report-send"]').attributes('href')).toBe('/staff-report/7/2026-10-03');
        expect(page.find('[data-testid="staff-reports-none"]').exists()).toBe(true);
    });

    it('shows an FTX, other events, notes and the sheet marker on a row', () => {
        const ftx = row({ practiceType: 'ftx', ftx: { name: 'Operation Anvil', date: '2026-10-05' }, practices: [], otherEvents: 'Range day', notes: 'Ran OPFOR.', sheetPushedAt: null });
        const pushed = row({ trooperId: 8, practiceType: 'sp', sheetPushedAt: '2026-10-10T12:01:00+00:00' });
        const page = mount(StaffReports, { props: { week: WEEK, sheetConfigured: true, groups: [{ id: 4, label: '1/A/1-7', rows: [ftx, pushed] }] } });

        const first = page.find('[data-testid="staff-reports-row-7"]');
        expect(first.find('[data-testid="row-attended"]').text()).toBe('Yes, FTX Operation Anvil (05Oct26)');
        expect(first.find('[data-testid="row-other-events"]').text()).toBe('Other events: Range day');
        expect(first.find('[data-testid="row-notes"]').text()).toBe('Notes: Ran OPFOR.');
        expect(first.find('[data-testid="row-sheet"]').text()).toBe('Not sent to the sheet yet');
        expect(page.find('[data-testid="staff-reports-row-8"] [data-testid="row-sheet"]').text()).toBe('Sent to the sheet');
    });

    it('filters to who has not sent and who missed the rule, with counts, and says when nobody is left', async () => {
        const groups = [
            { id: 4, label: '1/A/1-7', rows: [row(), row({ trooperId: 8, status: 'notSent', attended: null, practices: [], metRule: null })] },
            { id: 5, label: '2/A/1-7', rows: [row({ trooperId: 9, attended: false, practices: [], metRule: false })] },
        ];
        const page = mount(StaffReports, { props: { week: WEEK, groups } });

        expect(page.find('[data-testid="filter-notSent"]').text()).toBe('Not sent1');
        expect(page.find('[data-testid="filter-missed"]').text()).toBe('Missed1');

        await page.find('[data-testid="filter-notSent"]').trigger('click');
        expect(page.find('[data-testid="filter-notSent"]').attributes('aria-checked')).toBe('true');
        expect(page.findAll('[data-testid^="staff-reports-row-"]').map((tr) => tr.attributes('data-testid'))).toEqual(['staff-reports-row-8']);
        expect(page.find('[data-testid="staff-reports-hq-5"]').exists()).toBe(false);

        await page.find('[data-testid="filter-missed"]').trigger('click');
        expect(page.findAll('[data-testid^="staff-reports-row-"]').map((tr) => tr.attributes('data-testid'))).toEqual(['staff-reports-row-9']);
        expect(page.find('[data-testid="staff-reports-row-9"] [data-testid="row-rule"]').text()).toBe('Missed');

        const allSent = mount(StaffReports, { props: { week: WEEK, groups: [{ id: 4, label: '1/A/1-7', rows: [row()] }] } });
        await allSent.find('[data-testid="filter-notSent"]').trigger('click');
        expect(allSent.find('[data-testid="staff-reports-filtered-none"]').text()).toContain('Everyone has sent theirs');
    });

    it('shows the game server warning on a row that has one, and none elsewhere', () => {
        const groups = [{ id: 4, label: '1/A/1-7', rows: [row({ discrepancy: { notSeen: [], seenElsewhere: ['3/1/A/1-7'] } }), row({ trooperId: 8, discrepancy: null })] }];
        const page = mount(StaffReports, { props: { week: WEEK, groups } });

        expect(page.find('[data-testid="staff-reports-row-7"] [data-testid="staff-report-discrepancy"]').attributes('aria-label'))
            .toContain('The game server saw them at the practice of 3/1/A/1-7, which they did not report.');
        expect(page.find('[data-testid="staff-reports-row-8"] [data-testid="staff-report-discrepancy"]').exists()).toBe(false);
    });

    it('says nothing about the sheet when none is set up', () => {
        const page = mount(StaffReports, { props: { week: WEEK, groups: [{ id: 4, label: '1/A/1-7', rows: [row()] }] } });

        expect(page.find('[data-testid="row-sheet"]').exists()).toBe(false);
    });
});

