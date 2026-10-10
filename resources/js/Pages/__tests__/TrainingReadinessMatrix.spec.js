import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TrainingReadinessMatrix from '../TrainingReadinessMatrix.vue';

// Story 6.9: the matrix draws what the server shaped; it decides nothing about who may look.

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
    Head: { template: '<head></head>' },
}));

const unit = { id: 4, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon' };
const courses = [
    { id: 1, code: 'BCT', name: 'Basic Combat Training' },
    { id: 2, code: 'NCOA-1', name: 'NCOA Phase I' },
    { id: 3, code: 'AIR', name: 'Air Assault' },
];
const trooper = (id, displayName, rankShort, statuses, billet = 'Trooper') => ({ id, displayName, rankShort, billet, statuses });
const troopers = [
    trooper(10, 'PFC Svensson.A', 'PFC', { 1: 'qualified', 2: 'required', 3: 'none' }),
    trooper(11, 'SGT Jay.JH', 'SGT', { 1: 'qualified', 2: 'in_progress', 3: 'none' }, 'Section Leader'),
    trooper(12, 'SPC Crooks.A', 'SPC', { 1: 'qualified', 2: 'none', 3: 'none' }),
];
const coverage = [
    { courseId: 1, qualified: 3, total: 3, percent: 100 },
    { courseId: 2, qualified: 0, total: 3, percent: 0 },
    { courseId: 3, qualified: 0, total: 3, percent: 0 },
];

const page = (props = {}) => mount(TrainingReadinessMatrix, { props: { unit, courses, troopers, coverage, ...props } });

describe('TrainingReadinessMatrix', () => {
    it('titles the page with the unit', () => {
        expect(page().get('[data-testid="matrix-title"]').text()).toBe('Training readiness · 1/A/1-7 1st Platoon');
    });

    it('has a column for every course, with the full name as its title', () => {
        const columns = page().findAll('[data-testid="course-column"]');

        expect(columns.map((c) => c.text())).toEqual(['BCT', 'NCOA-1', 'AIR']);
        expect(columns[1].attributes('title')).toBe('NCOA Phase I');
    });

    it('lists the leader first, then the assistant leader, then everyone by rank, as the mockup does', () => {
        const wrapper = page({
            troopers: [
                trooper(1, 'PFC Svensson.A', 'PFC', { 1: 'none', 2: 'none', 3: 'none' }),
                trooper(2, 'SPC Milkovic.D', 'SPC', { 1: 'none', 2: 'none', 3: 'none' }, 'Asst. Section Leader'),
                trooper(3, 'CPL Flanagan.L', 'CPL', { 1: 'none', 2: 'none', 3: 'none' }),
                trooper(4, 'SGT Jay.JH', 'SGT', { 1: 'none', 2: 'none', 3: 'none' }, 'Section Leader'),
            ],
        });

        expect(wrapper.findAll('[data-testid="trooper-link"]').map((a) => a.text())).toEqual(['SGT Jay.JH', 'SPC Milkovic.D', 'CPL Flanagan.L', 'PFC Svensson.A']);
    });

    it('lists the troopers senior first, then by name', () => {
        const names = page().findAll('[data-testid="trooper-link"]').map((a) => a.text());

        expect(names).toEqual(['SGT Jay.JH', 'SPC Crooks.A', 'PFC Svensson.A']);
    });

    it('links each trooper to their milpac, and shows their billet', () => {
        const row = page().findAll('[data-testid="trooper-row"]')[0];

        expect(row.get('[data-testid="trooper-link"]').attributes('href')).toBe('/troopers/11');
        expect(row.text()).toContain('Section Leader');
    });

    it('says no billet on record rather than leave the line blank', () => {
        const wrapper = page({ troopers: [trooper(10, 'PFC Svensson.A', 'PFC', { 1: 'none', 2: 'none', 3: 'none' }, null)] });

        expect(wrapper.get('[data-testid="trooper-row"]').text()).toContain('No billet on record');
    });

    it('marks each cell by status, in the courses\' order', () => {
        const cells = page().findAll('[data-testid="trooper-row"]')[0].findAll('[data-testid="cell"] [data-status]');

        expect(cells.map((c) => c.attributes('data-status'))).toEqual(['qualified', 'in_progress', 'none']);
    });

    it('gives a screen reader the words, not just the glyph', () => {
        const row = page().findAll('[data-testid="trooper-row"]')[2];

        expect(row.text()).toContain('Qualified');
        expect(row.text()).toContain('Required, not held');
        expect(row.text()).toContain('Not held');
    });

    it('treats a status it does not know as not held, not a broken cell', () => {
        const wrapper = page({ troopers: [trooper(10, 'PFC Svensson.A', 'PFC', { 1: 'banana', 2: 'none', 3: 'none' })] });

        expect(wrapper.findAll('[data-testid="cell"]')[0].get('[data-status]').attributes('data-status')).toBe('none');
    });

    it('shows each course\'s coverage under its column', () => {
        const footer = page().findAll('[data-testid="coverage"]').map((c) => c.text());

        expect(footer).toEqual(['100%', '0%', '0%']);
    });

    it('keeps a course nobody holds as a column with 0%', () => {
        const wrapper = page();

        expect(wrapper.findAll('[data-testid="course-column"]')).toHaveLength(3);
        expect(wrapper.findAll('[data-testid="coverage"]')[2].text()).toBe('0%');
    });

    it('explains every mark in a legend', () => {
        const legend = page().get('[data-testid="legend"]').text();

        expect(legend).toContain('qualified');
        expect(legend).toContain('in progress');
        expect(legend).toContain('required, not held');
        expect(legend).toContain('not held');
    });

    it('says coverage is a plain ratio, not a reported KPI', () => {
        expect(page().get('[data-testid="coverage-note"]').text()).toContain('not a reported KPI');
    });

    it('with no troopers keeps the column headers, says so, and draws no coverage row', () => {
        const wrapper = page({ troopers: [], coverage: courses.map((c) => ({ courseId: c.id, qualified: 0, total: 0, percent: null })) });

        expect(wrapper.findAll('[data-testid="course-column"]')).toHaveLength(3);
        expect(wrapper.get('[data-testid="matrix-empty"]').text()).toBe('No active troopers in this unit.');
        expect(wrapper.find('[data-testid="coverage-row"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('NaN');
    });

    it('links back to the roster for the unit', () => {
        expect(page().get('[data-testid="back-to-roster"]').attributes('href')).toBe('/roster/4');
    });

    it('offers nothing that changes a trooper', () => {
        expect(page().findAll('button, input, select, textarea, form')).toHaveLength(0);
    });
});
