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

/**
 * The steps with the items ticked by a click in this tab (`clicked`, `step id:item index`) marked done, and each step
 * done once all its items are.
 *
 * @param {Array<{id: string, done: boolean, checklist?: Array<{done: boolean, click?: string}>}>} steps
 * @param {string[]} clicked
 */
export function withClicks(steps, clicked) {
    if (!clicked?.length) return steps;
    const keys = new Set(clicked);

    return steps.map((step) => {
        const checklist = (step.checklist ?? []).map((item, i) => (item.click && keys.has(`${step.id}:${i}`) ? { ...item, done: true } : item));

        return { ...step, checklist, done: checklist.length > 0 && checklist.every((item) => item.done) };
    });
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

/**
 * The CSS that shows what a step points at (`selector`) in the tutorial blue: an outline on anything, such as a trooper
 * card, a text box or a sidebar link; a solid fill on a button or a link drawn as one (Open milpac); and a soft tint
 * inside a tab or a roll call step, where a fill would swallow the tab strip. A stylesheet rather than a class on the
 * element, so the page drawing that element again (a mark changing a card) never drops it.
 */
export function targetCss(selector) {
    if (!selector) return '';
    const tab = '[role="tab"], [data-step]';

    return [
        `:is(${selector}) { outline: 2px solid var(--changelog) !important; outline-offset: 1px; }`,
        `:is(button, [data-slot="button"]):is(${selector}):not(${tab}) { background-color: var(--changelog) !important; border-color: var(--changelog) !important; color: #fff !important; }`,
        `:is(${tab}):is(${selector}) { background-color: color-mix(in oklab, var(--changelog) 14%, transparent) !important; outline-offset: -2px; border-radius: 0.375rem; }`,
    ].join('\n');
}

/** Whether the page at `url` is the step's own page, `href` (queries ignored): `/rollcall/5` is not `/rollcall/50`. */
export function onStepPage(url, href) {
    if (!href) return false;
    const path = (value) => String(value ?? '').split(/[?#]/)[0].replace(/\/+$/, '');
    const here = path(url);
    const page = path(href);

    return here === page || here.startsWith(`${page}/`);
}

/**
 * Which bottom corner the coach card sits in: `left` when, in the bottom right, it would cover one of `obstacles` (what
 * the step points at, an open side panel) and in the bottom left it would not, else `right`. Rectangles are in viewport
 * pixels; `card` gives the card's width and its top and bottom.
 *
 * @param {{width: number, top: number, bottom: number}} card
 * @param {number} viewportWidth
 * @param {Array<{left: number, right: number, top: number, bottom: number, width: number, height: number}>} obstacles
 */
export function coachSide(card, viewportWidth, obstacles, margin = 16) {
    const at = (left) => ({ left, right: left + card.width, top: card.top, bottom: card.bottom });
    const covers = (box) =>
        obstacles.some((o) => o.width > 0 && o.height > 0 && o.left < box.right && o.right > box.left && o.top < box.bottom && o.bottom > box.top);

    return covers(at(viewportWidth - margin - card.width)) && !covers(at(margin)) ? 'left' : 'right';
}
