import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AttendanceSourceIndicator from '../AttendanceSourceIndicator.vue';
import AttendanceSourceLegend from '../AttendanceSourceLegend.vue';
import { provenanceOf } from '../attendanceSource';

describe('attendance provenance (3.9)', () => {
    it('reads provenance from source, and makeup from status, never anything else', () => {
        expect(provenanceOf({ status: 'present', source: 'manual' })).toBe('manual');
        expect(provenanceOf({ status: 'present', source: 'auto' })).toBe('auto');
        expect(provenanceOf({ status: 'missed', source: 'auto' })).toBe('auto');
        expect(provenanceOf({ status: 'makeup', source: 'manual' })).toBe('makeup');
        expect(provenanceOf({ status: 'loa', source: 'manual' })).toBe('loa');
        expect(provenanceOf({ status: null, source: null })).toBe('unmarked');
    });

    it.each([
        ['present', 'manual', 'manual', 'Manual'],
        ['attached', 'auto', 'auto', 'Auto'],
        ['makeup', 'manual', 'makeup', 'Makeup'],
        [null, null, 'unmarked', 'Not marked'],
    ])('renders %s / %s as the %s indicator', (status, source, kind, label) => {
        const el = mount(AttendanceSourceIndicator, { props: { status, source } }).find('[data-provenance]');

        expect(el.attributes('data-provenance')).toBe(kind);
        expect(el.text()).toBe(label);
        expect(el.attributes('aria-label')).toContain(label);
    });

    it('renders the LOA badge instead of a source dot on an LOA row', () => {
        const el = mount(AttendanceSourceIndicator, { props: { status: 'loa', source: 'manual' } }).find('[data-provenance]');

        expect(el.attributes('data-provenance')).toBe('loa');
        expect(el.classes()).toContain('rounded-sm');
        expect(el.text()).toBe('LOA');
    });

    it('lists auto, manual and makeup in the legend', () => {
        expect(mount(AttendanceSourceLegend).findAll('li').map((li) => li.text())).toEqual(['Auto', 'Manual', 'Makeup']);
    });
});
