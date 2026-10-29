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
            form.post = (url, options) => posted(url, transform({ attended: form.attended, section_ids: [...form.section_ids] }), options);

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

        expect(posted).toHaveBeenCalledWith('/staff-report/7/2026-10-03', { attended: true, section_ids: [4, 9] }, expect.any(Object));
    });

    it('posts "No" with no practices', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="attended-no"]').setValue(true);
        await wrapper.find('form').trigger('submit');

        expect(posted).toHaveBeenCalledWith('/staff-report/7/2026-10-03', { attended: false, section_ids: [] }, expect.any(Object));
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

        expect(wrapper.find('[data-testid="staff-report-none"]').text()).toContain('You were not on a platoon or company HQ when this week ended');
    });
});
