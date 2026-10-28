import { describe, expect, it } from 'vitest';
import { COUNSELING_TYPES, DRIVE_STATUS, MAX_DOCUMENT_BYTES, documentProblem, fileSize, nextStepHint, todayUtc } from '../counseling';

// #381: the words and small rules of the counseling panel.

describe('counseling', () => {
    it('names both records the way the regulations do', () => {
        expect(COUNSELING_TYPES.dcs).toEqual({ short: 'DCS', label: 'Developmental Counseling Statement' });
        expect(COUNSELING_TYPES.lor).toEqual({ short: 'LOR', label: 'Letter of Reprimand' });
        expect(Object.keys(DRIVE_STATUS)).toEqual(['pending', 'uploaded', 'failed', 'skipped']);
    });

    it('sizes a document in KB below a megabyte and in MB above', () => {
        expect(fileSize(200)).toBe('1 KB');
        expect(fileSize(34 * 1024)).toBe('34 KB');
        expect(fileSize(1.25 * 1024 * 1024)).toBe('1.3 MB');
        expect(fileSize(undefined)).toBe('');
    });

    it('says what one more record would mean under the 90-day rules', () => {
        expect(nextStepHint('dcs', { dcs: 0, lor: 0 })).toBeNull();
        expect(nextStepHint('dcs', { dcs: 1, lor: 0 })).toBe(
            'This trooper already has a DCS in the last 90 days. A repeat within 90 days of a DCS calls for a Letter of Reprimand.',
        );
        expect(nextStepHint('dcs', { dcs: 2, lor: 0 })).toContain('already has 2 DCSs');
        expect(nextStepHint('lor', { dcs: 3, lor: 0 })).toBeNull();
        expect(nextStepHint('lor', { dcs: 0, lor: 1 })).toContain('A second within 90 days allows removal as Section Leader or Assistant Section Leader.');
        expect(nextStepHint('lor', { dcs: 0, lor: 2 })).toContain('A third within 90 days calls for an Article 15.');
        expect(nextStepHint('lor', undefined)).toBeNull();
    });

    it('catches a missing, non-PDF or oversize file before it uploads', () => {
        expect(documentProblem(null)).toBe('Attach the signed document as a PDF.');
        expect(documentProblem({ name: 'scan.png', type: 'image/png', size: 10 })).toBe('Attach the signed document as a PDF.');
        expect(documentProblem({ name: 'dcs.pdf', type: '', size: MAX_DOCUMENT_BYTES + 1 })).toBe('The document must be 10 MB or smaller.');
        expect(documentProblem({ name: 'DCS.PDF', type: '', size: 10 })).toBeNull();
        expect(documentProblem({ name: 'dcs', type: 'application/pdf', size: MAX_DOCUMENT_BYTES })).toBeNull();
    });

    it('takes today on the UTC clock', () => {
        expect(todayUtc(new Date('2026-10-28T23:30:00-05:00'))).toBe('2026-10-29');
    });
});
