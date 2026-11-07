import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import ReportDueBanner from '../ReportDueBanner.vue';

const page = reactive({ url: '/home', props: { reportsDue: null } });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => page,
}));

describe('report due banner', () => {
    it('lists each report still owed, linked, with the 2359Z deadline', () => {
        page.props = {
            reportsDue: {
                due: '2026-10-10T23:59:00+00:00',
                reports: [
                    { kind: 'unit', label: '1/A/1-7', href: '/reports/4/2026-10-03' },
                    { kind: 'staff', label: null, href: '/staff-reports/9/2026-10-03' },
                ],
            },
        };
        const banner = mount(ReportDueBanner);
        const items = banner.findAll('[data-testid="report-due-item"]');

        expect(items).toHaveLength(2);
        expect(items[0].text()).toBe('Your 1/A/1-7 weekly report is due at 23 59Z today.');
        expect(items[0].get('a').attributes('href')).toBe('/reports/4/2026-10-03');
        expect(items[1].text()).toBe('Your staff report is due at 23 59Z today.');
        expect(items[1].get('a').attributes('href')).toBe('/staff-reports/9/2026-10-03');
    });

    it('shows nothing when no report is due', () => {
        page.props = { reportsDue: null };

        expect(mount(ReportDueBanner).find('[data-testid="report-due-banner"]').exists()).toBe(false);
    });
});
