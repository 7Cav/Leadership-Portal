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
        expect(sent.text()).toContain('Met');
        expect(sent.find('a').attributes('href')).toBe('/staff-report/7/2026-10-03');
        expect(page.find('[data-testid="staff-reports-row-8"]').text()).toContain('Not sent yet');
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
});
