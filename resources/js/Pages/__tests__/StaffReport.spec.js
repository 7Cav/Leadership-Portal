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
                        office_hour_date: form.office_hour_date,
                        office_hour_note: form.office_hour_note,
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
    { id: 4, label: '1/1/A/1-7', own: true, group: '1/A/1-7' },
    { id: 5, label: '2/1/A/1-7', own: true, group: '1/A/1-7' },
    { id: 9, label: '1/2/A/1-7', own: false, group: 'A/1-7' },
    { id: 11, label: '1/1/B/1-7', own: false, group: null },
];

const HEALTH = {
    unit: { id: 3, label: '1/A/1-7' },
    week: '2026-10-03',
    sp: 0.75,
    rc: 0.9,
    headcount: 14,
    inRollcall: 12,
    atRisk: 1,
    staff: { met: 1, total: 2 },
    trend: [{ week: '2026-10-03', sp: 0.75, rc: 0.9, headcount: 14, inRollcall: 12, atRisk: 1 }],
};

const page = (props = {}) =>
    mount(StaffReport, {
        props: { trooper: { id: 7, displayName: 'PVT Brandt.L' }, self: true, hq: HQ, week: WEEK, canSend: true, sections: SECTIONS, ...props },
    });

const NONE = { ftx_name: null, ftx_date: null, office_hour_date: null, office_hour_note: null, other_events: '', notes: '' };
const sendButton = (wrapper) => wrapper.find('[data-testid="staff-report-send"]');
async function toPicker(props = {}) {
    const wrapper = page(props);
    await wrapper.find('[data-testid="attended-yes"]').trigger('click');
    await wrapper.find('[data-testid="type-sp"]').trigger('click');

    return wrapper;
}

describe('StaffReport page', () => {
    beforeEach(() => posted.mockReset());

    it('asks Yes or No first, as big choices, with Send beside it saying what is missing', async () => {
        const wrapper = page();

        expect(wrapper.find('[data-testid="step-attended"]').attributes('aria-current')).toBe('step');
        expect(wrapper.find('[data-testid="practice-type"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="step-next"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="step-details"]').attributes('disabled')).toBeDefined();
        expect(sendButton(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="send-missing"]').text()).toBe('Answer Yes or No.');
        expect(wrapper.find('[data-testid="send-summary"]').text()).toBe('Not answered yet');
    });

    it('walks Yes, then what it was, then the practices, one question at a time', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-yes"]').trigger('click');

        expect(wrapper.find('[data-testid="step-kind"]').attributes('aria-current')).toBe('step');
        expect(wrapper.find('[data-testid="type-sp"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.find('[data-testid="step-panel-attended"]').exists()).toBe(false);

        await wrapper.find('[data-testid="type-sp"]').trigger('click');
        expect(wrapper.find('[data-testid="practice-picker"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="step-missing"]').text()).toBe('Pick the section practice you attended.');
        expect(wrapper.find('[data-testid="step-next"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="step-back"]').trigger('click');
        await wrapper.find('[data-testid="step-back"]').trigger('click');
        expect(wrapper.find('[data-testid="attended-yes"]').attributes('aria-checked')).toBe('true');
    });

    it('goes from No straight to the optional extras', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-no"]').trigger('click');

        expect(wrapper.find('[data-testid="step-kind"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="step-panel-extras"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="step-next"]').exists()).toBe(false);
        expect(sendButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('groups the sections nearest first: the HQ, then the company, then the rest', async () => {
        const wrapper = await toPicker();

        const groups = wrapper.findAll('[data-testid="practice-picker"] [data-testid^="sections-"]');
        expect(groups.map((group) => group.find('p').text())).toEqual(['In 1/A/1-7', 'In A/1-7', 'Other sections']);
        expect(groups[0].findAll('li').map((li) => li.text())).toEqual(['1/1/A/1-7', '2/1/A/1-7']);
    });

    it('shows each pick as a chip in the summary card that removes it, and posts the picks', async () => {
        const wrapper = await toPicker();
        await wrapper.find('[data-testid="section-4"]').trigger('change');
        await wrapper.find('[data-testid="section-9"]').trigger('change');
        await wrapper.find('[data-testid="section-11"]').trigger('change');

        expect(wrapper.find('[data-testid="send-bar"] [data-testid="picked-chips"]').text()).toContain('1/2/A/1-7');
        expect(wrapper.find('[data-testid="send-summary"]').text()).toBe('Yes: 1/1/A/1-7, 1/2/A/1-7, 1/1/B/1-7');

        await wrapper.find('[data-testid="chip-remove-11"]').trigger('click');
        expect(wrapper.find('[data-testid="section-11"]').element.checked).toBe(false);
        expect(sendButton(wrapper).attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-testid="step-next"]').attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');
        expect(posted).toHaveBeenCalledWith('/staff-report/7/2026-10-03', { attended: true, section_ids: [4, 9], practice_type: 'sp', ...NONE }, expect.any(Object));
    });

    it('posts "No" with no practices', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-no"]').trigger('click');

        expect(wrapper.find('[data-testid="send-summary"]').text()).toBe('No');
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith('/staff-report/7/2026-10-03', { attended: false, section_ids: [], practice_type: null, ...NONE }, expect.any(Object));
    });

    it('opens on the practices when entries are there, says who entered them, and warns when one is left out', async () => {
        const wrapper = page({ entries: [{ sectionId: 5, section: '2/1/A/1-7', enteredBy: 'Mercer.J', selfReported: false }] });

        expect(wrapper.find('[data-testid="step-details"]').attributes('aria-current')).toBe('step');
        expect(wrapper.find('[data-testid="section-5"]').element.checked).toBe(true);
        expect(wrapper.find('[data-testid="entered-5"]').text()).toBe('Entered by Mercer.J');
        expect(wrapper.find('[data-testid="dropped-note"]').exists()).toBe(false);

        await wrapper.find('[data-testid="chip-remove-5"]').trigger('click');

        expect(wrapper.find('[data-testid="dropped-note"]').text()).toContain('2/1/A/1-7');
    });

    it('shows the game server sighting as a hint, never picked', async () => {
        const wrapper = await toPicker({ seen: [9] });

        expect(wrapper.find('[data-testid="seen-9"]').text()).toBe('Seen on the game server');
        expect(wrapper.find('[data-testid="section-9"]').element.checked).toBe(false);
    });

    it('narrows the sections by name, and says when none match or none play the game', async () => {
        const wrapper = await toPicker();
        await wrapper.find('[data-testid="section-filter"]').setValue('2/A');

        expect(wrapper.find('[data-testid="section-4"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="section-9"]').exists()).toBe(true);

        await wrapper.find('[data-testid="section-filter"]').setValue('zzz');
        expect(wrapper.find('[data-testid="sections-empty"]').text()).toBe('No section matches "zzz".');

        const none = await toPicker({ sections: [] });
        expect(none.find('[data-testid="sections-empty"]').text()).toBe("No section plays your HQ's game.");
    });

    it('shows the FTX name and date only for an FTX, its sections optional', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-yes"]').trigger('click');
        await wrapper.find('[data-testid="type-ftx"]').trigger('click');

        expect(wrapper.find('[data-testid="ftx-fields"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="ftx-date"]').attributes('min')).toBe('2026-10-03');
        expect(wrapper.find('[data-testid="ftx-date"]').attributes('max')).toBe('2026-10-09');
        expect(wrapper.find('[data-testid="practice-picker"]').text()).toContain('Sections that took part (optional)');

        await wrapper.find('[data-testid="step-back"]').trigger('click');
        await wrapper.find('[data-testid="type-sp"]').trigger('click');
        expect(wrapper.find('[data-testid="ftx-fields"]').exists()).toBe(false);
    });

    it('sends an FTX with its name and a date in the week, and holds it back otherwise', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-yes"]').trigger('click');
        await wrapper.find('[data-testid="type-ftx"]').trigger('click');
        expect(wrapper.find('[data-testid="send-missing"]').text()).toBe('Give the name of the FTX.');

        await wrapper.find('[data-testid="ftx-name"]').setValue('Operation Anvil');
        await wrapper.find('[data-testid="ftx-date"]').setValue('2026-10-10');
        // The Saturday after is outside the week.
        expect(sendButton(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="send-missing"]').text()).toContain('Pick the FTX date');

        await wrapper.find('[data-testid="ftx-date"]').setValue('2026-10-05');
        await wrapper.find('[data-testid="step-next"]').trigger('click');
        await wrapper.find('[data-testid="other-events"]').setValue('Range day');
        await wrapper.find('[data-testid="notes"]').setValue('Ran OPFOR.');
        expect(wrapper.find('[data-testid="send-extras"]').text()).toBe('Other events, Notes');
        expect(sendButton(wrapper).attributes('disabled')).toBeUndefined();
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith(
            '/staff-report/7/2026-10-03',
            { attended: true, section_ids: [], practice_type: 'ftx', ...NONE, ftx_name: 'Operation Anvil', ftx_date: '2026-10-05', other_events: 'Range day', notes: 'Ran OPFOR.' },
            expect.any(Object),
        );
    });

    it('offers Office hour on a battalion HQ only', async () => {
        const platoon = page();
        await platoon.find('[data-testid="attended-yes"]').trigger('click');
        expect(platoon.find('[data-testid="type-office_hour"]').exists()).toBe(false);

        const battalion = page({ hq: { id: 2, label: '1-7', type: 'battalion' } });
        await battalion.find('[data-testid="attended-yes"]').trigger('click');
        expect(battalion.find('[data-testid="type-office_hour"]').exists()).toBe(true);
    });

    it('sends an office hour with its date and note, without sections', async () => {
        const wrapper = page({ hq: { id: 2, label: '1-7', type: 'battalion' }, entries: [{ sectionId: 4, section: '1/1/A/1-7', enteredBy: 'Stone.A', selfReported: true }] });
        await wrapper.find('[data-testid="step-kind"]').trigger('click');
        await wrapper.find('[data-testid="type-office_hour"]').trigger('click');

        expect(wrapper.find('[data-testid="practice-picker"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="office-hour-fields"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dropped-note"]').text()).toContain('1/1/A/1-7');
        expect(wrapper.find('[data-testid="send-missing"]').text()).toContain('Pick the office hour date');

        await wrapper.find('[data-testid="office-hour-date"]').setValue('2026-10-06');
        expect(wrapper.find('[data-testid="send-missing"]').text()).toBe('Say in a few words what the office hour was.');
        await wrapper.find('[data-testid="office-hour-note"]').setValue('Questions from new NCOs');
        expect(wrapper.find('[data-testid="send-summary"]').text()).toBe('Yes, office hour (06Oct26)');
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith(
            '/staff-report/7/2026-10-03',
            { attended: true, section_ids: [], practice_type: 'office_hour', ...NONE, office_hour_date: '2026-10-06', office_hour_note: 'Questions from new NCOs' },
            expect.any(Object),
        );
    });

    it('never shows the staff member the unit health panel on their own form', () => {
        expect(page({ health: null }).find('[data-testid="staff-hq-health"]').exists()).toBe(false);
    });

    it('shows a reader the HQ unit health beside the report', () => {
        const wrapper = page({
            self: false,
            canSend: false,
            report: { sentAt: '2026-10-08T20:00:00+00:00', attended: false, practices: [] },
            health: HEALTH,
        });

        expect(wrapper.find('[data-testid="staff-hq-health"]').text()).toContain('1/A/1-7 health');
        expect(wrapper.find('[data-testid="health-sp"]').text()).toContain('75%');
    });

    it('reads a sent report back as sent and final, staff guest in their own unit and guest elsewhere, with no form', () => {
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
        expect(wrapper.find('[data-testid="staff-report-sent"]').text()).toContain('A sent staff report is final.');
        expect(wrapper.findAll('[data-testid="staff-report-practices"] li').map((li) => li.text())).toEqual(['1/1/A/1-7Staff guest', '1/2/A/1-7Guest']);
        expect(wrapper.find('[data-testid="staff-report-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="staff-report-discrepancy"]').exists()).toBe(false);
    });

    it('reads "No" and an office hour back', () => {
        expect(page({ canSend: false, report: { sentAt: '2026-10-08T20:00:00+00:00', attended: false, practices: [] } }).find('[data-testid="staff-report-answer"]').text())
            .toBe('Did not attend a section practice.');

        const office = page({ canSend: false, report: { sentAt: '2026-10-08T20:00:00+00:00', attended: true, practiceType: 'office_hour', officeHour: { date: '2026-10-06', note: 'NCO questions' }, practices: [] } });
        expect(office.find('[data-testid="staff-report-answer"]').text()).toBe('Held an office hour on 06Oct26.');
        expect(office.find('[data-testid="staff-report-office-hour"]').text()).toBe('NCO questions');
    });

    it('shows a reader the warning when the game server disagrees', () => {
        const wrapper = page({
            self: false,
            canSend: false,
            report: { sentAt: '2026-10-08T20:00:00+00:00', attended: true, practices: [{ sectionId: 4, section: '1/1/A/1-7', own: true }] },
            discrepancy: { notSeen: ['1/1/A/1-7'], seenElsewhere: [] },
        });

        expect(wrapper.find('[data-testid="staff-report-discrepancy"]').attributes('aria-label')).toContain('Reported at 1/1/A/1-7, but the game server capture of that practice did not see them.');
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
        expect(wrapper.find('[data-testid="staff-report-sheet"]').text()).toBe('Not sent to the sheet yet.');
        expect(page({ canSend: false, sheetConfigured: true, report: { ...report, sheetPushedAt: '2026-10-10T12:01:00+00:00' } }).find('[data-testid="staff-report-sheet"]').text()).toBe('Sent to the sheet.');
        expect(page({ canSend: false, report }).find('[data-testid="staff-report-sheet"]').exists()).toBe(false);
    });

    it('tells the staff member a running week is sent once it ends', () => {
        const wrapper = page({ canSend: false, week: { ...WEEK, ended: false } });

        expect(wrapper.find('[data-testid="staff-report-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="staff-report-unsent"]').text()).toContain('once the week ends');
    });

    it('tells a reader who is not the staff member that only they send it', () => {
        expect(page({ self: false, canSend: false }).find('[data-testid="staff-report-unsent"]').text()).toContain('Only PVT Brandt.L can send their staff report.');
    });

    it('says when there is no report for the week', () => {
        expect(page({ hq: null, canSend: false }).find('[data-testid="staff-report-none"]').text()).toContain('You were not on a platoon, company or battalion HQ when this week ended');
    });
});
