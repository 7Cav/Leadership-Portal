import { describe, expect, it } from 'vitest';
import { attendedText, namedSentence, presenceAnswer, presenceText } from '@/lib/staffReport';

describe('attendedText', () => {
    it('reads each named type by its name and date', () => {
        const named = (practiceType) => attendedText({ attended: true, practiceType, ftx: { name: 'Anvil', date: '2026-10-06' }, practices: [] });

        expect(named('ftx')).toBe('Yes, FTX Anvil (06OCT26)');
        expect(named('operation')).toBe('Yes, operation Anvil (06OCT26)');
        expect(named('class')).toBe('Yes, class Anvil (06OCT26)');
        expect(named('event')).toBe('Yes, official event Anvil (06OCT26)');
    });

    it('still reads an office hour sent as accountability before', () => {
        expect(attendedText({ attended: true, practiceType: 'office_hour', officeHour: { date: '2026-10-06' }, practices: [] })).toBe('Yes, office hour (06OCT26)');
    });
});

describe('namedSentence', () => {
    it('says what a named type was, and nothing for an SP or No', () => {
        expect(namedSentence({ attended: true, practiceType: 'class', ftx: { name: 'NCO class', date: '2026-10-06' } })).toBe('Attended a class, NCO class on 06OCT26.');
        expect(namedSentence({ attended: true, practiceType: 'sp' })).toBeNull();
        expect(namedSentence({ attended: false, practiceType: 'operation' })).toBeNull();
    });
});

describe('presenceAnswer', () => {
    it('reads each presence answer, and nothing on a report sent before it was asked', () => {
        expect(presenceAnswer({ presenceType: 'public_server' })).toBe('Public server hour');
        expect(presenceAnswer({ presenceType: 'office_hour', officeHour: { date: '2026-10-07' } })).toBe('Office hour (07OCT26)');
        expect(presenceAnswer({ presenceType: 'none' })).toBe('No public server hour or office hour');
        expect(presenceAnswer({ presenceType: null })).toBe('');
    });

    it('names the public server in its check', () => {
        expect(presenceText({ check: 'no_data', source: 'public server' })).toBe('No public server data');
    });
});
