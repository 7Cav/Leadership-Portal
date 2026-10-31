import { describe, it, expect } from 'vitest';
import { forumDate, forumDateTime, weekLabel } from '../forumDate';

describe('forumDate', () => {
    it('formats an ISO timestamp as day + 3-letter month + 2-digit year', () => {
        expect(forumDate('2026-05-30T14:22:00Z')).toBe('30May26');
    });

    it('pads a single-digit day', () => {
        expect(forumDate('2026-05-03T00:00:00Z')).toBe('03May26');
    });

    it('reads the date in UTC, matching how the server stores timestamps (AD-11)', () => {
        // 23:30 UTC on the 30th is still the 31st in UTC+1 — a local-time read would drift the day.
        expect(forumDate('2026-05-30T23:30:00Z')).toBe('30May26');
    });
});

describe('forumDateTime', () => {
    it('appends a zero-padded UTC HH:mm to the forum date', () => {
        expect(forumDateTime('2026-05-30T04:05:00Z')).toBe('30May26 04:05');
    });
});

describe('weekLabel (#568)', () => {
    it('names the week opening on a week_start by its Friday, as the Google Form does', () => {
        expect(weekLabel('2026-10-24')).toBe('30Oct26');
        expect(weekLabel('2026-10-24T00:00:00Z')).toBe('30Oct26');
        expect(weekLabel('2026-10-24T00:00:00.000000Z')).toBe('30Oct26');
    });

    it('names the Cav week a timestamp falls in, not the timestamp plus six days', () => {
        expect(weekLabel('2026-10-27T15:00:00Z')).toBe('30Oct26');
        expect(weekLabel('2026-10-30T23:59:59Z')).toBe('30Oct26');
        expect(weekLabel('2026-10-31T00:00:00Z')).toBe('06Nov26');
    });

    it('buckets by the UTC instant, so an offset time lands in the week of its UTC moment (AD-11)', () => {
        // Friday 23:30 in UTC-2 is already Saturday 01:30 UTC: the next Cav week.
        expect(weekLabel('2026-10-30T23:30:00-02:00')).toBe('06Nov26');
    });

    it('crosses a month and a year end', () => {
        expect(weekLabel('2026-12-26')).toBe('01Jan27');
    });
});
