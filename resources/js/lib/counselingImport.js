import { CONSEQUENCE_CHECKS, RADIOS, SECTION_1, SPECIAL_PUNISHMENTS, TEXT_FIELDS, typeOfFieldNames } from '@/lib/counselingFields';
import { pickWidget } from '@/lib/counselingPdf';

// The counseling import's browser half (#565, Settings → Counseling import). Before the check, the Admin's browser
// reads the AcroForm of each PDF they chose, with the same field names the portal fills the forms with
// (counselingFields.js), and sends what it read beside the files. The server treats it as a hint: it checks every
// value again and stores none of it unless the row resolves (App\Commands\Counseling\ImportFields). A PDF with no form
// fields (scanned or flattened) reads as null, and one pdf-lib cannot open as `UNREADABLE`, which the server flags for
// review with that reason. Drive links are not read here: the browser cannot fetch them, so the server imports them for
// review.

// What the server takes for a PDF the browser could not open.
export const UNREADABLE = Object.freeze({ error: 'unreadable' });

// pdf-lib, loaded only on this page. A failure here (the chunk did not load: a deploy since the page opened, or the
// network) is not about any one PDF, so it is thrown for the page to ask for a reload.
export function loadPdfLib() {
    return import('@cantoo/pdf-lib');
}

// What one PDF's form says: `{ type, date, trooper, issuer, form }`, null when it has no form fields, or `UNREADABLE`
// when pdf-lib cannot open it. `type` is 'dcs' or 'lor' by which form it is (null for another form); `form` is keyed as
// `form_data` keys it.
export async function readImportFields(bytes, name = 'PDF') {
    const { PDFDocument } = await loadPdfLib();

    let form;
    try {
        const doc = await PDFDocument.load(bytes, { ignoreEncryption: true, updateMetadata: false });
        form = doc.getForm();
    } catch (error) {
        console.error(`Counseling import: ${name} could not be opened to read its form.`, error);
        return UNREADABLE;
    }

    const fields = form.getFields();
    if (fields.length === 0) return null;

    const names = new Set(fields.map((field) => field.getName()));
    const type = typeOfFieldNames([...names]);
    const side = type ?? 'dcs';
    const text = (name) => {
        if (!names.has(name)) return '';
        try {
            return (form.getTextField(name).getText() ?? '').trim();
        } catch {
            return '';
        }
    };
    const checked = (name) => {
        try {
            return names.has(name) && form.getCheckBox(name).isChecked();
        } catch {
            return false;
        }
    };

    const read = Object.fromEntries(Object.entries(TEXT_FIELDS).map(([key, name]) => [key, text(name)]));

    if (type === 'lor') {
        read.special_punishments = text(SPECIAL_PUNISHMENTS);
        read.consequences = Object.entries(CONSEQUENCE_CHECKS).filter(([, name]) => checked(name)).map(([key]) => key);
        read.removal = radioChoice(form, names, RADIOS.removal);
        read.appeal = radioChoice(form, names, RADIOS.appeal);
    }

    return {
        type,
        date: text(SECTION_1.date),
        trooper: text(SECTION_1.trooperName),
        issuer: text(SECTION_1.authorityName[side]),
        form: read,
    };
}

// Which choice of a radio group is on, by the place of its button on the page (the templates give both buttons the
// same export value, see counselingFields.js), or null.
function radioChoice(form, names, radio) {
    if (!names.has(radio.group)) return null;

    try {
        const widgets = form.getRadioGroup(radio.group).acroField.getWidgets();

        for (const [choice, pick] of Object.entries(radio)) {
            if (choice === 'group') continue;
            const state = pickWidget(widgets, pick).widget.getAppearanceState();
            if (state && String(state) !== '/Off') return choice;
        }
    } catch {
        return null;
    }

    return null;
}

// What every chosen PDF's form says, by file name, for the check's `fields`.
// Throws only when pdf-lib itself cannot be loaded; a file that cannot be read is `UNREADABLE`.
export async function readAllImportFields(files) {
    await loadPdfLib();
    const read = {};

    for (const file of files) {
        try {
            read[file.name] = await readImportFields(new Uint8Array(await file.arrayBuffer()), file.name);
        } catch (error) {
            console.error(`Counseling import: ${file.name} could not be read.`, error);
            read[file.name] = UNREADABLE;
        }
    }

    return read;
}

// A row's source as the table shows it: a Drive link shortened to the end of its file id, a file by its name.
export function sourceLabel(row) {
    if (row.source !== 'drive') return row.label;

    const id = /\/(?:file\/d|folders)\/([^/?#]+)/.exec(row.label)?.[1] ?? /[?&]id=([^&#]+)/.exec(row.label)?.[1];

    return id ? `drive …/${id.slice(-6)}` : row.label;
}

// What the import would store: every import row, and every review row but one waiting for a trooper pick it has not
// had. `picks` is `{ [line]: trooperId }`.
export function importableCount(rows, picks = {}) {
    return rows.filter((row) => row.outcome === 'import' || (row.outcome === 'review' && (row.candidates.length === 0 || picks[row.line]))).length;
}

// `2025-03-12` as `12 Mar 2025`, the portal's date style; empty for null.
export function importDate(value) {
    if (!value) return '';
    const [year, month, day] = value.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}
