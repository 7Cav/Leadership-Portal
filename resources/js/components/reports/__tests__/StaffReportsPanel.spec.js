import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffReportsPanel from '../StaffReportsPanel.vue';

// A platoon's or company's report shows, read only, whether each HQ staff member sent their own
// staff report and attended a section practice.

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const row = (overrides = {}) => ({ trooperId: 1, name: 'PVT Brandt.L', sent: false, attended: null, practices: 0, href: '/staff-report/1/2026-10-03', ...overrides });

describe('StaffReportsPanel', () => {
    it('says for each staff member whether they sent and attended', () => {
        const wrapper = mount(StaffReportsPanel, {
            props: {
                rows: [row(), row({ trooperId: 2, sent: true, attended: true, practices: 2 }), row({ trooperId: 3, sent: true, attended: false })],
            },
        });

        const state = (id) => wrapper.find(`[data-testid="staff-report-row-${id}"] [data-testid="staff-report-state"]`).text();
        expect(state(1)).toBe('Not sent');
        expect(state(2)).toBe('Attended 2 practices');
        expect(state(3)).toBe('Did not attend');
        expect(wrapper.find('[data-testid="staff-report-row-1"] a').attributes('href')).toBe('/staff-report/1/2026-10-03');
    });

    it('shows an office hour, and the game server warning only on a row that has one', () => {
        const wrapper = mount(StaffReportsPanel, {
            props: {
                rows: [
                    row({ sent: true, attended: true, practiceType: 'office_hour', officeHour: { date: '2026-10-06', note: 'NCO questions' } }),
                    row({ trooperId: 2, sent: true, attended: true, practices: 1, discrepancy: { notSeen: ['1/1/A/1-7'], seenElsewhere: [] } }),
                ],
            },
        });

        expect(wrapper.find('[data-testid="staff-report-row-1"] [data-testid="staff-report-state"]').text()).toBe('Yes, office hour (06OCT26)');
        expect(wrapper.find('[data-testid="staff-report-row-1"] [data-testid="staff-report-discrepancy"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="staff-report-row-2"] [data-testid="staff-report-discrepancy"]').exists()).toBe(true);
    });

    it('shows an operation and the presence answer, with the public server warning', () => {
        const wrapper = mount(StaffReportsPanel, {
            props: {
                rows: [
                    row({
                        sent: true, attended: true, practiceType: 'operation', ftx: { name: 'Anvil', date: '2026-10-06' },
                        presenceType: 'public_server', publicServer: { check: 'conflict', source: 'public server', minutes: 43 },
                    }),
                ],
            },
        });
        const line = wrapper.find('[data-testid="staff-report-row-1"]');

        expect(line.find('[data-testid="staff-report-state"]').text()).toBe('Yes, operation Anvil (06OCT26)');
        expect(line.find('[data-testid="staff-report-presence-answer"]').text()).toBe('Presence: Public server hour');
        expect(line.find('[data-testid="staff-report-public-server"]').text()).toBe('43 minutes of server data');
    });

    it('says once that a game that can never be read is self-reported, in place of each no data alert', () => {
        const noData = {
            sent: true, attended: true, practices: 1, presenceType: 'public_server',
            presence: { check: 'no_data', source: 'game server' },
            publicServer: { check: 'no_data', source: 'public server', minutes: null, reason: 'no_server' },
        };
        const rows = [row(noData), row({ ...noData, trooperId: 2 })];

        const quiet = mount(StaffReportsPanel, { props: { rows, selfReported: 'Battlefield 6' } });
        expect(quiet.findAll('[data-testid="self-reported-notice"]')).toHaveLength(1);
        expect(quiet.get('[data-testid="self-reported-notice"]').text()).toBe("Server data can't be read automatically for Battlefield 6. All attendance is self-reported.");
        expect(quiet.find('[data-testid="staff-report-presence"]').exists()).toBe(false);
        expect(quiet.find('[data-testid="staff-report-public-server"]').exists()).toBe(false);

        // The unit report shows the notice near its top already: the panel only stays quiet.
        const inReport = mount(StaffReportsPanel, { props: { rows, selfReported: 'Battlefield 6', notice: false } });
        expect(inReport.find('[data-testid="self-reported-notice"]').exists()).toBe(false);
        expect(inReport.find('[data-testid="staff-report-presence"]').exists()).toBe(false);

        // Not set up yet: the alerts stay, saying so, and there is no notice.
        const loud = mount(StaffReportsPanel, { props: { rows } });
        expect(loud.find('[data-testid="self-reported-notice"]').exists()).toBe(false);
        expect(loud.find('[data-testid="staff-report-row-1"] [data-testid="staff-report-presence"]').text()).toBe('No game server data: not set up for this game yet');
        expect(loud.find('[data-testid="staff-report-row-1"] [data-testid="staff-report-public-server"]').text()).toBe('No game server data: no public server is set up yet');
    });

    it('says when there is nobody to show', () => {
        expect(mount(StaffReportsPanel, { props: { rows: [] } }).find('[data-testid="staff-reports-empty"]').exists()).toBe(true);
    });
});
