import { describe, expect, it } from 'vitest';
import { discordPlain, NOTES_MAX, reportSummary, trimNotes } from '../reportSummary';

const SECTION = { id: 5, name: '2nd Section', callsign: '2/1/A/1-7', isLeaf: true };
const WEEK = { start: '2026-10-03T00:00:00+00:00' };
const KPIS = { strength: 9, headcount: 12, rc: { numerator: 22, denominator: 25 }, sp: { numerator: 3, denominator: 4 }, atRisk: 2, onLoa: 1, makeups: 0 };
const DELTA = { strength: 1, rcBp: 500, spBp: -300, atRisk: 1, onLoa: 0, makeups: 0 };

const sent = (overrides = {}) =>
    reportSummary({
        unit: SECTION,
        week: WEEK,
        sent: true,
        sentAt: '2026-10-08T16:24:00+00:00',
        sentBy: 'SGT Vasquez.R',
        kpis: KPIS,
        delta: DELTA,
        trainingSubject: 'Room clearing',
        notes: 'Good week. Comms tighter.',
        ...overrides,
    });

describe('reportSummary', () => {
    it('writes a sent report as a few plain lines', () => {
        expect(sent()).toBe(
            [
                '2nd Section (2/1/A/1-7) weekly report, week of 03Oct26',
                'Sent Thu 08Oct26 16:24Z by SGT Vasquez.R',
                'Headcount 12, staff and groups included',
                'In rollcall 9 (+1)',
                'RC 88% (+5 pts), SP 75% (-3 pts), last 30 days',
                'At-risk 2 (+1), on LOA 1, makeups 0',
                'Trained: Room clearing',
                '',
                'Notes:',
                'Good week. Comms tighter.',
            ].join('\n'),
        );
    });

    it('says a draft is not sent yet', () => {
        expect(sent({ sent: false, sentAt: null, sentBy: null }).split('\n')[1]).toBe('Draft, not sent yet');
    });

    it('leaves the headcount line out of a report frozen before it existed', () => {
        const { headcount, ...legacy } = KPIS;

        expect(sent({ kpis: legacy })).not.toContain('Headcount');
        expect(sent({ kpis: legacy })).toContain('In rollcall 9 (+1)');
    });

    it('leaves the changes out when there is nothing to compare with', () => {
        const text = sent({ delta: null });

        expect(text).toContain('In rollcall 9\n');
        expect(text).toContain('RC 88%, SP 75%, last 30 days');
        expect(text).toContain('At-risk 2, on LOA 1');
    });

    it('shows a rate with nothing eligible as n/a, never 0%', () => {
        expect(sent({ kpis: { ...KPIS, sp: { numerator: 0, denominator: 0 } }, delta: { ...DELTA, spBp: null } })).toContain('SP n/a,');
    });

    it('says when the numbers are not available', () => {
        expect(sent({ kpis: null, delta: null })).toContain('\nNumbers not available\n');
    });

    it('marks a missing training subject on a section, and leaves the line out of a roll-up', () => {
        expect(sent({ trainingSubject: '  ' })).toContain('Trained: not filled in');
        expect(sent({ unit: { name: '1st Platoon', callsign: '1/A/1-7', isLeaf: false }, trainingSubject: null })).not.toContain('Trained');
    });

    it('leaves out empty notes', () => {
        expect(sent({ notes: '  \n ' }).endsWith('Trained: Room clearing')).toBe(true);
    });

    it('names a unit once when it has only one name', () => {
        expect(sent({ unit: { ...SECTION, name: '2/1/A/1-7' } }).startsWith('2/1/A/1-7 weekly report')).toBe(true);
    });

    it('uses no markdown or BBCode of its own', () => {
        expect(sent({ sentBy: 'SGT Vasquez.R', trainingSubject: 'Room clearing', notes: 'Fine.' })).not.toMatch(/[*_~`|#>[\]]/);
    });

    it('escapes what a leader wrote so Discord shows it as written', () => {
        const text = sent({ sentBy: 'SGT Doe_J', trainingSubject: 'Breach *and* clear', notes: '# Header\n> quoted\nSpoiler ||x|| and `code` @everyone' });

        expect(text).toContain('by SGT Doe\\_J');
        expect(text).toContain('Trained: Breach \\*and\\* clear');
        expect(text).toContain('\\# Header\n\\> quoted\nSpoiler \\|\\|x\\|\\| and \\`code\\` @​everyone');
    });
});

describe('trimNotes', () => {
    it('drops trailing spaces and runs of blank lines', () => {
        expect(trimNotes('  First.   \r\n\r\n\r\n\r\nSecond.  \n')).toBe('First.\n\nSecond.');
    });

    it('cuts long notes at a word and says where the rest is', () => {
        const long = `${'word '.repeat(150)}end`;
        const cut = trimNotes(long);

        expect(cut.length).toBeLessThanOrEqual(NOTES_MAX + 30);
        expect(cut).toMatch(/word… \(full notes in the portal\)$/);
    });

    it('keeps notes at the limit whole', () => {
        const exact = 'x'.repeat(NOTES_MAX);

        expect(trimNotes(exact)).toBe(exact);
    });
});

describe('discordPlain', () => {
    it('leaves ordinary text alone', () => {
        expect(discordPlain('SGT Vasquez.R, 2/1/A/1-7: 88% (+5)')).toBe('SGT Vasquez.R, 2/1/A/1-7: 88% (+5)');
    });

    it('escapes a backslash so it is not read as an escape', () => {
        expect(discordPlain('a\\b')).toBe('a\\\\b');
    });

    it('stops @here from pinging', () => {
        expect(discordPlain('@here look')).toBe('@​here look');
    });
});
