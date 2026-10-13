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
const trooper = (id, displayName, rankShort, statuses, billet = 'Trooper', role = null) => ({ id, displayName, rankShort, billet, role, statuses });
const troopers = [
    trooper(10, 'PFC Svensson.A', 'PFC', { 1: 'qualified', 2: 'required', 3: 'none' }),
    trooper(11, 'SGT Jay.JH', 'SGT', { 1: 'qualified', 2: 'in_progress', 3: 'none' }, 'Section Leader', 'SL'),
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

    it('has a column for every course, with the full name reachable without a mouse', () => {
        const columns = page().findAll('[data-testid="course-column"]');

        expect(columns.map((c) => c.text())).toEqual(['BCT (Basic Combat Training)', 'NCOA-1 (NCOA Phase I)', 'AIR (Air Assault)']);
        expect(columns[1].get('.sr-only').text()).toBe('(NCOA Phase I)');
        expect(columns[1].get('[tabindex="0"]').exists()).toBe(true);
    });

    it('keeps the course header and the trooper column sticky inside a focusable, labelled scroll region', () => {
        const wrapper = page();
        const region = wrapper.get('[role="region"]');

        expect(region.attributes('aria-labelledby')).toBe('matrix-title');
        expect(region.attributes('tabindex')).toBe('0');
        expect(region.attributes('style')).toContain('max-height');
        expect(wrapper.get('[data-testid="course-column"]').classes()).toContain('sticky');
        expect(wrapper.get('[data-testid="course-column"]').classes()).toContain('top-0');
        expect(wrapper.get('[data-testid="trooper-row"] th').classes()).toContain('border-r');
    });

    it('lists the leader first, then the assistant leader, then everyone by rank, as the mockup does', () => {
        const wrapper = page({
            troopers: [
                trooper(1, 'PFC Svensson.A', 'PFC', { 1: 'none', 2: 'none', 3: 'none' }),
                trooper(2, 'SPC Milkovic.D', 'SPC', { 1: 'none', 2: 'none', 3: 'none' }, 'Assistant Section Leader', 'ASL'),
                trooper(3, 'CPL Flanagan.L', 'CPL', { 1: 'none', 2: 'none', 3: 'none' }),
                trooper(4, 'SGT Jay.JH', 'SGT', { 1: 'none', 2: 'none', 3: 'none' }, 'Section Leader', 'SL'),
            ],
        });

        expect(wrapper.findAll('[data-testid="trooper-link"]').map((a) => a.text())).toEqual(['SGT Jay.JH', 'SPC Milkovic.D', 'CPL Flanagan.L', 'PFC Svensson.A']);
    });

    it('takes the leader from the server\'s role, not from the words of a billet', () => {
        const wrapper = page({
            troopers: [
                trooper(1, 'SGT Train.Er', 'SGT', { 1: 'none', 2: 'none', 3: 'none' }, 'Training Leader'),
                trooper(2, 'PFC Real.Lead', 'PFC', { 1: 'none', 2: 'none', 3: 'none' }, 'Rifleman', 'SL'),
            ],
        });

        expect(wrapper.findAll('[data-testid="trooper-link"]').map((a) => a.text())).toEqual(['PFC Real.Lead', 'SGT Train.Er']);
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
        expect(wrapper.get('[data-testid="matrix-empty"]').text()).toContain('No active troopers in this unit');
        expect(wrapper.get('[data-testid="matrix-empty"] a').attributes('href')).toBe('/roster/4');
        expect(wrapper.find('[data-testid="coverage-row"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('NaN');
    });

    it('with no courses in the catalog says so, rather than draw a lone Trooper column', () => {
        const wrapper = page({ courses: [], coverage: [] });

        expect(wrapper.get('[data-testid="matrix-no-courses"]').text()).toContain('No courses in the catalog yet');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('hides the not-held dash in the legend from assistive tech, and does not read each mark twice', () => {
        const legend = page().get('[data-testid="legend"]');

        expect(legend.findAll('.sr-only')).toHaveLength(0);
        expect(legend.findAll('[data-status]')).toHaveLength(4);
    });

    it('links back to the roster for the unit', () => {
        expect(page().get('[data-testid="back-to-roster"]').attributes('href')).toBe('/roster/4');
    });

    it('offers nothing that changes a trooper', () => {
        expect(page().findAll('button, input, select, textarea, form')).toHaveLength(0);
    });
});
