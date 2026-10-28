import { describe, expect, it } from 'vitest';
import { COUNSELING_KINDS, COUNSELING_TYPES, DRIVE_STATUS, MAX_DOCUMENT_BYTES, documentProblem, fileSize, forecastFlags, todayUtc } from '../counseling';

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

    it('names what a record is for, in the order the form offers them', () => {
        expect(Object.keys(COUNSELING_KINDS)).toEqual(['missed_practice', 'conduct']);
        expect(COUNSELING_KINDS.missed_practice.label).toBe('Missed section practice or rollcall');
        expect(COUNSELING_KINDS.conduct.label).toBe('Other conduct');
    });

    it('picks the forecast range the filing date falls in, edges included', () => {
        const repeat = { key: 'dcs-repeat', message: 'Repeat within 90 days of a DCS: an LOR is the next step if it was for the same issue.' };
        const forecast = {
            dcs: {
                conduct: [
                    { from: '1970-01-01', flags: [] },
                    { from: '2026-08-01', flags: [repeat] },
                    { from: '2026-10-31', flags: [] },
                ],
            },
        };

        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-07-31')).toEqual([]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-08-01')).toEqual([repeat]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-10-30')).toEqual([repeat]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-10-31')).toEqual([]);
        // Nothing chosen yet, or nothing the viewer may file.
        expect(forecastFlags(forecast, 'dcs', '', '2026-08-01')).toEqual([]);
        expect(forecastFlags(forecast, 'lor', 'conduct', '2026-08-01')).toEqual([]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '')).toEqual([]);
        expect(forecastFlags(undefined, 'dcs', 'conduct', '2026-08-01')).toEqual([]);
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
