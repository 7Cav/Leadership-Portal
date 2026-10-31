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

    it('says when there is nobody to show', () => {
        expect(mount(StaffReportsPanel, { props: { rows: [] } }).find('[data-testid="staff-reports-empty"]').exists()).toBe(true);
    });
});
