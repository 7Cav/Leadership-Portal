import { describe, expect, it } from 'vitest';
import { rollcallBbcode, rollcallPost } from '../rollcallBbcode';

const t = (id, name, rollcall, status, rankShort = 'PFC') => ({ id, cavId: 1000 + id, rank: 'Long Rank Title', rankShort, name, rollcall, status, source: null, priorStatus: null });
const WEEK = '2026-10-03T00:00:00+00:00';

describe('rollcall BBCode (3.10)', () => {
    it('writes the default template: header, a forum table with rank, name, signature and SP, and totals', () => {
        const bbcode = rollcallBbcode({
            designation: '2/1/A/1-7',
            weekStart: WEEK,
            troopers: [
                t(1, 'Vasquez.R', 'will_attend', 'present', 'SGT'),
                t(2, 'Okafor.T', 'cannot_attend', 'missed'),
                t(3, 'Kowalczyk.P', 'cannot_attend', 'makeup'),
                t(4, 'Reyes.M', 'will_attend', 'attached', 'PVT'),
                t(5, 'Doyle.A', 'unsigned', null, 'PVT'),
                t(6, 'Tanaka.H', 'unsigned', 'loa'),
            ],
        });

        expect(bbcode).toBe(
            [
                '[b]2/1/A/1-7 Rollcall, week of 03Oct26[/b]',
                '',
                '[table]',
                '[tr][td][b]Rank[/b][/td][td][b]Name[/b][/td][td][b]Rollcall[/b][/td][td][b]SP[/b][/td][/tr]',
                '[tr][td]SGT[/td][td]Vasquez.R[/td][td]Will attend[/td][td]Present[/td][/tr]',
                '[tr][td]PFC[/td][td]Okafor.T[/td][td]Cannot attend[/td][td][color=#c0392b]Missed[/color][/td][/tr]',
                '[tr][td]PFC[/td][td]Kowalczyk.P[/td][td]Cannot attend[/td][td]Makeup[/td][/tr]',
                '[tr][td]PVT[/td][td]Reyes.M[/td][td]Will attend[/td][td]Attached[/td][/tr]',
                '[tr][td]PVT[/td][td]Doyle.A[/td][td]Unsigned[/td][td]Not marked[/td][/tr]',
                '[tr][td]PFC[/td][td]Tanaka.H[/td][td]LOA[/td][td]LOA[/td][/tr]',
                '[/table]',
                '',
                'Signed 4 of 5. Present 1, missed 1, makeup 1, attached 1, LOA 1, not marked 1.',
            ].join('\n'),
        );
    });

    it('renders the header with no table for an empty roster, not a crash', () => {
        expect(rollcallBbcode({ designation: '3/1/A/1-7', weekStart: WEEK, troopers: [] })).toBe(
            '[b]3/1/A/1-7 Rollcall, week of 03Oct26[/b]\n\nNo troopers are placed in this section.',
        );
    });

    it('names a trooper whose username has not synced by milpac id and keeps forum markup out of the cells', () => {
        const post = rollcallPost({
            designation: '2/1/A/1-7',
            weekStart: WEEK,
            troopers: [{ ...t(1, null, 'will_attend', 'present', null), rank: null }, t(2, 'Evil[/td][url]x[/url]', 'will_attend', 'present')],
        });

        expect(post.rows[0]).toMatchObject({ rank: '', name: '#1001' });
        expect(post.rows[1].name).toBe('Evil(/td)(url)x(/url)');
    });

    it('omits "not marked" from the totals once everyone is marked', () => {
        expect(rollcallPost({ designation: 'S', weekStart: WEEK, troopers: [t(1, 'A.B', 'will_attend', 'present')] }).summary).toBe(
            'Signed 1 of 1. Present 1, missed 0, makeup 0, attached 0, LOA 0.',
        );
    });
});
