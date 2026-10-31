// @vitest-environment node
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { PDFDocument, PDFName } from '@cantoo/pdf-lib';
import { documentPayload } from '../counseling';
import { fillCounselingForm } from '../counselingPdf';

// #565: the regiment's forms are filled in the browser from the fields the server (or the page) worked out. These run
// against the checked-in templates, so a renamed field or a moved radio button fails here and not on a leader's desk.

const template = (name) => new Uint8Array(readFileSync(fileURLToPath(new URL(`../../../counseling/${name}.pdf`, import.meta.url))));

const section1 = {
    trooper: { rank: 'PFC', name: 'Okafor.D', billet: 'Rifleman' },
    authority: { rank: '2LT', name: 'Varga.T', billet: 'Platoon Leader' },
};
const text = {
    infraction: 'Missed section practice on 10, 17 and 24 Oct.\nNo LOA. 🙂',
    regulation: '2/B/1-7 Platoon SOP',
    discussion: 'We met.',
    plan_authority: 'Check in weekly.',
    plan_trooper: 'Post an LOA.',
    plan_other: 'Section leader confirms.',
    comments_authority: 'None.',
    comments_trooper: 'Understood.',
    comments_other: 'n/a',
};
const dcs = documentPayload({ type: 'dcs', date: '2026-10-29', section1, form: text, trooper: { id: 4, name: 'Okafor.D' } });
const lor = documentPayload({
    type: 'lor',
    date: '2026-10-29',
    section1,
    form: { ...text, consequences: ['nfa', 'removal', 'article_15', 'special_punishment'], removal: 'assistant_section_leader', special_punishments: 'Extra duty.', appeal: 'do' },
    outcome: 'amended',
    trooper: { id: 4, name: 'Okafor.D' },
});

async function filled(name, payload) {
    return PDFDocument.load(await fillCounselingForm(template(name), payload));
}

// The widget that is on in a radio group, by its place on the page.
function onWidget(form, group) {
    const widgets = form.getRadioGroup(group).acroField.getWidgets();
    const on = widgets.filter((widget) => widget.getAppearanceState()?.toString() !== '/Off');
    expect(on).toHaveLength(1);

    return { rect: on[0].getRectangle(), others: widgets.filter((w) => w !== on[0]).map((w) => w.getRectangle()), value: on[0].getOnValue() };
}

describe('counselingPdf', () => {
    it('names only fields the templates have, for a DCS and an LOR', async () => {
        for (const [name, payload] of [['7CAV-R-042', dcs], ['7CAV-R-043', lor]]) {
            const names = new Set((await PDFDocument.load(template(name))).getForm().getFields().map((f) => f.getName()));
            for (const field of [...Object.keys(payload.text), ...payload.checks, ...payload.radios.map((r) => r.group)]) {
                expect(names, `${name} has ${field}`).toContain(field);
            }
        }
        expect(dcs.template).toBe('7CAV-R-042');
        expect(lor.template).toBe('7CAV-R-043');
        expect(lor.fileName).toBe('LOR 2026-10-29 Okafor.D.pdf');
    });

    it('fills the text fields, with what Helvetica cannot draw as ?', async () => {
        const form = (await filled('7CAV-R-042', dcs)).getForm();

        expect(form.getTextField('Date of Letter').getText()).toBe('29 Oct 2026');
        expect(form.getTextField('Name LastFCounselling Authority').getText()).toBe('Varga.T');
        expect(form.getTextField('Counselling Authority2').getText()).toBe('Post an LOA.');
        expect(form.getTextField('Evidenced infraction and associated details  Previous instances and counsellings').getText()).toBe(
            'Missed section practice on 10, 17 and 24 Oct.\nNo LOA. ?',
        );
    });

    it('checks the consequence boxes and draws every appearance', async () => {
        const form = (await filled('7CAV-R-043', lor)).getForm();

        for (const box of ['Check Box1', 'Check Box2', 'Check Box3', 'Check Box4']) expect(form.getCheckBox(box).isChecked()).toBe(true);
        expect(form.getTextField('Name LastFDisciplining Authority').acroField.getWidgets()[0].getAppearances()?.normal).toBeTruthy();
    });

    it('turns on the radio widget at the place each choice names, and sets the group value to it', async () => {
        const form = (await filled('7CAV-R-043', lor)).getForm();

        // Removal from ASL: the lower button of Group2.
        const removal = onWidget(form, 'Group2');
        expect(removal.others.every((r) => r.y > removal.rect.y)).toBe(true);
        // DO wish to appeal: the left button of Group4.
        const appeal = onWidget(form, 'Group4');
        expect(appeal.others.every((r) => r.x > appeal.rect.x)).toBe(true);
        // HAVE AMENDED: the left button of Group3, which the template lists second.
        const outcome = onWidget(form, 'Group3');
        expect(outcome.others.every((r) => r.x > outcome.rect.x)).toBe(true);
        expect(form.getRadioGroup('Group3').acroField.dict.get(PDFName.of('V'))).toBe(outcome.value);
    });

    it('picks the opposite widgets for the other choices', async () => {
        const payload = documentPayload({
            type: 'lor',
            date: '2026-10-29',
            section1,
            form: { consequences: ['removal'], removal: 'section_leader', appeal: 'do_not' },
            outcome: 'not_amended',
        });
        const form = (await filled('7CAV-R-043', payload)).getForm();

        const removal = onWidget(form, 'Group2');
        expect(removal.others.every((r) => r.y < removal.rect.y)).toBe(true);
        const appeal = onWidget(form, 'Group4');
        expect(appeal.others.every((r) => r.x < appeal.rect.x)).toBe(true);
        const outcome = onWidget(form, 'Group3');
        expect(outcome.others.every((r) => r.x < outcome.rect.x)).toBe(true);
    });
});
