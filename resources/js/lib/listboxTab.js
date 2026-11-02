// Tab in a picker's search box picks the top result, as Enter does, and lets focus move on. "Top" is the row the
// keyboard has highlighted, or the first row still shown when none is. With no rows left Tab only moves focus.
// Shift+Tab is left alone. The event is not cancelled, so the browser still moves focus as usual.

const OPTION = '[role="option"]:not([data-disabled])';

function listboxRoot(from) {
    for (let el = from; el && el !== document.body; el = el.parentElement) {
        if (el.matches?.('[data-slot="command"]') || el.querySelector?.(OPTION)) return el;
    }
    return null;
}

/** The row Tab would pick from this search box, or null. */
export function topOption(input) {
    const root = listboxRoot(input);
    if (!root) return null;
    return root.querySelector(`${OPTION}[data-highlighted]`) ?? root.querySelector(OPTION);
}

/** Whether this keydown is a plain Tab (no Shift, not mid-composition). */
export function isPlainTab(event) {
    return event.key === 'Tab' && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey && !event.isComposing;
}

/** On a plain Tab, selects the top row. Returns true when a row was picked. */
export function selectTopOnTab(event) {
    if (!isPlainTab(event)) return false;
    const option = topOption(event.target);
    if (!option) return false;
    option.click();
    return true;
}

const TABBABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Moves focus to the next focusable element after `el` in the page, as Tab from `el` would. */
export function focusNextAfter(el) {
    if (!el) return;
    const all = [...document.querySelectorAll(TABBABLE)].filter((node) => node === el || (!node.closest('[hidden], [inert]') && node.tabIndex >= 0));
    const next = all[all.indexOf(el) + 1];
    (next ?? el).focus();
}
