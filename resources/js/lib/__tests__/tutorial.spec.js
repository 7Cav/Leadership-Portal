import { describe, expect, it } from 'vitest';
import { coachSide, currentIndex, dotState, finishedGuides, onStepPage, stepLabel, targetCss, withClicks } from '../tutorial';

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

describe('what the coach points at, and where it sits', () => {
    it('outlines the target and fills a button, and writes nothing without one', () => {
        const css = targetCss('[data-trooper="9"], #report-notes');

        expect(css).toContain(':is([data-trooper="9"], #report-notes) { outline: 2px solid var(--changelog) !important;');
        expect(css).toContain(':is(button, [data-slot="button"]):is([data-trooper="9"], #report-notes):not([role="tab"], [data-step]) { background-color: var(--changelog) !important;');
        expect(css).toContain(':is([role="tab"], [data-step]):is([data-trooper="9"], #report-notes) { background-color: color-mix(');
        expect(targetCss(null)).toBe('');
    });

    it('knows the step page by its path, queries and deeper pages included', () => {
        expect(onStepPage('/rollcall/5?week=2026-11-07', '/rollcall/5')).toBe(true);
        expect(onStepPage('/rollcall/50', '/rollcall/5')).toBe(false);
        expect(onStepPage('/staff-report/1/2026-10-31', '/staff-report')).toBe(true);
        expect(onStepPage('/roster?view=orbat&trooper=8', '/roster')).toBe(true);
        expect(onStepPage('/home', '/roster')).toBe(false);
        expect(onStepPage('/home', null)).toBe(false);
    });

    it('moves to the bottom left only when the bottom right covers something and the left does not', () => {
        const card = { width: 352, top: 600, bottom: 1000 };
        const box = (left, top, width, height) => ({ left, top, width, height, right: left + width, bottom: top + height });

        // The report's Send button, bottom right.
        expect(coachSide(card, 1920, [box(1500, 970, 120, 36)])).toBe('left');
        // A trooper's side panel, the full height on the right.
        expect(coachSide(card, 1920, [box(1430, 0, 490, 1080)])).toBe('left');
        // Demo.K's card, in the middle.
        expect(coachSide(card, 1920, [box(460, 580, 920, 115)])).toBe('right');
        // Covered either way: it stays on the right.
        expect(coachSide(card, 1920, [box(0, 900, 1920, 60)])).toBe('right');
        // Something not drawn has no size and covers nothing.
        expect(coachSide(card, 1920, [box(1600, 900, 0, 0)])).toBe('right');
    });

    it('ticks an item by a click in this tab, and its step once every item is done', () => {
        const chase = {
            id: 'missing-report',
            done: false,
            checklist: [
                { label: 'Copy the reminder', done: false, click: '[data-testid="copy-reminder"]' },
                { label: 'Open the roll call', done: true },
            ],
        };

        expect(withClicks([chase], [])).toEqual([chase]);
        const [ticked] = withClicks([chase], ['missing-report:0']);
        expect(ticked.checklist[0].done).toBe(true);
        expect(ticked.done).toBe(true);
        // A key for an item with no click selector ticks nothing.
        expect(withClicks([chase], ['missing-report:1', 'other:0'])[0].done).toBe(false);
        expect(withClicks([{ ...chase, checklist: [chase.checklist[0], { label: 'x', done: false }] }], ['missing-report:0'])[0].done).toBe(false);
    });
});
