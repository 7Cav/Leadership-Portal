import { describe, expect, it } from 'vitest';
import { LEGEND_ORDER, SOURCE_KINDS, sourceKind } from '../attendanceSource';

describe('attendanceSource', () => {
    it('reads a manual mark as manual and an auto mark as auto', () => {
        expect(sourceKind('manual', 'present')).toBe('manual');
        expect(sourceKind('auto', 'present')).toBe('auto');
        expect(sourceKind('manual', 'missed')).toBe('manual');
        expect(sourceKind('auto', 'attached')).toBe('auto');
    });

    it('reads a makeup from its status, whoever made it', () => {
        expect(sourceKind('manual', 'makeup')).toBe('makeup');
        expect(sourceKind('auto', 'makeup')).toBe('makeup');
        expect(sourceKind(null, 'makeup')).toBe('makeup');
    });

    it('has no kind for a value that was never stored, or a source it does not know', () => {
        expect(sourceKind(null, null)).toBeNull();
        expect(sourceKind(undefined, undefined)).toBeNull();
        expect(sourceKind(null, 'present')).toBeNull();
        expect(sourceKind('crcon', 'present')).toBeNull();
    });

    it('does not read provenance from a derived leave', () => {
        expect(sourceKind(null, 'loa')).toBeNull();
    });

    it('defines a word, a title and a dot colour for every kind, and lists each once in the legend', () => {
        expect([...LEGEND_ORDER].sort()).toEqual(Object.keys(SOURCE_KINDS).sort());
        for (const kind of LEGEND_ORDER) {
            expect(SOURCE_KINDS[kind].label).toBeTruthy();
            expect(SOURCE_KINDS[kind].title).toBeTruthy();
            expect(SOURCE_KINDS[kind].dot).toMatch(/^bg-/);
        }
        expect(new Set(LEGEND_ORDER.map((k) => SOURCE_KINDS[k].dot)).size).toBe(LEGEND_ORDER.length);
    });
});
