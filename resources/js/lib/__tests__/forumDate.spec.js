import { describe, it, expect } from 'vitest';
import { forumDate } from '../forumDate';

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
