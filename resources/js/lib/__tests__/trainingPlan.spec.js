import { describe, expect, it } from 'vitest';
import { lockedNote, planHistory, planNextStep, planStatus, subjectsOf } from '../trainingPlan';

describe('training plan wording', () => {
    it('labels each status, and treats an unknown one as no plan', () => {
        expect(planStatus('pending')).toEqual({ label: 'Waiting for approval', variant: 'warning' });
        expect(planStatus('approved').label).toBe('Approved');
        expect(planStatus('returned').label).toBe('Sent back');
        expect(planStatus('bogus').label).toBe('Not written');
    });

    it('says who wrote the plan and who reviewed it last', () => {
        const plan = { status: 'approved', writtenBy: 'SFC Hale.J', writtenAt: '2026-11-11T12:00:00+00:00', reviewedBy: 'CPT Reyes.M', reviewedAt: '2026-11-12T09:30:00+00:00' };

        expect(planHistory(plan)).toBe('Written by SFC Hale.J Wed 11Nov26 12:00Z. Approved by CPT Reyes.M Thu 12Nov26 09:30Z.');
        expect(planHistory({ ...plan, status: 'returned' })).toContain('Sent back by CPT Reyes.M');
        expect(planHistory({ ...plan, status: 'pending' })).toBe('Written by SFC Hale.J Wed 11Nov26 12:00Z.');
        expect(planHistory(null)).toBeNull();
    });

    it('tells the writer what happens next, locked or not', () => {
        expect(planNextStep(null, false)).toContain('which each section can change');
        expect(planNextStep(null, true)).toContain('locked on every section report');
        expect(planNextStep({ status: 'approved' }, false)).toContain('sends it for approval again');
        expect(planNextStep({ status: 'returned' }, false)).toContain('sent this plan back');
        expect(planNextStep({ status: 'pending' }, false)).toContain('Waiting for your company');
    });

    it('explains a locked week', () => {
        expect(lockedNote('sent')).toContain('report is sent');
        expect(lockedNote('floor')).toBe('Read-only, this week is too far back to change.');
        expect(lockedNote(null)).toBeNull();
    });

    it('keeps only the weeks with a subject, trimmed, in week order', () => {
        const weeks = [{ start: '2026-11-07' }, { start: '2026-11-14' }, { start: '2026-11-21' }];

        expect(subjectsOf(weeks, { '2026-11-21': ' Radio ', '2026-11-07': 'Room clearing', '2026-11-14': '  ' })).toEqual({
            '2026-11-07': 'Room clearing',
            '2026-11-21': 'Radio',
        });
    });
});
