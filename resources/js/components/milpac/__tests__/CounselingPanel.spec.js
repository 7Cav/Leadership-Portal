import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, toRaw } from 'vue';
import { mount } from '@vue/test-utils';
import CounselingPanel from '../CounselingPanel.vue';

// #381: the counseling panel draws what the server sent and posts the forms; it decides nothing about who may look.

const sent = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

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
            for (const method of ['post', 'patch', 'delete']) {
                form[method] = (url, options) => sent(method, url, { ...form }, options);
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
    kindLabel: 'Missed section practice or rollcall',
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
    beforeEach(() => sent.mockReset());

    it('lists each record with its type, date, what it is for, reason, issuer, document and Drive status', () => {
        const wrapper = panel({ records: [record(), record({ id: 12, type: 'lor', typeLabel: 'Letter of Reprimand', kind: null, kindLabel: null, driveStatus: 'skipped' })] });
        const [dcs, lor] = wrapper.findAll('[data-testid="counseling-record"]');

        expect(wrapper.get('[data-testid="counseling-count"]').text()).toBe('2');
        expect(dcs.get('[data-testid="counseling-type-badge"]').text()).toBe('DCS: Developmental Counseling Statement');
        expect(dcs.get('[data-testid="counseling-date-text"]').text()).toBe('20Oct26');
        expect(dcs.get('[data-testid="counseling-kind-text"]').text()).toBe('Missed section practice or rollcall');
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
});
