import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, toRaw } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import CounselingPanel from '../CounselingPanel.vue';

// #381: the counseling panel draws what the server sent and posts the forms; it decides nothing about who may look.

const sent = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

const previewed = vi.fn();
vi.mock('@/lib/postJson', () => ({ postJson: (...args) => previewed(...args) }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        useForm: (data) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors(field) {
                    if (field) delete this.errors[field];
                    else this.errors = {};
                },
                setError(field, message) {
                    this.errors[field] = message;
                },
                reset() {
                    Object.assign(this, data);
                },
            });
            let transform = (payload) => payload;
            form.transform = (callback) => {
                transform = callback;

                return form;
            };
            for (const method of ['post', 'patch', 'delete']) {
                form[method] = (url, options) => sent(method, url, transform({ ...form }), options);
            }

            return form;
        },
    };
});

const record = (overrides = {}) => ({
    id: 11,
    type: 'dcs',
    typeLabel: 'Developmental Counseling Statement',
    date: '2026-10-20',
    reason: 'Missed two section practices.\nNo notice given.',
    issuer: 'Okafor.B',
    document: { name: 'Brandt DCS.pdf', size: 34 * 1024 },
    driveStatus: 'uploaded',
    kind: 'missed_practice',
    kindLabel: 'Missed section practice or roll call',
    kindAssumed: false,
    flags: [],
    canChange: false,
    ...overrides,
});

const counseling = (overrides = {}) => ({
    records: [],
    warnings: [],
    forecast: {},
    departed: false,
    sees: { dcs: true, lor: true },
    canFile: { dcs: false, lor: false },
    ...overrides,
});

const panel = (overrides = {}) => mount(CounselingPanel, { props: { trooperId: 7, counseling: counseling(overrides) } });

describe('CounselingPanel', () => {
    beforeEach(() => {
        sent.mockReset();
        previewed.mockReset();
        document.body.innerHTML = '';
    });

    it('lists each record with its type, date, what it is for, reason, issuer, document and Drive status', () => {
        const wrapper = panel({ records: [record(), record({ id: 12, type: 'lor', typeLabel: 'Letter of Reprimand', kind: null, kindLabel: null, driveStatus: 'skipped' })] });
        const [dcs, lor] = wrapper.findAll('[data-testid="counseling-record"]');

        expect(wrapper.get('[data-testid="counseling-count"]').text()).toBe('2');
        expect(dcs.get('[data-testid="counseling-type-badge"]').text()).toBe('DCS: Developmental Counseling Statement');
        expect(dcs.get('[data-testid="counseling-date-text"]').text()).toBe('20Oct26');
        expect(dcs.get('[data-testid="counseling-kind-text"]').text()).toBe('Missed section practice or roll call');
        expect(dcs.find('[data-testid="counseling-flags"]').exists()).toBe(false);
        expect(dcs.get('[data-testid="counseling-reason-text"]').text()).toContain('No notice given.');
        expect(dcs.get('[data-testid="counseling-issuer"]').text()).toBe('Filed by Okafor.B');
        expect(dcs.get('[data-testid="counseling-download"]').attributes('href')).toBe('/counseling/11/document');
        expect(dcs.get('[data-testid="counseling-download"]').text()).toContain('Brandt DCS.pdf');
        expect(dcs.get('[data-testid="counseling-download"]').text()).toContain('(34 KB)');
        expect(dcs.get('[data-testid="counseling-drive"]').text()).toBe('Copied to Drive');
        expect(lor.find('[data-testid="counseling-kind-text"]').exists()).toBe(false);
        expect(lor.find('[data-testid="counseling-kind-assumed"]').exists()).toBe(false);
        expect(lor.get('[data-testid="counseling-drive"]').text()).toBe('Portal only');
    });

    it('renders a reason as text, never as markup', () => {
        const wrapper = panel({ records: [record({ reason: '<img src=x onerror=alert(1)>' })] });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-testid="counseling-reason-text"]').text()).toBe('<img src=x onerror=alert(1)>');
    });

    it('shows the banner and the flags on each record that the server worked out', () => {
        const wrapper = panel({
            warnings: [{ key: 'no-favorable-action', message: 'No favorable action until 27Nov26, 30 days after the LOR of 28Oct26.', until: '2026-11-27' }],
            records: [
                record({ type: 'lor', flags: [{ key: 'lor-article-15', message: '3 LORs within 90 days: Article 15.' }] }),
                record({ id: 12, kind: null, kindLabel: null, kindAssumed: true, flags: [] }),
            ],
        });
        const [flagged, legacy] = wrapper.findAll('[data-testid="counseling-record"]');

        expect(wrapper.get('[data-testid="counseling-warning-no-favorable-action"]').text()).toBe('No favorable action until 27Nov26, 30 days after the LOR of 28Oct26.');
        expect(flagged.get('[data-testid="counseling-flag-lor-article-15"]').text()).toBe('3 LORs within 90 days: Article 15.');
        expect(legacy.find('[data-testid="counseling-flags"]').exists()).toBe(false);
        expect(legacy.get('[data-testid="counseling-kind-assumed"]').text()).toContain('the other conduct rules apply to it');
    });

    it("says a departed trooper's records are read-only, and offers nothing to file or change", () => {
        const wrapper = panel({ departed: true, records: [record({ canChange: false })] });

        expect(wrapper.get('[data-testid="counseling-departed"]').text()).toContain('no longer serving, so their counseling records are read-only');
        expect(wrapper.find('[data-testid="counseling-file-open"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="counseling-edit"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="counseling-download"]').exists()).toBe(true);
        expect(panel().find('[data-testid="counseling-departed"]').exists()).toBe(false);
    });

    it('says what an empty list is empty of', () => {
        expect(panel().get('[data-testid="counseling-empty"]').text()).toBe('No counseling records on file.');
        expect(panel({ sees: { dcs: false, lor: true } }).get('[data-testid="counseling-empty"]').text()).toBe('No Letters of Reprimand on file.');
    });

    it('offers filing only when the server allows it, and only the types it allows', async () => {
        expect(panel().find('[data-testid="counseling-file-open"]').exists()).toBe(false);

        const wrapper = panel({ canFile: { dcs: true, lor: false } });
        await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');

        const options = wrapper.findAll('[data-testid="counseling-type"] option');
        expect(options.map((option) => option.attributes('value'))).toEqual(['dcs']);
    });

    it("says what the record would be flagged with, by the date and what it is for, from the server's forecast", async () => {
        const lorDue = { key: 'missed-lor', message: 'Second miss within 30 days of the last: an LOR is called for.' };
        const wrapper = panel({
            canFile: { dcs: true, lor: false },
            forecast: {
                dcs: {
                    missed_practice: [{ from: '1970-01-01', flags: [] }, { from: '2026-01-10', flags: [lorDue] }, { from: '2026-02-10', flags: [] }],
                    conduct: [{ from: '1970-01-01', flags: [] }],
                },
            },
        });
        await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');

        expect(wrapper.get('[data-testid="counseling-kind"]').element.value).toBe('');
        expect(wrapper.find('[data-testid="counseling-hint"]').exists()).toBe(false);

        await wrapper.get('[data-testid="counseling-kind"]').setValue('missed_practice');
        await wrapper.get('[data-testid="counseling-date"]').setValue('2026-01-20');
        expect(wrapper.get('[data-testid="counseling-hint-missed-lor"]').text()).toBe('Second miss within 30 days of the last: an LOR is called for.');

        await wrapper.get('[data-testid="counseling-date"]').setValue('2026-02-10');
        expect(wrapper.find('[data-testid="counseling-hint"]').exists()).toBe(false);

        await wrapper.get('[data-testid="counseling-date"]').setValue('2026-01-20');
        await wrapper.get('[data-testid="counseling-kind"]').setValue('conduct');
        expect(wrapper.find('[data-testid="counseling-hint"]').exists()).toBe(false);
    });

    it('flags a DCS filed within 30 days of an LOR on the record, and warns before one is filed in that window', async () => {
        const afterLor = { key: 'dcs-after-lor', message: 'Within 30 days of an LOR: the regulation calls for a repeat LOR, not a DCS, if it is for the same issue.' };
        const wrapper = panel({
            records: [record({ kind: 'conduct', kindLabel: 'Other conduct', flags: [afterLor] })],
            canFile: { dcs: true, lor: false },
            forecast: {
                dcs: {
                    missed_practice: [{ from: '1970-01-01', flags: [] }],
                    // An LOR on 20Sep26: its 30 days run to 20Oct26.
                    conduct: [{ from: '1970-01-01', flags: [] }, { from: '2026-09-20', flags: [afterLor] }, { from: '2026-10-21', flags: [] }],
                },
            },
        });

        expect(wrapper.get('[data-testid="counseling-flag-dcs-after-lor"]').text()).toBe(afterLor.message);

        await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');
        await wrapper.get('[data-testid="counseling-kind"]').setValue('conduct');
        await wrapper.get('[data-testid="counseling-date"]').setValue('2026-10-20');
        expect(wrapper.get('[data-testid="counseling-hint-dcs-after-lor"]').text()).toBe(afterLor.message);

        await wrapper.get('[data-testid="counseling-date"]').setValue('2026-10-21');
        expect(wrapper.find('[data-testid="counseling-hint"]').exists()).toBe(false);

        await wrapper.get('[data-testid="counseling-date"]').setValue('2026-10-10');
        await wrapper.get('[data-testid="counseling-kind"]').setValue('missed_practice');
        expect(wrapper.find('[data-testid="counseling-hint"]').exists()).toBe(false);
    });

    it('asks what the record is for before sending anything', async () => {
        const wrapper = panel({ canFile: { dcs: true, lor: false } });
        await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');
        await wrapper.get('[data-testid="counseling-reason"]').setValue('Missed practice.');
        await wrapper.get('[data-testid="counseling-form"]').trigger('submit');
        await nextTick();

        expect(sent).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="counseling-error-kind"]').text()).toBe('Choose what the record is for.');
    });

    it('files a record with its PDF as form data', async () => {
        const wrapper = panel({ canFile: { dcs: true, lor: true } });
        await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');
        await wrapper.get('[data-testid="counseling-type"]').setValue('lor');
        await wrapper.get('[data-testid="counseling-kind"]').setValue('conduct');
        await wrapper.get('[data-testid="counseling-reason"]').setValue('Disrespect toward an NCO.');
        const file = new File(['%PDF-1.4'], 'lor.pdf', { type: 'application/pdf' });
        const input = wrapper.get('[data-testid="counseling-document"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');
        await wrapper.get('[data-testid="counseling-form"]').trigger('submit');

        expect(sent).toHaveBeenCalledOnce();
        const [method, url, data, options] = sent.mock.calls[0];
        expect(method).toBe('post');
        expect(url).toBe('/troopers/7/counseling');
        expect(data.type).toBe('lor');
        expect(data.kind).toBe('conduct');
        expect(data.reason).toBe('Disrespect toward an NCO.');
        expect(toRaw(data.document)).toBe(file);
        expect(options.forceFormData).toBe(true);
    });

    it('stops a missing or non-PDF file before sending anything', async () => {
        const wrapper = panel({ canFile: { dcs: true, lor: false } });
        await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');
        await wrapper.get('[data-testid="counseling-kind"]').setValue('missed_practice');
        await wrapper.get('[data-testid="counseling-form"]').trigger('submit');
        await nextTick();

        expect(sent).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="counseling-error-document"]').text()).toBe('Attach the signed document as a PDF.');
    });

    it('offers correcting and deleting only on a record the server lets the viewer change', async () => {
        const wrapper = panel({ records: [record({ canChange: false }), record({ id: 12, canChange: true })] });
        const [locked, open] = wrapper.findAll('[data-testid="counseling-record"]');

        expect(locked.find('[data-testid="counseling-edit"]').exists()).toBe(false);
        expect(locked.find('[data-testid="counseling-delete"]').exists()).toBe(false);

        await open.get('[data-testid="counseling-edit"]').trigger('click');
        await open.get('[data-testid="counseling-edit-reason"]').setValue('Missed three practices.');
        await open.get('[data-testid="counseling-edit-why"]').setValue('Count was wrong.');
        await open.get('[data-testid="counseling-edit-form"]').trigger('submit');

        expect(sent).toHaveBeenCalledWith('patch', '/counseling/12', expect.objectContaining({ counseled_on: '2026-10-20', reason: 'Missed three practices.', audit_reason: 'Count was wrong.' }), expect.any(Object));
    });

    it('deletes a record with the reason given', async () => {
        const wrapper = panel({ records: [record({ canChange: true })] });
        await wrapper.get('[data-testid="counseling-delete"]').trigger('click');

        expect(wrapper.get('[data-testid="counseling-delete-form"]').text()).toContain('A copy already on the 7Cav Google Drive stays there.');

        await wrapper.get('[data-testid="counseling-delete-why"]').setValue('Filed on the wrong trooper.');
        await wrapper.get('[data-testid="counseling-delete-form"]').trigger('submit');

        expect(sent).toHaveBeenCalledWith('delete', '/counseling/11', expect.objectContaining({ audit_reason: 'Filed on the wrong trooper.' }), expect.any(Object));
    });

    it('offers neither correcting nor deleting on a frozen or overturned LOR', () => {
        const overturned = { date: '2026-10-29', by: 'Vance.R', reason: 'Appeal upheld.' };
        const wrapper = panel({
            records: [record({ type: 'lor', canChange: true, frozen: true }), record({ id: 12, type: 'lor', canChange: true, frozen: true, overturned })],
        });

        expect(wrapper.find('[data-testid="counseling-edit"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="counseling-delete"]').exists()).toBe(false);
    });

    it('offers Overturn only where the server allows it, and posts it with the reason given', async () => {
        const wrapper = panel({
            records: [record({ type: 'lor', frozen: true, canOverturn: false }), record({ id: 12, type: 'lor', frozen: true, canOverturn: true })],
        });
        const [other, open] = wrapper.findAll('[data-testid="counseling-record"]');

        expect(other.find('[data-testid="counseling-overturn"]').exists()).toBe(false);

        await open.get('[data-testid="counseling-overturn"]').trigger('click');
        expect(open.get('[data-testid="counseling-overturn-form"]').text()).toContain('It stays on file, marked overturned with your reason');
        await open.get('[data-testid="counseling-overturn-why"]').setValue('Appeal upheld by the RXO.');
        await open.get('[data-testid="counseling-overturn-form"]').trigger('submit');

        expect(sent).toHaveBeenCalledWith('post', '/counseling/12/overturn', expect.objectContaining({ reason: 'Appeal upheld by the RXO.' }), expect.any(Object));
    });

    it('shows an overturned LOR muted, with when, by whom and why, and still downloadable', () => {
        const wrapper = panel({
            records: [record({ type: 'lor', frozen: true, canOverturn: true, overturned: { date: '2026-10-29', by: 'Hale.S for Vance.R', reason: 'Appeal upheld.' } })],
        });
        const row = wrapper.get('[data-testid="counseling-record"]');

        expect(row.attributes('data-overturned')).toBe('true');
        expect(row.get('[data-testid="counseling-overturned"]').text()).toBe('Overturned on 29Oct26 by Hale.S for Vance.R: Appeal upheld.');
        expect(row.get('[data-testid="counseling-reason-text"]').classes()).toContain('text-muted-foreground');
        expect(row.find('[data-testid="counseling-overturn"]').exists()).toBe(false);
        expect(row.find('[data-testid="counseling-download"]').exists()).toBe(true);
        expect(panel({ records: [record()] }).find('[data-testid="counseling-overturned"]').exists()).toBe(false);
    });

    describe('a demo document', () => {
        // Clicks the download link and reports whether the panel stopped the download; the listener on the document
        // runs after the panel's own and keeps jsdom from navigating.
        async function clickDownload(wrapper) {
            let prevented = null;
            const listener = (event) => {
                prevented = event.defaultPrevented;
                event.preventDefault();
            };
            document.addEventListener('click', listener);
            wrapper.get('[data-testid="counseling-download"]').element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            document.removeEventListener('click', listener);
            await nextTick();

            return prevented;
        }

        it('says it is a demo file instead of downloading it', async () => {
            const wrapper = mount(CounselingPanel, {
                props: { trooperId: 7, counseling: counseling({ records: [record({ document: { name: 'dcs-signed.pdf', size: 52, demo: true } })] }) },
                attachTo: document.body,
            });

            expect(await clickDownload(wrapper)).toBe(true);
            expect(document.body.querySelector('[data-testid="counseling-demo-dialog"]')?.textContent).toContain(
                'This file is a demo file and will not be downloaded. You can generate a real one with the form.',
            );
            wrapper.unmount();
        });

        it('leaves a real document to download', async () => {
            const wrapper = mount(CounselingPanel, { props: { trooperId: 7, counseling: counseling({ records: [record()] }) }, attachTo: document.body });

            expect(await clickDownload(wrapper)).toBe(false);
            expect(document.body.querySelector('[data-testid="counseling-demo-dialog"]')).toBeNull();
            wrapper.unmount();
        });
    });

    describe('filling in the form in the portal', () => {
        const parties = {
            trooper: { rank: 'PFC', name: 'Brandt.L', billet: 'Rifleman' },
            authority: { rank: 'SGT', name: 'Okafor.B', billet: 'Section Leader' },
        };

        async function openForm(overrides = {}) {
            const wrapper = mount(CounselingPanel, {
                props: { trooperId: 7, counseling: counseling({ canFile: { dcs: true, lor: true }, formParties: parties, ...overrides }) },
                attachTo: document.body,
            });
            await wrapper.get('[data-testid="counseling-file-open"]').trigger('click');

            return wrapper;
        }

        async function fillSection2(wrapper) {
            await wrapper.get('[data-testid="counseling-kind"]').setValue('conduct');
            await wrapper.get('[data-testid="counseling-reason"]').setValue('Disrespect toward an NCO.');
            await wrapper.get('[data-testid="counseling-form-infraction"]').setValue('Swore at the SL during practice.');
            await wrapper.get('[data-testid="counseling-form-regulation"]').setValue('7CAV-R-042');
            await wrapper.get('[data-testid="counseling-form-discussion"]').setValue('Talked it through after practice.');
            await wrapper.get('[data-testid="counseling-form-plan_authority"]').setValue('Check in next week.');
        }

        it('switches between uploading a PDF and filling in the form', async () => {
            const wrapper = await openForm();

            expect(wrapper.find('[data-testid="counseling-document"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="counseling-generate"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-preview"]').exists()).toBe(false);

            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);

            expect(wrapper.find('[data-testid="counseling-document"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-generate"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="counseling-preview"]').exists()).toBe(true);
            expect(wrapper.get('label[for="counseling-date"]').text()).toBe('Date of letter');

            await wrapper.get('[data-testid="counseling-source-upload"]').setValue(true);
            expect(wrapper.find('[data-testid="counseling-document"]').exists()).toBe(true);
            wrapper.unmount();
        });

        it('shows section 1 from the roster, read only, with the authority named for the type', async () => {
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);

            const trooper = wrapper.get('[data-testid="counseling-party-trooper"]');
            const authority = wrapper.get('[data-testid="counseling-party-authority"]');
            expect(trooper.text()).toContain('PFC');
            expect(trooper.get('[data-testid="counseling-party-name"]').text()).toBe('Brandt.L');
            expect(trooper.text()).toContain('Rifleman');
            expect(authority.text()).toContain('Counselling authority');
            expect(authority.get('[data-testid="counseling-party-name"]').text()).toBe('Okafor.B');
            expect(wrapper.get('[data-testid="counseling-section-1"]').find('input').exists()).toBe(false);
            expect(wrapper.get('[data-testid="counseling-date"]').element.value).toMatch(/^\d{4}-\d{2}-\d{2}$/);

            await wrapper.get('[data-testid="counseling-type"]').setValue('lor');
            expect(wrapper.get('[data-testid="counseling-party-authority"]').text()).toContain('Disciplining authority');
            wrapper.unmount();
        });

        it('asks for the consequence and the appeal on an LOR only', async () => {
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);

            expect(wrapper.find('[data-testid="counseling-form-consequences"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-form-appeal"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-dcs-fixed"]').exists()).toBe(true);

            await wrapper.get('[data-testid="counseling-type"]').setValue('lor');
            expect(wrapper.find('[data-testid="counseling-form-consequences"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="counseling-form-appeal"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="counseling-form-special_punishments"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="counseling-form-removal"]').exists()).toBe(false);

            await wrapper.get('[data-testid="counseling-consequence-removal"]').setValue(true);
            expect(wrapper.find('[data-testid="counseling-form-removal"]').exists()).toBe(true);
            wrapper.unmount();
        });

        it('files an LOR from the form as plain data, with no file and the consequence and appeal chosen', async () => {
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);
            await wrapper.get('[data-testid="counseling-type"]').setValue('lor');
            await fillSection2(wrapper);
            await wrapper.get('[data-testid="counseling-consequence-nfa"]').setValue(true);
            await wrapper.get('[data-testid="counseling-consequence-removal"]').setValue(true);
            await wrapper.get('[data-testid="counseling-removal-section_leader"]').setValue(true);
            await wrapper.get('[data-testid="counseling-appeal-do_not"]').setValue(true);
            await wrapper.get('[data-testid="counseling-form"]').trigger('submit');

            expect(sent).toHaveBeenCalledOnce();
            const [method, url, data, options] = sent.mock.calls[0];
            expect(method).toBe('post');
            expect(url).toBe('/troopers/7/counseling');
            expect(data.source).toBe('form');
            expect(data.document).toBeUndefined();
            expect(data.form.infraction).toBe('Swore at the SL during practice.');
            expect(data.form.consequences).toEqual(['nfa', 'removal']);
            expect(data.form.removal).toBe('section_leader');
            expect(data.form.appeal).toBe('do_not');
            expect(options.forceFormData).toBe(false);
            wrapper.unmount();
        });

        it('leaves the LOR fields out of a DCS', async () => {
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);
            await fillSection2(wrapper);
            await wrapper.get('[data-testid="counseling-form"]').trigger('submit');

            const [, , data] = sent.mock.calls[0];
            expect(data.type).toBe('dcs');
            expect(data.form).not.toHaveProperty('appeal');
            expect(data.form).not.toHaveProperty('consequences');
            wrapper.unmount();
        });

        it('previews the document the server renders, in a frame that runs nothing', async () => {
            previewed.mockResolvedValue({ ok: true, status: 200, data: { html: '<p>7CAV-R-042 &lt;script&gt;</p>' } });
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);
            await fillSection2(wrapper);
            await wrapper.get('[data-testid="counseling-preview"]').trigger('click');
            await flushPromises();

            expect(previewed).toHaveBeenCalledOnce();
            const [url, body] = previewed.mock.calls[0];
            expect(url).toBe('/troopers/7/counseling/preview');
            expect(body.type).toBe('dcs');
            expect(body.form.plan_authority).toBe('Check in next week.');
            expect(sent).not.toHaveBeenCalled();

            const frame = document.body.querySelector('[data-testid="counseling-preview-frame"]');
            expect(frame).not.toBeNull();
            expect(frame.getAttribute('sandbox')).toBe('');
            expect(frame.getAttribute('srcdoc')).toContain('7CAV-R-042');
            wrapper.unmount();
        });

        it('shows a preview refusal next to the fields it names', async () => {
            previewed.mockResolvedValue({ ok: false, status: 422, data: { errors: { 'form.infraction': ['Give the infraction and its details.'] } } });
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);
            await wrapper.get('[data-testid="counseling-preview"]').trigger('click');
            await flushPromises();

            expect(wrapper.get('[data-testid="counseling-error-form-infraction"]').text()).toBe('Give the infraction and its details.');
            expect(document.body.querySelector('[data-testid="counseling-preview-frame"]')).toBeNull();
            wrapper.unmount();
        });

        it('clears a field\'s error as soon as it is edited', async () => {
            previewed.mockResolvedValue({
                ok: false,
                status: 422,
                data: { errors: { 'form.infraction': ['Give the infraction and its details.'], kind: ['Choose what the record is for.'] } },
            });
            const wrapper = await openForm();
            await wrapper.get('[data-testid="counseling-source-form"]').setValue(true);
            await wrapper.get('[data-testid="counseling-preview"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="counseling-error-form-infraction"]').exists()).toBe(true);

            await wrapper.get('[data-testid="counseling-form-infraction"]').setValue('Swore at the SL.');
            expect(wrapper.find('[data-testid="counseling-error-form-infraction"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-error-kind"]').exists()).toBe(true);

            await wrapper.get('[data-testid="counseling-kind"]').setValue('conduct');
            expect(wrapper.find('[data-testid="counseling-error-kind"]').exists()).toBe(false);
            wrapper.unmount();
        });
    });

    describe('the 72-hour freeze and the trooper\'s appeal', () => {
        it('shows a Frozen badge with the time it froze, and no correct or appeal line then', () => {
            const wrapper = panel({ records: [record({ frozen: true, freezesAt: '2026-11-02T10:00:00Z', canChange: true })] });

            expect(wrapper.get('[data-testid="counseling-frozen"]').text()).toBe('Frozen 2026-11-02 10:00Z');
            expect(wrapper.get('[data-testid="counseling-frozen"] time').attributes('datetime')).toBe('2026-11-02T10:00:00Z');
            expect(wrapper.find('[data-testid="counseling-correct-until"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-appeal-until"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-edit"]').exists()).toBe(false);
        });

        it('tells the chain until when the record can be corrected', () => {
            const wrapper = panel({ records: [record({ frozen: false, freezesAt: '2026-11-02T10:00:00Z', canChange: true })] });

            expect(wrapper.get('[data-testid="counseling-correct-until"]').text()).toBe('Can be corrected until 2026-11-02 10:00Z');
            expect(wrapper.find('[data-testid="counseling-frozen"]').exists()).toBe(false);
        });

        it('tells the trooper until when they can appeal an LOR, or comment on or dispute a DCS', () => {
            const wrapper = panel({
                records: [
                    record({ id: 12, type: 'lor', frozen: false, freezesAt: '2026-11-02T10:00:00Z', canAppeal: true }),
                    record({ frozen: false, freezesAt: '2026-11-01T08:30:00Z', canAppeal: true }),
                ],
            });
            const [lor, dcs] = wrapper.findAll('[data-testid="counseling-record"]');

            expect(lor.get('[data-testid="counseling-appeal-until"]').text()).toBe('Appeal until 2026-11-02 10:00Z');
            expect(lor.get('[data-testid="counseling-appeal-open"]').text()).toBe('Appeal');
            expect(dcs.get('[data-testid="counseling-appeal-until"]').text()).toBe('Comment or dispute until 2026-11-01 08:30Z');
            expect(dcs.get('[data-testid="counseling-appeal-open"]').text()).toBe('Comment or dispute');
            expect(wrapper.find('[data-testid="counseling-correct-until"]').exists()).toBe(false);
        });

        it('flags a filed appeal for the leader and shows the statement with its time', () => {
            const wrapper = panel({
                records: [record({ id: 12, type: 'lor', canChange: true, freezesAt: '2026-11-02T10:00:00Z', appeal: { at: '2026-10-30T12:00:00Z', statement: 'It was a misunderstanding.' } })],
            });

            expect(wrapper.get('[data-testid="counseling-appeal-filed"]').text()).toBe('Appeal filed');
            expect(wrapper.get('[data-testid="counseling-appeal"]').text()).toBe('Appeal filed 2026-10-30 12:00Z: It was a misunderstanding.');
            expect(wrapper.find('[data-testid="counseling-appeal-open"]').exists()).toBe(false);
        });

        it('calls a filed DCS answer a comment or dispute', () => {
            const wrapper = panel({ records: [record({ appeal: { at: '2026-10-30T12:00:00Z', statement: 'I told my SL.' } })] });

            expect(wrapper.get('[data-testid="counseling-appeal-filed"]').text()).toBe('Comment or dispute filed');
        });

        it('opens the appeal dialog, refuses an empty statement, and posts the statement', async () => {
            const wrapper = mount(CounselingPanel, {
                props: { trooperId: 7, counseling: counseling({ records: [record({ id: 12, type: 'lor', freezesAt: '2026-11-02T10:00:00Z', canAppeal: true })] }) },
                attachTo: document.body,
            });
            await wrapper.get('[data-testid="counseling-appeal-open"]').trigger('click');
            await flushPromises();

            const dialog = document.body.querySelector('[data-testid="counseling-appeal-dialog"]');
            expect(dialog).not.toBeNull();
            expect(dialog.textContent).toContain('Appeal this LOR');
            expect(dialog.textContent).toContain('2026-11-02 10:00Z');
            const textarea = document.body.querySelector('[data-testid="counseling-appeal-statement"]');
            expect(textarea.getAttribute('maxlength')).toBe('2000');

            document.body.querySelector('[data-testid="counseling-appeal-form"]').dispatchEvent(new Event('submit'));
            await flushPromises();
            expect(sent).not.toHaveBeenCalled();
            expect(document.body.querySelector('[data-testid="counseling-appeal-error"]').textContent).toBe('Write your statement.');

            textarea.value = 'It was a misunderstanding.';
            textarea.dispatchEvent(new Event('input'));
            // The Textarea passes its value up on the next tick.
            await flushPromises();
            document.body.querySelector('[data-testid="counseling-appeal-form"]').dispatchEvent(new Event('submit'));
            await flushPromises();

            expect(sent).toHaveBeenCalledWith('post', '/counseling/12/appeal', expect.objectContaining({ statement: 'It was a misunderstanding.' }), expect.any(Object));
            wrapper.unmount();
        });
    });
});
