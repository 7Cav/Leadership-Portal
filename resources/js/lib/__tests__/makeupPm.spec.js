import { describe, expect, it } from 'vitest';
import { makeupPmTitle, makeupPmUrl } from '../makeupPm';

// The makeup PM is only a link to the forum's own "Start conversation" form (XenForo `conversations/add`),
// pre-filled with the recipient and a title. The portal never sends anything to the forum.

const query = (url) => Object.fromEntries(new URL(url).searchParams);

describe('makeupPmTitle', () => {
    it('names one missed week in the forum date convention', () => {
        expect(makeupPmTitle(['2026-10-03'])).toBe('Section practice makeup: week of 03Oct26');
    });

    it('lists several missed weeks oldest first, without repeats', () => {
        expect(makeupPmTitle(['2026-10-03', '2026-09-26', '2026-10-03'])).toBe('Section practice makeup: weeks of 26Sep26, 03Oct26');
    });

    it('falls back to a plain title when no week is known or a value is not a date', () => {
        expect(makeupPmTitle([])).toBe('Section practice makeup');
        expect(makeupPmTitle()).toBe('Section practice makeup');
        expect(makeupPmTitle(['not-a-date'])).toBe('Section practice makeup');
    });
});

describe('makeupPmUrl', () => {
    it('opens the forum\'s start-conversation form with the recipient and title filled in', () => {
        const url = makeupPmUrl('https://7cav.us', 'Vasquez.R', ['2026-09-26', '2026-10-03']);

        expect(url.startsWith('https://7cav.us/conversations/add?')).toBe(true);
        expect(query(url)).toEqual({ to: 'Vasquez.R', title: 'Section practice makeup: weeks of 26Sep26, 03Oct26' });
    });

    it('encodes every value, so a name with spaces or URL characters cannot break or extend the query', () => {
        const url = makeupPmUrl('https://7cav.us', 'Doe J&title=x#y', ['2026-10-03']);

        expect(url).toBe('https://7cav.us/conversations/add?to=Doe%20J%26title%3Dx%23y&title=Section%20practice%20makeup%3A%20week%20of%2003Oct26');
        expect(query(url).to).toBe('Doe J&title=x#y');
        expect(Object.keys(query(url))).toEqual(['to', 'title']);
    });

    it('uses the configured forum address, with or without a trailing slash', () => {
        expect(makeupPmUrl('https://forum.example.test/', 'A.B', [])).toBe('https://forum.example.test/conversations/add?to=A.B&title=Section%20practice%20makeup');
        expect(makeupPmUrl('http://localhost:8080//', 'A.B', []).startsWith('http://localhost:8080/conversations/add?')).toBe(true);
    });

    it('gives no link without a forum username', () => {
        expect(makeupPmUrl('https://7cav.us', null, ['2026-10-03'])).toBeNull();
        expect(makeupPmUrl('https://7cav.us', undefined, ['2026-10-03'])).toBeNull();
        expect(makeupPmUrl('https://7cav.us', '  ', ['2026-10-03'])).toBeNull();
    });

    it('gives no link without a usable http(s) forum address', () => {
        expect(makeupPmUrl(undefined, 'A.B', [])).toBeNull();
        expect(makeupPmUrl('', 'A.B', [])).toBeNull();
        expect(makeupPmUrl('javascript:alert(1)', 'A.B', [])).toBeNull();
        expect(makeupPmUrl('7cav.us', 'A.B', [])).toBeNull();
    });
});
