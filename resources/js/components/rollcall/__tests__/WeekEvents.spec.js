import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import WeekEvents from '../WeekEvents.vue';

enableAutoUnmount(afterEach);

const LISTED = {
    state: 'listed',
    game: 'Squad',
    items: [
        { name: 'SQUAD | Squad Saturday @1900Z', start: '2026-11-21T19:00:00Z', url: 'https://7cav.us/events/1064/' },
        { name: 'SQUAD | Warrior Wednesdays 2300 Zulu', start: '2026-11-25T23:00:00Z', url: null },
    ],
    readAt: '2026-11-21T10:50:00Z',
    lastReadFailed: false,
    frozen: false,
};
const factory = (events = {}) => mount(WeekEvents, { props: { events: { ...LISTED, ...events } } });

describe("WeekEvents, the week's calendar events on the roll call (#751)", () => {
    it('lists each event with its start in Zulu and its name, linked to the forum when it has a page', () => {
        const wrapper = factory();
        const starts = wrapper.findAll('[data-testid="week-event-start"]').map((cell) => cell.text());
        const names = wrapper.findAll('[data-testid="week-event-name"]');

        expect(starts[0]).toMatch(/^21NOV26 19\s00Z \(SAT\):$/);
        expect(starts[1]).toMatch(/^25NOV26 23\s00Z \(WED\):$/);
        expect(names.map((name) => name.text())).toEqual(['SQUAD | Squad Saturday @1900Z', 'SQUAD | Warrior Wednesdays 2300 Zulu']);
        expect(names[0].attributes('href')).toBe('https://7cav.us/events/1064/');
        expect(names[1].element.tagName).toBe('SPAN');
        expect(wrapper.find('[data-testid="week-events-empty"]').exists()).toBe(false);
    });

    it('says there are none for a week with no events of the game', () => {
        expect(factory({ items: [] }).get('[data-testid="week-events-empty"]').text()).toBe('No Squad events on the 7Cav calendar this week.');
    });

    it('says why a section with no game lists none', () => {
        const wrapper = factory({ state: 'no_game', game: null, items: [] });

        expect(wrapper.get('[data-testid="week-events-empty"]').text()).toBe('This section plays no game, so no calendar events are listed for it.');
        expect(wrapper.find('[data-testid="week-events-list"]').exists()).toBe(false);
    });

    it('says so when the calendar could not be read', () => {
        expect(factory({ state: 'unavailable', items: [], readAt: null }).get('[data-testid="week-events-empty"]').text()).toBe(
            'The 7Cav calendar could not be read, so no events are listed.',
        );
    });

    it('says when the list is from before a read that failed', () => {
        expect(factory({ lastReadFailed: true }).get('[data-testid="week-events-stale"]').text()).toMatch(/^The calendar could not be read at the last try\. Listed as read 21NOV26 10\s50Z\.$/);
        expect(factory().find('[data-testid="week-events-stale"]').exists()).toBe(false);
    });

    it('says the events are the ones the sent report kept', () => {
        const wrapper = factory({ frozen: true, lastReadFailed: true });

        expect(wrapper.get('[data-testid="week-events-frozen"]').text()).toBe('As the report kept them when it was sent.');
        expect(wrapper.find('[data-testid="week-events-stale"]').exists()).toBe(false);
    });
});
