import dcsTemplateUrl from '../../counseling/7CAV-R-042.pdf?url';
import lorTemplateUrl from '../../counseling/7CAV-R-043.pdf?url';
import { TEMPLATES } from '@/lib/counselingFields';

// The regiment's counseling form, filled in the browser (#565). The server decides what goes in every field
// (App\Commands\Counseling\CounselingDocument) and answers it as JSON; this applies it to the checked-in template and
// saves the file. Nothing is generated on the server, and pdf-lib loads only on the pages that fill a form.

const TEMPLATE_URLS = { [TEMPLATES.dcs]: dcsTemplateUrl, [TEMPLATES.lor]: lorTemplateUrl };

// The template's bytes for a payload's `template` (`7CAV-R-042` or `7CAV-R-043`).
async function templateBytes(template) {
    const url = TEMPLATE_URLS[template];
    if (!url) throw new Error(`Unknown counseling form ${template}.`);

    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(`Could not load the form template (error ${response.status}).`);

    return new Uint8Array(await response.arrayBuffer());
}

// Text the form's Helvetica cannot draw (an emoji, a script outside Western European) becomes `?`, so one odd character
// never stops the whole form. A one-line field gets its line breaks as spaces.
function drawable(value, charset, multiline) {
    const text = multiline ? String(value).replace(/\r\n?/g, '\n') : String(value).replace(/\s*[\r\n]+\s*/g, ' ');

    return Array.from(text, (char) => (char === '\n' || charset.has(char.codePointAt(0)) ? char : '?')).join('');
}

// The widget a radio choice names by its place on the page: `top`/`bottom` by the rectangle's height on the page,
// `left`/`right` by its x. The templates give both buttons of a group the same export value, so the place is the only
// thing that tells them apart.
export function pickWidget(widgets, pick) {
    const placed = widgets.map((widget, index) => ({ widget, index, rect: widget.getRectangle() }));
    const by = {
        top: (a, b) => b.rect.y - a.rect.y,
        bottom: (a, b) => a.rect.y - b.rect.y,
        left: (a, b) => a.rect.x - b.rect.x,
        right: (a, b) => b.rect.x - a.rect.x,
    }[pick];
    if (!by) throw new Error(`Unknown radio pick ${pick}.`);

    return placed.sort(by)[0];
}

// The template filled from a payload: Uint8Array of the saved PDF. `bytes` is the template; split out so a test can
// hand it the file from disk.
export async function fillCounselingForm(bytes, payload) {
    const { PDFDocument, PDFName, StandardFonts } = await import('@cantoo/pdf-lib');

    const doc = await PDFDocument.load(bytes);
    const form = doc.getForm();
    const font = await doc.embedFont(StandardFonts.Helvetica);
    const charset = new Set(font.getCharacterSet());

    for (const [name, value] of Object.entries(payload.text ?? {})) {
        const field = form.getTextField(name);
        field.setText(value ? drawable(value, charset, field.isMultiline()) : '');
    }

    for (const name of payload.checks ?? []) form.getCheckBox(name).check();

    for (const { group, pick } of payload.radios ?? []) {
        const radio = form.getRadioGroup(group);
        const widgets = radio.acroField.getWidgets();
        const chosen = pickWidget(widgets, pick);
        const on = chosen.widget.getOnValue();

        widgets.forEach((widget, index) => widget.setAppearanceState(index === chosen.index ? on : PDFName.of('Off')));
        radio.acroField.dict.set(PDFName.of('V'), on);
    }

    // Draw every field's appearance now, so the values show in every viewer, not only in those that rebuild them.
    form.updateFieldAppearances(font);

    return doc.save();
}

// The filled form for a payload from `/counseling/{id}/document`, or built live on the page (`documentPayload` in
// counseling.js).
export async function fillCounselingPdf(payload) {
    return fillCounselingForm(await templateBytes(payload.template), payload);
}

// Hand the viewer a file to save.
export function saveFile(bytes, fileName, type = 'application/pdf') {
    const url = URL.createObjectURL(bytes instanceof Blob ? bytes : new Blob([bytes], { type }));
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// The name in a Content-Disposition header, or null.
function attachmentName(header) {
    if (!header) return null;
    const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (star) return decodeURIComponent(star[1]);
    const plain = /filename="?([^";]+)"?/i.exec(header);

    return plain ? plain[1] : null;
}

// Download a record's document: a record kept with its source PDF answers that file, saved as is; any other record
// answers the form's fields, which are filled here and saved under the name the server gave. Throws an Error whose
// message says what went wrong, for the page to show.
export async function downloadCounselingDocument(recordId) {
    const response = await fetch(`/counseling/${recordId}/document`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json, application/pdf', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        const why = { 403: 'You may not download this record.', 404: 'This record is no longer there.', 429: 'Too many downloads; wait a minute and try again.' }[response.status];
        throw new Error(why ?? `The document could not be downloaded (error ${response.status}). Try again.`);
    }

    const type = response.headers.get('Content-Type') ?? '';
    if (type.includes('application/json')) {
        const payload = await response.json();
        saveFile(await fillCounselingPdf(payload), payload.fileName);

        return;
    }

    saveFile(await response.blob(), attachmentName(response.headers.get('Content-Disposition')) ?? `counseling-${recordId}.pdf`);
}
