import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import TimeAgo from '../TimeAgo.vue';
import { localTime } from '@/lib/timeAgo';

const AT = '2026-10-16T22:16:00Z';

const stamp = (props = { at: AT }, slot = '16Oct26 22:16Z') => mount(TimeAgo, { props, slots: slot ? { default: slot } : {}, attachTo: document.body });

enableAutoUnmount(afterEach);

describe('TimeAgo', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-16T22:51:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('keeps the Zulu text first and adds how long ago it was', () => {
        const el = stamp().find('[data-time-ago]');

        expect(el.element.tagName).toBe('TIME');
        expect(el.text()).toBe('16Oct26 22:16Z (35 min ago)');
        expect(el.attributes('datetime')).toBe('2026-10-16T22:16:00.000Z');
    });

    it('shows only the relative part without a slot', () => {
        expect(stamp({ at: AT }, null).find('[data-time-ago]').text()).toBe('(35 min ago)');
    });

    it('moves on every minute', async () => {
        const wrapper = stamp();

        vi.advanceTimersByTime(60_000);
        await nextTick();
        expect(wrapper.text()).toBe('16Oct26 22:16Z (36 min ago)');

        vi.advanceTimersByTime(25 * 60_000);
        await nextTick();
        expect(wrapper.text()).toBe('16Oct26 22:16Z (1 h ago)');
    });

    it('shares one clock across every stamp and stops it when the last one goes', () => {
        const wrappers = [stamp(), stamp(), stamp({ at: '2026-10-16T20:00:00Z' })];

        expect(vi.getTimerCount()).toBe(1);

        wrappers[0].unmount();
        wrappers[1].unmount();
        expect(vi.getTimerCount()).toBe(1);

        wrappers[2].unmount();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('restarts the clock, current, for a stamp mounted after the others went', async () => {
        stamp().unmount();
        vi.setSystemTime(new Date('2026-10-16T23:51:00Z'));

        const wrapper = stamp();
        expect(wrapper.text()).toBe('16Oct26 22:16Z (1 h ago)');
        expect(vi.getTimerCount()).toBe(1);
    });

    it('names the viewer\'s local time in a tooltip on focus', async () => {
        const wrapper = stamp();

        await wrapper.find('[data-time-ago]').trigger('focus');
        await nextTick();

        const tip = document.body.querySelector('[data-local-time]');
        expect(tip).not.toBeNull();
        expect(tip.textContent).toContain(`Your time: ${localTime(AT)}`);
    });

    it('leaves the tooltip to its host when told to', () => {
        const wrapper = stamp({ at: AT, tooltip: false });

        expect(wrapper.find('[data-time-ago]').text()).toBe('16Oct26 22:16Z (35 min ago)');
        expect(wrapper.find('[data-time-ago]').attributes('tabindex')).toBeUndefined();
    });

    it('prints just the slot for a missing or unreadable time', () => {
        expect(stamp({ at: null }, 'never').text()).toBe('never');
        expect(stamp({ at: 'nope' }, 'never').find('[data-time-ago]').exists()).toBe(false);
    });
});
