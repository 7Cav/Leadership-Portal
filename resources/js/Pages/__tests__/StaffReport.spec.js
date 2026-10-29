import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffReport from '../StaffReport.vue';

// The staff report page draws what the server sent and posts the picks; it decides nothing about
// who may send.

const posted = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (data) => {
            const form = reactive({ ...data, errors: {}, processing: false });
            let transform = (value) => value;
            form.transform = (fn) => {
                transform = fn;

                return form;
            };
            form.post = (url, options) =>
                posted(
                    url,
                    transform({
                        attended: form.attended,
                        section_ids: [...form.section_ids],
                        practice_type: form.practice_type,
                        ftx_name: form.ftx_name,
                        ftx_date: form.ftx_date,
                        other_events: form.other_events,
                        notes: form.notes,
                    }),
                    options,
                );

            return form;
        },
    };
});

const WEEK = {
    date: '2026-10-03',
    start: '2026-10-03T00:00:00+00:00',
    due: '2026-10-10T23:59:00+00:00',
    overdueUntil: '2026-10-31T00:00:00+00:00',
    previous: '2026-09-26',
    next: null,
    beforeFloor: false,
    ended: true,
    opensAt: '2026-10-10T00:00:00+00:00',
};
const HQ = { id: 3, label: '1/A/1-7', type: 'platoon' };
const SECTIONS = [
    { id: 4, label: '1/1/A/1-7', own: true },
    { id: 5, label: '2/1/A/1-7', own: true },
    { id: 9, label: '1/2/A/1-7', own: false },
];

const page = (props = {}) =>
    mount(StaffReport, {
        props: { trooper: { id: 7, displayName: 'PVT Brandt.L' }, self: true, hq: HQ, week: WEEK, canSend: true, sections: SECTIONS, ...props },
    });

describe('StaffReport page', () => {
    beforeEach(() => posted.mockReset());

    it('asks Yes or No first, and offers the practices only after Yes, own platoon first', async () => {
        const wrapper = page();

        expect(wrapper.find('[data-testid="practice-picker"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="staff-report-send"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="attended-yes"]').setValue(true);

        expect(wrapper.find('[data-testid="sections-own"]').text()).toContain('In 1/A/1-7');
        expect(wrapper.findAll('[data-testid="sections-own"] li').map((li) => li.text())).toEqual(['1/1/A/1-7', '2/1/A/1-7']);
        expect(wrapper.find('[data-testid="sections-others"]').text()).toContain('1/2/A/1-7');
        // Yes with nothing picked cannot be sent.
        expect(wrapper.find('[data-testid="staff-report-send"]').attributes('disabled')).toBeDefined();
    });

    it('posts the picks, several at once', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-yes"]').setValue(true);
        await wrapper.find('[data-testid="section-4"]').trigger('change');
        await wrapper.find('[data-testid="section-9"]').trigger('change');
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith('/staff-report/7/2026-10-03', { attended: true, section_ids: [4, 9], practice_type: 'sp', ftx_name: null, ftx_date: null, other_events: '', notes: '' }, expect.any(Object));
    });

    it('posts "No" with no practices', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-no"]').setValue(true);
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith('/staff-report/7/2026-10-03', { attended: false, section_ids: [], practice_type: null, ftx_name: null, ftx_date: null, other_events: '', notes: '' }, expect.any(Object));
    });

    it('ticks the week\'s entries, says who entered them, and warns when one is left unticked', async () => {
        const wrapper = page({ entries: [{ sectionId: 5, section: '2/1/A/1-7', enteredBy: 'Mercer.J', selfReported: false }] });

        expect(wrapper.find('[data-testid="section-5"]').element.checked).toBe(true);
        expect(wrapper.find('[data-testid="entered-5"]').text()).toBe('Entered by Mercer.J');
        expect(wrapper.find('[data-testid="dropped-note"]').exists()).toBe(false);

        await wrapper.find('[data-testid="section-5"]').trigger('change');

        expect(wrapper.find('[data-testid="dropped-note"]').text()).toContain('2/1/A/1-7');
    });

    it('shows the game server\'s sighting as a hint, never ticked', async () => {
        const wrapper = page({ seen: [9] });
        await wrapper.find('[data-testid="attended-yes"]').setValue(true);

        expect(wrapper.find('[data-testid="seen-9"]').text()).toBe('Seen on the game server');
        expect(wrapper.find('[data-testid="section-9"]').element.checked).toBe(false);
    });

    it('narrows the sections by name', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-yes"]').setValue(true);
        await wrapper.find('[data-testid="section-filter"]').setValue('2/A');

        expect(wrapper.find('[data-testid="sections-own"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sections-others"]').text()).toContain('1/2/A/1-7');
    });

    it('reads a sent report back as sent, staff guest in their own unit and guest elsewhere, with no form', () => {
        const wrapper = page({
            canSend: false,
            report: {
                sentAt: '2026-10-08T20:00:00+00:00',
                attended: true,
                practices: [
                    { sectionId: 4, section: '1/1/A/1-7', own: true },
                    { sectionId: 9, section: '1/2/A/1-7', own: false },
                ],
            },
        });

        expect(wrapper.find('[data-testid="status-badge"]').text()).toBe('Sent');
        expect(wrapper.find('[data-testid="staff-report-answer"]').text()).toBe('Attended 2 section practices.');
        expect(wrapper.findAll('[data-testid="staff-report-practices"] li').map((li) => li.text())).toEqual(['1/1/A/1-7Staff guest', '1/2/A/1-7Guest']);
        expect(wrapper.find('[data-testid="staff-report-form"]').exists()).toBe(false);
    });

    it('reads "No" back as no practice', () => {
        const wrapper = page({ canSend: false, report: { sentAt: '2026-10-08T20:00:00+00:00', attended: false, practices: [] } });

        expect(wrapper.find('[data-testid="staff-report-answer"]').text()).toBe('Did not attend a section practice.');
    });

    it('tells the staff member a running week is sent once it ends', () => {
        const wrapper = page({ canSend: false, week: { ...WEEK, ended: false } });

        expect(wrapper.find('[data-testid="staff-report-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="staff-report-unsent"]').text()).toContain('once the week ends');
    });

    it('tells a reader who is not the staff member that only they send it', () => {
        const wrapper = page({ self: false, canSend: false });

        expect(wrapper.find('[data-testid="staff-report-unsent"]').text()).toContain('Only PVT Brandt.L can send their staff report.');
    });

    it('says when there is no report for the week', () => {
        const wrapper = page({ hq: null, canSend: false });

        expect(wrapper.find('[data-testid="staff-report-none"]').text()).toContain('You were not on a platoon, company or battalion HQ when this week ended');
    });

    it('asks the type after Yes, SP to start with, and shows the FTX name and date only for an FTX', async () => {
        const wrapper = page();
        expect(wrapper.find('[data-testid="practice-type"]').exists()).toBe(false);

        await wrapper.find('[data-testid="attended-yes"]').setValue(true);
        expect(wrapper.find('[data-testid="type-sp"]').element.checked).toBe(true);
        expect(wrapper.find('[data-testid="ftx-fields"]').exists()).toBe(false);

        await wrapper.find('[data-testid="type-ftx"]').setValue('ftx');
        expect(wrapper.find('[data-testid="ftx-fields"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="ftx-date"]').attributes('min')).toBe('2026-10-03');
        expect(wrapper.find('[data-testid="ftx-date"]').attributes('max')).toBe('2026-10-09');
        expect(wrapper.find('[data-testid="practice-picker"] legend').text()).toBe('Which sections took part? (optional)');

        await wrapper.find('[data-testid="type-sp"]').setValue('sp');
        expect(wrapper.find('[data-testid="ftx-fields"]').exists()).toBe(false);
    });

    it('sends an FTX with its name and a date in the week, without sections, and holds it back otherwise', async () => {
        const wrapper = page();
        const send = () => wrapper.find('[data-testid="staff-report-send"]');
        await wrapper.find('[data-testid="attended-yes"]').setValue(true);
        await wrapper.find('[data-testid="type-ftx"]').setValue('ftx');
        expect(send().attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="ftx-name"]').setValue('Operation Anvil');
        await wrapper.find('[data-testid="ftx-date"]').setValue('2026-10-10');
        // The Saturday after is outside the week.
        expect(send().attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="ftx-date"]').setValue('2026-10-05');
        await wrapper.find('[data-testid="other-events"]').setValue('Range day');
        await wrapper.find('[data-testid="notes"]').setValue('Ran OPFOR.');
        expect(send().attributes('disabled')).toBeUndefined();
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith(
            '/staff-report/7/2026-10-03',
            { attended: true, section_ids: [], practice_type: 'ftx', ftx_name: 'Operation Anvil', ftx_date: '2026-10-05', other_events: 'Range day', notes: 'Ran OPFOR.' },
            expect.any(Object),
        );
    });

    it('reads a sent FTX back with its other events, notes and the sheet marker', () => {
        const report = {
            sentAt: '2026-10-10T12:00:00+00:00',
            attended: true,
            practiceType: 'ftx',
            ftx: { name: 'Operation Anvil', date: '2026-10-05' },
            otherEvents: 'Range day',
            notes: 'Ran OPFOR.',
            sheetPushedAt: null,
            practices: [],
        };
        const wrapper = page({ canSend: false, report, sheetConfigured: true });

        expect(wrapper.find('[data-testid="staff-report-answer"]').text()).toBe('Attended an FTX, Operation Anvil on 05Oct26.');
        expect(wrapper.find('[data-testid="staff-report-other-events"]').text()).toBe('Range day');
        expect(wrapper.find('[data-testid="staff-report-notes"]').text()).toBe('Ran OPFOR.');
        expect(wrapper.find('[data-testid="staff-report-sheet"]').text()).toBe('Not sent to the sheet yet');
        expect(page({ canSend: false, report: { ...report, sheetPushedAt: '2026-10-10T12:01:00+00:00' }, sheetConfigured: true }).find('[data-testid="staff-report-sheet"]').text()).toBe('Sent to the sheet');
        expect(page({ canSend: false, report }).find('[data-testid="staff-report-sheet"]').exists()).toBe(false);
    });
});
