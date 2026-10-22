import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import ReportDeadlineBanner from '../ReportDeadlineBanner.vue';

enableAutoUnmount(afterEach);
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const DUE = '2026-10-10T23:59:00+00:00';
const HREF = '/reports/12/2026-10-03';
const UNTIL = '2026-10-31T00:00:00+00:00';
const factory = (props = {}) => mount(ReportDeadlineBanner, { props: { due: DUE, overdueUntil: UNTIL, href: HREF, ...props } });
const banner = (wrapper) => wrapper.find('[data-testid="report-deadline"]');

describe('ReportDeadlineBanner', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('appears 24 hours out, counts down, and turns overdue at the deadline', async () => {
        vi.setSystemTime(new Date('2026-10-09T20:00:00Z'));
        const wrapper = factory();
        expect(banner(wrapper).exists()).toBe(false);

        await vi.advanceTimersByTimeAsync(4 * 3_600_000);
        expect(banner(wrapper).text()).toContain('Report due in 23 h');
        expect(banner(wrapper).text()).toContain('(due 10Oct26 23:59Z)');
        expect(banner(wrapper).attributes('data-state')).toBe('due');

        await vi.advanceTimersByTimeAsync(23 * 3_600_000 + 30 * 60_000);
        expect(banner(wrapper).text()).toContain('Report due in 29 min');

        await vi.advanceTimersByTimeAsync(3 * 3_600_000);
        expect(banner(wrapper).text()).toContain('Report overdue by 2 h');
        expect(banner(wrapper).attributes('data-state')).toBe('overdue');
    });

    it('drops the overdue line when the week leaves the 4-week window', async () => {
        vi.setSystemTime(new Date('2026-10-30T23:59:00Z'));
        const wrapper = factory();
        expect(banner(wrapper).text()).toContain('Report overdue by 20 d');

        await vi.advanceTimersByTimeAsync(60_000);
        expect(banner(wrapper).exists()).toBe(false);
    });

    it('links to the report', () => {
        vi.setSystemTime(new Date('2026-10-10T18:00:00Z'));

        expect(factory().find('[data-testid="report-deadline-link"]').attributes('href')).toBe(HREF);
    });

    it('shows without the link when read-only or already on the report', () => {
        vi.setSystemTime(new Date('2026-10-10T18:00:00Z'));

        expect(banner(factory({ readOnly: true })).text()).toContain('Report due in 5 h');
        expect(factory({ readOnly: true }).find('[data-testid="report-deadline-link"]').exists()).toBe(false);
        expect(factory({ href: null }).find('[data-testid="report-deadline-link"]').exists()).toBe(false);
    });

    it('hides for a sent report, before and after the deadline', () => {
        vi.setSystemTime(new Date('2026-10-10T18:00:00Z'));
        expect(banner(factory({ sent: true })).exists()).toBe(false);

        vi.setSystemTime(new Date('2026-10-12T18:00:00Z'));
        expect(banner(factory({ sent: true })).exists()).toBe(false);
    });
});
