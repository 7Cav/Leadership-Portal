import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffReportRevisions from '../StaffReportRevisions.vue';

const revision = {
    sentAt: '2026-10-10T12:00:00Z',
    sentBy: 'SSG Brandt.L',
    attended: true,
    practiceType: 'sp',
    ftx: null,
    officeHour: null,
    practices: [{ sectionId: 1, section: '1/1/A/1-7' }],
    otherEvents: null,
    notes: 'First.',
    replaced: { at: '2026-10-10T18:00:00Z', by: 'SSG Brandt.L', reason: 'Picked the wrong week' },
};

describe('StaffReportRevisions', () => {
    it('names each earlier version and opens it to what it said and why it was sent again', async () => {
        const wrapper = mount(StaffReportRevisions, { props: { revisions: [revision] } });
        const toggle = wrapper.get('[data-testid="staff-report-revision"] button');

        expect(toggle.text()).toBe('Sent 10OCT26 12\u200A00Z by SSG Brandt.L');
        expect(wrapper.find('[data-testid="staff-report-revision-body"]').exists()).toBe(false);

        await toggle.trigger('click');

        const body = wrapper.get('[data-testid="staff-report-revision-body"]').text();
        expect(body).toContain('10OCT26 18\u200A00Z by SSG Brandt.L: Picked the wrong week');
        expect(body).toContain('Attended 1 section practice.');
        expect(body).toContain('1/1/A/1-7');
        expect(body).toContain('First.');
    });

    it('reads a named type and the presence answer of an earlier version', async () => {
        const wrapper = mount(StaffReportRevisions, {
            props: { revisions: [{ ...revision, practiceType: 'event', ftx: { name: 'Town hall', date: '2026-10-06' }, practices: [], presenceType: 'office_hour', officeHour: { date: '2026-10-07', note: 'Q and A' } }] },
        });
        await wrapper.get('[data-testid="staff-report-revision"] button').trigger('click');

        const body = wrapper.get('[data-testid="staff-report-revision-body"]').text();
        expect(body).toContain('Attended an official event, Town hall on 06OCT26.');
        expect(body).toContain('Office hour (07OCT26)');
    });
});
