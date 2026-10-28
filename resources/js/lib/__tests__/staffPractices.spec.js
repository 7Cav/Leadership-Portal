import { describe, expect, it } from 'vitest';
import { staffPracticeKey, staffPracticeSource } from '../staffPractices';

// #448: how a staff member's attended practice says where it came from.
describe('staffPracticeSource', () => {
    it('names a self-report, a leader\'s entry and a game server sighting apart', () => {
        expect(staffPracticeSource({ source: 'entered', enteredBy: 'Brandt.L', selfReported: true })).toBe('Self-reported by Brandt.L');
        expect(staffPracticeSource({ source: 'entered', enteredBy: 'Hale.S for Mercer.J', selfReported: false })).toBe('Entered by Hale.S for Mercer.J');
        expect(staffPracticeSource({ source: 'capture', enteredBy: null, selfReported: false })).toBe('Seen on the game server');
    });

    it('still reads when the user who entered it is gone', () => {
        expect(staffPracticeSource({ source: 'entered', enteredBy: null, selfReported: false })).toBe('Entered by a user no longer in the portal');
    });

    it('keys an entry by its week and section', () => {
        expect(staffPracticeKey({ week: '2026-10-03', sectionId: 4 })).toBe('2026-10-03-4');
    });
});
