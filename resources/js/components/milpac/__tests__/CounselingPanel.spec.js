import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CounselingPanel from '../CounselingPanel.vue';

// #381, #565: the counseling panel lists what the server sent and starts a draft; it decides nothing about who may look
// or do what. Records are written on their own page.

const sent = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

const downloaded = vi.fn();
vi.mock('@/lib/counselingPdf', () => ({ downloadCounselingDocument: (...args) => downloaded(...args) }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
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
            });
            form.post = (url, options) => sent('post', url, { type: form.type, kind: form.kind }, options);

            return form;
        },
    };
});

const KINDS = [
    { value: 'missed_practice', label: 'Missed section practice or roll call' },
    { value: 'conduct', label: 'Other conduct' },
];

const record = (overrides = {}) => ({
    id: 11,
    type: 'dcs',
    typeLabel: 'Developmental Counseling Statement',
    kind: 'missed_practice',
    kindLabel: 'Missed section practice or roll call',
    state: 'acknowledged',
    source: 'portal',
    needsReview: false,
    hasSourceDocument: false,
    issuer: 'Pereira.M',
    startedAt: '2026-10-01T10:00:00Z',
    savedAt: '2026-10-01T10:00:00Z',
    date: '2026-10-03',
    meeting: { windows: [], invitedAt: null, closesAt: null, confirmed: true },
    sharedAt: '2026-10-03T12:00:00Z',
    dcsPhase: 'active',
    inactiveOn: '2026-11-03',
    expiresOn: '2026-01-02',
    withdrawn: null,
    overturned: null,
    appeal: null,
    flags: [],
    kindAssumed: false,
    document: null,
    can: { open: true, continue: false, respond: false, overturn: false },
    ...overrides,
});

const counseling = (overrides = {}) => ({
    records: [],
    warnings: [],
    forecast: {},
    departed: false,
    sees: { dcs: true, lor: true },
    canFile: { dcs: false, lor: false },
    kinds: KINDS,
    ...overrides,
});

const panel = (overrides = {}) => mount(CounselingPanel, { props: { trooperId: 7, counseling: counseling(overrides) }, attachTo: document.body });

describe('CounselingPanel', () => {
    beforeEach(() => {
        sent.mockReset();
        downloaded.mockReset();
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-31T12:00:00Z'));
    });
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('says what the list is empty of', () => {
        expect(panel().find('[data-testid="counseling-empty"]').text()).toBe('No counseling records on file.');
        expect(panel({ sees: { dcs: false, lor: true } }).find('[data-testid="counseling-empty"]').text()).toBe('No Letters of Reprimand on file.');
    });

    it('offers New DCS or LOR only to a viewer who may file, and starts a draft of a type they may file', async () => {
        expect(panel().find('[data-testid="counseling-start-open"]').exists()).toBe(false);

        const wrapper = panel({ canFile: { dcs: true, lor: false } });
        await wrapper.find('[data-testid="counseling-start-open"]').trigger('click');
        await flushPromises();

        const dialog = document.querySelector('[data-testid="counseling-start-dialog"]');
        expect(dialog.querySelector('[data-testid="counseling-start-type-dcs"]')).not.toBeNull();
        expect(dialog.querySelector('[data-testid="counseling-start-type-lor"]')).toBeNull();
        expect([...dialog.querySelectorAll('[data-testid="counseling-start-kind"] option')].map((o) => o.textContent)).toEqual(['Choose one', ...KINDS.map((k) => k.label)]);

        // Nothing chosen yet: it says so and sends nothing.
        dialog.querySelector('[data-testid="counseling-start-form"]').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(sent).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="counseling-start-error-kind"]').textContent).toBe('Choose what the record is for.');

        const kind = dialog.querySelector('[data-testid="counseling-start-kind"]');
        kind.value = 'conduct';
        kind.dispatchEvent(new Event('change'));
        await flushPromises();
        dialog.querySelector('[data-testid="counseling-start-form"]').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(sent).toHaveBeenCalledWith('post', '/troopers/7/counseling', { type: 'dcs', kind: 'conduct' }, expect.any(Object));
    });

    it('shows the flags a record started today would earn', async () => {
        const repeat = { key: 'dcs-repeat', message: 'Repeat within 90 days of a DCS.' };
        const wrapper = panel({ canFile: { dcs: true, lor: true }, forecast: { dcs: { conduct: [{ from: '2026-08-01', flags: [repeat] }] } } });
        await wrapper.find('[data-testid="counseling-start-open"]').trigger('click');
        await flushPromises();
        const kind = document.querySelector('[data-testid="counseling-start-kind"]');
        kind.value = 'conduct';
        kind.dispatchEvent(new Event('change'));
        await flushPromises();

        expect(document.querySelector('[data-testid="counseling-hint-dcs-repeat"]').textContent).toBe(repeat.message);
    });

    it('badges each record by where it stands, with the actions its `can` allows', () => {
        const wrapper = panel({
            records: [
                record({ id: 1, type: 'lor', state: 'draft', sharedAt: null, dcsPhase: null, date: null, can: { open: true, continue: true } }),
                record({ id: 2 }),
                record({ id: 3, dcsPhase: 'inactive', expiresOn: '2026-12-14' }),
                record({ id: 4, source: 'import', hasSourceDocument: true, dcsPhase: 'expired', document: { name: 'x.pdf', size: 1, demo: false } }),
                record({ id: 5, type: 'lor', state: 'shared', dcsPhase: null, can: { open: true, respond: true } }),
                record({ id: 6, type: 'lor', state: 'draft', sharedAt: null, dcsPhase: null, withdrawn: { at: '2026-10-30T10:00:00Z', reason: 'Wrong date' }, can: { open: true } }),
            ],
        });
        const rows = wrapper.findAll('[data-testid="counseling-record"]');
        const badge = (row) => row.find('[data-testid="counseling-state"]').text();

        expect(rows.map(badge)).toEqual(['Draft', 'Active', 'Inactive', 'Expired', 'Waiting for the trooper', 'Withdrawn to draft']);

        // A draft is continued, never downloaded.
        expect(rows[0].find('[data-testid="counseling-continue"]').attributes('href')).toBe('/counseling/1');
        expect(rows[0].find('[data-testid="counseling-download"]').exists()).toBe(false);
        expect(rows[0].find('[data-testid="counseling-meta"]').text()).toContain('only you and the chain see it');
        // A shared record is opened and downloaded.
        expect(rows[1].find('[data-testid="counseling-open"]').attributes('href')).toBe('/counseling/2');
        expect(rows[1].find('[data-testid="counseling-download"]').text()).toBe('PDF');
        expect(rows[1].find('[data-testid="counseling-meta"]').text()).toContain('the trooper sees it until 2 Nov 2026');
        expect(rows[2].find('[data-testid="counseling-meta"]').text()).toContain('counts until 13 Dec 2026');
        // An imported record opens too, beside its original PDF.
        expect(rows[3].find('[data-testid="counseling-open"]').exists()).toBe(true);
        expect(rows[3].find('[data-testid="counseling-download"]').text()).toBe('Original PDF');
        expect(rows[3].find('[data-testid="counseling-meta"]').text()).toContain('expired, no longer counts');
        // The trooper's own record waiting for them.
        expect(rows[4].find('[data-testid="counseling-respond"]').attributes('href')).toBe('/counseling/5');
        // No `continue` on it: the viewer may only look.
        expect(rows[5].find('[data-testid="counseling-continue"]').exists()).toBe(false);
    });

    it('downloads a record through the document route, and only says so for a demo file', async () => {
        const wrapper = panel({ records: [record({ id: 2 }), record({ id: 3, hasSourceDocument: true, source: 'upload', document: { name: 'x.pdf', size: 1, demo: true } })] });
        const rows = wrapper.findAll('[data-testid="counseling-record"]');

        await rows[0].find('[data-testid="counseling-download"]').trigger('click');
        expect(downloaded).toHaveBeenCalledWith(2);

        await rows[1].find('[data-testid="counseling-download"]').trigger('click');
        await flushPromises();
        expect(downloaded).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-testid="counseling-demo-dialog"]')).not.toBeNull();
    });

    it('draws the 90-day standing with the active and inactive spans of each counting DCS', () => {
        const wrapper = panel({
            records: [
                record({ id: 2, sharedAt: '2026-10-03T12:00:00Z', inactiveOn: '2026-11-03', expiresOn: '2027-01-02' }),
                record({ id: 3, sharedAt: '2026-09-14T12:00:00Z', dcsPhase: 'inactive', inactiveOn: '2026-10-15', expiresOn: '2026-12-14' }),
                // Drafts and overturned records do not count.
                record({ id: 4, state: 'draft', sharedAt: null }),
                record({ id: 5, type: 'lor', dcsPhase: null, overturned: { date: '2026-10-20', by: 'X', reason: 'y' } }),
            ],
        });
        const standing = wrapper.find('[data-testid="counseling-standing"]');

        expect(standing.findAll('[data-testid="standing-mark"]')).toHaveLength(2);
        expect(standing.findAll('[data-testid="standing-active"]')).toHaveLength(2);
        expect(standing.text()).toContain('Counts toward standing (90 days)');
        expect(standing.find('[role="img"]').attributes('aria-label')).toBe("2 records count toward the trooper's standing in the last 90 days");
    });

    it('shows the flags and the warning the server worked out, and says a departed trooper is read-only', () => {
        const wrapper = panel({
            departed: true,
            warnings: [{ key: 'no-favorable-action', message: 'No favorable action until 30 Nov.' }],
            records: [record({ flags: [{ key: 'dcs-repeat', message: 'Repeat.' }], kindAssumed: true })],
        });

        expect(wrapper.find('[data-testid="counseling-departed"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="counseling-warning-no-favorable-action"]').text()).toBe('No favorable action until 30 Nov.');
        expect(wrapper.find('[data-testid="counseling-flag-dcs-repeat"]').text()).toBe('Repeat.');
        expect(wrapper.find('[data-testid="counseling-kind-assumed"]').exists()).toBe(true);
    });
});
