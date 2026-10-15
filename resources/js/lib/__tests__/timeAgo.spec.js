import { describe, expect, it } from 'vitest';
import { localTime, timeAgo } from '../timeAgo';

const NOW = Date.parse('2026-10-16T22:51:00Z');

describe('timeAgo', () => {
    it.each([
        ['2026-10-16T22:50:31Z', 'just now'],
        ['2026-10-16T22:50:00Z', '1 min ago'],
        ['2026-10-16T22:16:00Z', '35 min ago'],
        ['2026-10-16T21:51:00Z', '1 h ago'],
        ['2026-10-16T19:20:00Z', '3 h ago'],
        ['2026-10-15T00:00:00Z', '46 h ago'],
        ['2026-10-14T22:51:00Z', '2 days ago'],
        ['2026-10-03T00:00:00Z', '13 days ago'],
    ])('reads %s as "%s"', (at, text) => {
        expect(timeAgo(at, NOW)).toBe(text);
    });

    it('rounds down, so a stamp never reads as more recent than it is', () => {
        expect(timeAgo('2026-10-16T22:16:01Z', NOW)).toBe('34 min ago');
        expect(timeAgo('2026-10-16T19:51:01Z', NOW)).toBe('2 h ago');
    });

    it('reads a future time forwards', () => {
        expect(timeAgo('2026-10-16T22:56:00Z', NOW)).toBe('in 5 min');
        expect(timeAgo('2026-10-17T23:59:00Z', NOW)).toBe('in 25 h');
        expect(timeAgo('2026-10-16T22:51:20Z', NOW)).toBe('just now');
    });

    it('takes an ISO string, epoch milliseconds or a Date', () => {
        expect(timeAgo('2026-10-16T22:16:00+00:00', NOW)).toBe('35 min ago');
        expect(timeAgo(Date.parse('2026-10-16T22:16:00Z'), NOW)).toBe('35 min ago');
        expect(timeAgo(new Date('2026-10-16T22:16:00Z'), new Date(NOW))).toBe('35 min ago');
    });

    it('is null for a missing or unreadable value', () => {
        expect(timeAgo(null, NOW)).toBeNull();
        expect(timeAgo(undefined, NOW)).toBeNull();
        expect(timeAgo('', NOW)).toBeNull();
        expect(timeAgo('not a date', NOW)).toBeNull();
    });
});

describe('localTime', () => {
    it('shows the moment on the viewer\'s clock with the zone named', () => {
        expect(localTime('2026-10-16T22:16:00Z', 'en-US', 'Europe/Berlin')).toBe('Sat, Oct 17, 12:16 AM GMT+2');
        expect(localTime('2026-10-16T22:16:00Z', 'en-US', 'America/New_York')).toBe('Fri, Oct 16, 06:16 PM EDT');
        expect(localTime('2026-10-16T22:16:00Z', 'en-GB', 'UTC')).toBe('Fri 16 Oct, 22:16 UTC');
    });

    it('is null for a missing or unreadable value', () => {
        expect(localTime(null)).toBeNull();
        expect(localTime('nope')).toBeNull();
    });
});
