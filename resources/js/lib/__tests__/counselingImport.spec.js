import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { CONSEQUENCE_CHECKS, SECTION_1, SPECIAL_PUNISHMENTS, TEXT_FIELDS } from '../counselingFields';
import { fillCounselingForm } from '../counselingPdf';
import { importableCount, importDate, readAllImportFields, readImportFields, sourceLabel } from '../counselingImport';

// #565: the counseling import reads each chosen PDF's form in the browser. These fill the real checked-in templates with
// the portal's own filler and read them back, so the field map is proven against the forms themselves.
const template = (name) => new Uint8Array(readFileSync(resolve(process.cwd(), `resources/counseling/${name}.pdf`)));

describe('readImportFields', () => {
    it('reads a filled DCS (R-042): its type, Section 1 and every text field', async () => {
        const bytes = await fillCounselingForm(template('7CAV-R-042'), {
            text: {
                [SECTION_1.date]: '20OCT2025',
                [SECTION_1.trooperName]: 'Brandt.L',
                [SECTION_1.authorityName.dcs]: 'Okafor.B',
                [TEXT_FIELDS.discussion]: 'Talked it through.',
                [TEXT_FIELDS.plan_authority]: 'Weekly check-in.',
                [TEXT_FIELDS.plan_trooper]: 'I will post an LOA.',
            },
        });

        const read = await readImportFields(bytes);

        expect(read).toMatchObject({ type: 'dcs', date: '20OCT2025', trooper: 'Brandt.L', issuer: 'Okafor.B' });
        expect(read.form).toMatchObject({ discussion: 'Talked it through.', plan_authority: 'Weekly check-in.', plan_trooper: 'I will post an LOA.', infraction: '' });
        expect(read.form).not.toHaveProperty('consequences');
    });

    it('reads a filled LOR (R-043): its authority, checkboxes, special punishments and radio choices', async () => {
        const bytes = await fillCounselingForm(template('7CAV-R-043'), {
            text: {
                [SECTION_1.trooperName]: 'Brandt.L',
                [SECTION_1.authorityName.lor]: 'Mercer.J',
                [SPECIAL_PUNISHMENTS]: 'No leave for 30 days.',
            },
            checks: [CONSEQUENCE_CHECKS.removal, CONSEQUENCE_CHECKS.special_punishment],
            radios: [
                { group: 'Group2', pick: 'bottom' },
                { group: 'Group4', pick: 'right' },
            ],
        });

        const read = await readImportFields(bytes);

        expect(read).toMatchObject({ type: 'lor', trooper: 'Brandt.L', issuer: 'Mercer.J' });
        expect(read.form).toMatchObject({
            consequences: ['removal', 'special_punishment'],
            removal: 'assistant_section_leader',
            appeal: 'do_not',
            special_punishments: 'No leave for 30 days.',
        });
    });

    it('reads an unfilled LOR as having no choices', async () => {
        const read = await readImportFields(template('7CAV-R-043'));

        expect(read.form).toMatchObject({ consequences: [], removal: null, appeal: null });
    });

    it('answers null for a PDF without form fields, or for bytes that are not a PDF', async () => {
        const { PDFDocument } = await import('@cantoo/pdf-lib');
        const flat = await PDFDocument.create();
        flat.addPage();

        expect(await readImportFields(await flat.save())).toBeNull();
        expect(await readImportFields(new TextEncoder().encode('not a pdf'))).toBeNull();
    });

    it('reads every chosen file by name', async () => {
        const file = (name, bytes) => ({ name, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) });

        const read = await readAllImportFields([file('a.pdf', template('7CAV-R-042')), file('junk.pdf', new TextEncoder().encode('junk'))]);

        expect(read['a.pdf'].type).toBe('dcs');
        expect(read['junk.pdf']).toBeNull();
    });
});

describe('the check table', () => {
    it('shortens a Drive link to the end of its file id, and leaves a file name', () => {
        expect(sourceLabel({ source: 'drive', label: 'https://drive.google.com/file/d/1AbCdEfGh1Qw8Tz/view' })).toBe('drive …/1Qw8Tz');
        expect(sourceLabel({ source: 'drive', label: 'https://drive.google.com/open?id=1AbCdEf9Kp2Lm' })).toBe('drive …/9Kp2Lm');
        expect(sourceLabel({ source: 'drive', label: 'https://drive.google.com/drive/folders/77777AbX7Ab' })).toBe('drive …/AbX7Ab');
        expect(sourceLabel({ source: 'file', label: 'scan-0412.pdf' })).toBe('scan-0412.pdf');
    });

    it('counts what the import would store: a row waiting for a trooper pick only once picked', () => {
        const rows = [
            { line: 2, outcome: 'import', candidates: [] },
            { line: 3, outcome: 'review', candidates: [] },
            { line: 4, outcome: 'review', candidates: [{ id: 1 }, { id: 2 }] },
            { line: 5, outcome: 'skipped', candidates: [] },
        ];

        expect(importableCount(rows)).toBe(2);
        expect(importableCount(rows, { 4: 2 })).toBe(3);
    });

    it('writes a date the portal way', () => {
        expect(importDate('2025-03-12')).toBe('12 Mar 2025');
        expect(importDate(null)).toBe('');
    });
});
