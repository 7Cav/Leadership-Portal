// The hands-on tutorial's step logic (App\Tutorial on the server). Pure, so the bar and the coach card agree and the
// tests can read it without mounting anything.

/**
 * The step to show: the one the reader picked with Next or Back while it still exists, else the first step not done
 * yet, else the last step.
 *
 * @param {Array<{id: string, done: boolean}>} steps
 * @param {string|null} picked
 */
export function currentIndex(steps, picked) {
    if (!steps?.length) return -1;

    const chosen = picked == null ? -1 : steps.findIndex((step) => step.id === picked);
    if (chosen !== -1) return chosen;

    const open = steps.findIndex((step) => !step.done);

    return open === -1 ? steps.length - 1 : open;
}

/** `done`, `now` or `open`, for the bar's dots. */
export function dotState(step, index, current) {
    if (index === current) return 'now';

    return step.done ? 'done' : 'open';
}

/** "Step 3 of 5: Mark the practice". */
export function stepLabel(steps, index) {
    if (index < 0 || !steps?.[index]) return '';

    return `Step ${index + 1} of ${steps.length}: ${steps[index].title}`;
}

/**
 * The getting started guide steps to tick: those of the finished tutorial steps that have one.
 *
 * @param {Array<{done: boolean, guide: string|null}>} steps
 */
export function finishedGuides(steps) {
    return (steps ?? []).filter((step) => step.done && step.guide).map((step) => step.guide);
}
