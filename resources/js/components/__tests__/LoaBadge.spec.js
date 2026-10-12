import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LoaBadge from '../LoaBadge.vue';

// Story 4.5: the shared inline LOA badge. It draws the resolver's state (4.4) and the sync provenance (4.3);
// it never decides coverage itself.

const covered = (overrides = {}) => ({
    state: 'covered',
    type: 'loa',
    typeLabel: 'LOA',
    startsAt: '2026-10-05',
    endsAt: '2026-10-30',
    expectedReturnAt: null,
    ongoing: false,
    overdue: false,
    endsInDays: null,
    ended: false,
    sourceThread: 'https://7cav.us/threads/loa-request.1/',
    reviewReason: null,
    ...overrides,
});

const needsReview = (overrides = {}) => ({
    state: 'needs_review',
    type: 'eloa',
    typeLabel: 'ELOA',
    startsAt: null,
    endsAt: null,
    expectedReturnAt: null,
    ongoing: false,
    sourceThread: 'https://7cav.us/threads/eloa-report.2/',
    reviewReason: 'The end date could not be read.',
    ...overrides,
});

const synced = (overrides = {}) => ({
    source: 'Forum thread',
    lastSyncedAt: '2026-10-08T14:05:00+00:00',
    stale: false,
    neverSynced: false,
    latestStatus: 'success',
    latestFinishedAt: '2026-10-08T14:05:00+00:00',
    ...overrides,
});

const badge = (props) => mount(LoaBadge, { props });

describe('LoaBadge', () => {
    describe('a leave that was read', () => {
        it('shows the type and the end date in forum convention', () => {
            const wrapper = badge({ cover: covered(), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('LOA ends 30Oct26');
        });

        it('counts down when a leave is about to end, as the section overview mockup draws it', () => {
            const label = (endsInDays) => badge({ cover: covered({ endsInDays }), sync: synced() }).get('[data-testid="loa-badge"]').text();

            expect(label(2)).toBe('LOA ends in 2d');
            expect(label(1)).toBe('LOA ends in 1d');
            expect(label(0)).toBe('LOA ends today');
            expect(label(7)).toBe('LOA ends in 7d');
        });

        it('names the date once a leave is more than a week from ending', () => {
            const wrapper = badge({ cover: covered({ endsInDays: 8 }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('LOA ends 30Oct26');
        });

        it('says ended, not ends, for a leave the server marks as already over', () => {
            const wrapper = badge({ cover: covered({ endsAt: '2026-10-03', endsInDays: null, ended: true }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('LOA ended 03Oct26');
        });

        it('says ended for an ELOA a return has closed', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: '2026-10-06', expectedReturnAt: '2026-10-20', ended: true }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA ended 06Oct26');
        });

        it('shows the type and ongoing when an open-ended leave has no expected return either', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: null, ongoing: true }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA ongoing');
        });

        it('is the stock warning badge, with no icon', () => {
            const wrapper = badge({ cover: covered(), sync: synced() });
            const trigger = wrapper.get('[data-testid="loa-badge"]');

            expect(trigger.attributes('data-state-kind')).toBe('covered');
            expect(trigger.classes()).toContain('text-warning');
            expect(trigger.find('svg').exists()).toBe(false);
        });

        it('shows the expected return of an ELOA as its end date, and says it can be extended', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: null, expectedReturnAt: '2026-11-13', ongoing: true }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA ends 13Nov26');
            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('ELOA from 05Oct26, expected return 13Nov26');
            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('can be extended');
        });

        it('says overdue, with the date, once an open ELOA is past its expected return', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: null, expectedReturnAt: '2026-10-07', ongoing: true, overdue: true }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA overdue 07Oct26');
            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('no return has been posted');
        });

        it('draws overdue as the danger badge with an icon, so it does not read like an ordinary leave', () => {
            const overdue = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: null, expectedReturnAt: '2026-10-07', ongoing: true, overdue: true }), sync: synced() }).get('[data-testid="loa-badge"]');
            const ordinary = badge({ cover: covered(), sync: synced() }).get('[data-testid="loa-badge"]');

            expect(overdue.classes()).toContain('text-destructive');
            expect(overdue.classes()).not.toContain('text-warning');
            expect(overdue.find('svg').exists()).toBe(true);
            expect(ordinary.classes()).not.toContain('text-destructive');
        });

        it('follows the server on whether it is overdue, never the local clock', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: null, expectedReturnAt: '2000-01-01', ongoing: true, overdue: false }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA ends 01Jan00');
        });

        it('is not overdue once a return has ended it', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: '2026-10-09', expectedReturnAt: '2026-10-01' }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA ends 09Oct26');
        });

        it('shows the day a return ended an ELOA, not the expected one', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: '2026-11-02', expectedReturnAt: '2026-11-13' }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('ELOA ends 02Nov26');
        });

        it('gives the dates in the details', () => {
            const wrapper = badge({ cover: covered(), sync: synced() });

            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('LOA from 05Oct26 to 30Oct26');
        });

        it('says there is no end date yet for an open-ended leave', () => {
            const wrapper = badge({ cover: covered({ type: 'eloa', typeLabel: 'ELOA', endsAt: null, ongoing: true }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('ELOA from 05Oct26, no end date yet');
        });
    });

    describe('a leave that needs review', () => {
        it('says so, and shows no date', () => {
            const wrapper = badge({ cover: needsReview(), sync: synced() });
            const text = wrapper.get('[data-testid="loa-badge"]').text();

            expect(text).toBe('ELOA needs review');
            expect(text).not.toMatch(/\d{2}[A-Z][a-z]{2}\d{2}/);
            expect(text).not.toContain('ends');
            expect(wrapper.get('[data-testid="loa-badge"]').attributes('data-state-kind')).toBe('needs_review');
        });

        it('is not styled like a confirmed leave: outlined, dashed, with a warning icon', () => {
            const trigger = badge({ cover: needsReview(), sync: synced() }).get('[data-testid="loa-badge"]');

            expect(trigger.classes()).toContain('border-dashed');
            expect(trigger.classes()).not.toContain('text-warning');
            expect(trigger.classes()).not.toContain('text-destructive');
            expect(trigger.find('svg').exists()).toBe(true);
        });

        it('explains in the details that no dates are shown, with the reason', () => {
            const wrapper = badge({ cover: needsReview(), sync: synced() });
            const details = wrapper.get('[data-testid="loa-details"]').text();

            expect(details).toContain('could not be read, so no dates are shown');
            expect(details).toContain('The end date could not be read.');
        });

        it('falls back to LOA when the type is unknown', () => {
            const wrapper = badge({ cover: needsReview({ type: null, typeLabel: null }), sync: synced() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('LOA needs review');
        });
    });

    describe('no leave that week', () => {
        it('renders nothing for a none state', () => {
            const wrapper = badge({ cover: { ...covered(), state: 'none' }, sync: synced() });

            expect(wrapper.find('[data-testid="loa-badge"]').exists()).toBe(false);
            expect(wrapper.html()).toBe('<!--v-if-->');
        });

        it('renders nothing without a cover', () => {
            expect(badge({ cover: null, sync: synced() }).find('[data-testid="loa-badge"]').exists()).toBe(false);
            expect(mount(LoaBadge).find('[data-testid="loa-badge"]').exists()).toBe(false);
        });
    });

    describe('source and last sync (AD-20)', () => {
        it('shows the source and the last successful sync time', () => {
            const wrapper = badge({ cover: covered(), sync: synced() });

            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('Source: Forum thread');
            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('Last synced 08Oct26 14:05 UTC');
            expect(wrapper.get('[data-testid="loa-details"]').text()).not.toContain('did not succeed');
        });

        it('says when the latest sync failed and that this is the last good data', () => {
            const wrapper = badge({ cover: covered(), sync: synced({ stale: true, latestStatus: 'failure', lastSyncedAt: '2026-10-01T09:30:00+00:00' }) });
            const details = wrapper.get('[data-testid="loa-details"]').text();

            expect(details).toContain('Last synced 01Oct26 09:30 UTC');
            expect(details).toContain('The latest sync did not succeed, so this is the last good data.');
        });

        it('says never synced instead of showing a time', () => {
            const wrapper = badge({ cover: covered(), sync: synced({ lastSyncedAt: null, neverSynced: true, latestStatus: null, latestFinishedAt: null }) });

            expect(wrapper.get('[data-testid="loa-details"]').text()).toContain('Never synced');
        });

        it('shows the leave without a source line when it is not given the sync state', () => {
            const wrapper = badge({ cover: covered() });

            expect(wrapper.get('[data-testid="loa-badge"]').text()).toBe('LOA ends 30Oct26');
            expect(wrapper.get('[data-testid="loa-details"]').text()).not.toContain('Source');
        });
    });

    it('uses no dots or dashes as separators in the pill', () => {
        for (const cover of [covered(), covered({ endsAt: null, ongoing: true }), needsReview()]) {
            expect(badge({ cover, sync: synced() }).get('[data-testid="loa-badge"]').text()).not.toMatch(/[·—–]/);
        }
    });

    it('opens the details on tap or click, which a hover tooltip cannot do on a phone', async () => {
        const wrapper = mount(LoaBadge, { props: { cover: needsReview(), sync: synced({ stale: true }) }, attachTo: document.body });

        expect(document.body.querySelector('[data-testid="loa-popover"]')).toBeNull();

        await wrapper.get('[data-testid="loa-badge"]').trigger('click');

        const popover = document.body.querySelector('[data-testid="loa-popover"]');
        expect(popover).not.toBeNull();
        expect(popover.textContent).toContain('The end date could not be read.');
        expect(popover.textContent).toContain('Source: Forum thread');
        expect(popover.textContent).toContain('last good data');

        wrapper.unmount();
    });

    it('is a real button, reachable by keyboard, and described for screen readers', () => {
        const wrapper = badge({ cover: covered(), sync: synced() });
        const trigger = wrapper.get('[data-testid="loa-badge"]');
        const details = wrapper.get('[data-testid="loa-details"]');

        expect(trigger.element.tagName).toBe('BUTTON');
        expect(trigger.attributes('aria-describedby')).toBe(details.attributes('id'));
        expect(details.classes()).toContain('sr-only');
    });
});
