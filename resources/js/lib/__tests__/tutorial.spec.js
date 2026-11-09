import { describe, expect, it } from 'vitest';
import { currentIndex, dotState, finishedGuides, stepLabel } from '../tutorial';

const steps = [
    { id: 'rollcall', title: 'Run roll call', done: true, guide: 'run-roll-call' },
    { id: 'weekly-report', title: 'Send the weekly report', done: false, guide: 'send-the-weekly-report' },
    { id: 'roster', title: 'Read the roster', done: false, guide: 'read-your-roster' },
];

describe('tutorial steps', () => {
    it('shows the first step not done unless the reader picked one', () => {
        expect(currentIndex(steps, null)).toBe(1);
        expect(currentIndex(steps, 'roster')).toBe(2);
        expect(currentIndex(steps, 'gone')).toBe(1);
        expect(currentIndex(steps.map((s) => ({ ...s, done: true })), null)).toBe(2);
        expect(currentIndex([], null)).toBe(-1);
    });

    it('labels the dots and the step', () => {
        expect(steps.map((step, i) => dotState(step, i, 1))).toEqual(['done', 'now', 'open']);
        expect(stepLabel(steps, 1)).toBe('Step 2 of 3: Send the weekly report');
        expect(stepLabel(steps, -1)).toBe('');
    });

    it('ticks only the guide steps of finished tutorial steps', () => {
        expect(finishedGuides(steps)).toEqual(['run-roll-call']);
        expect(finishedGuides([{ done: true, guide: null }])).toEqual([]);
    });
});
