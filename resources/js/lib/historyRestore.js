// Whether the page mounting now was rebuilt from the browser's history rather than answered by the server.
//
// Inertia keeps each history entry's page, props included, and browser Back or Forward shows that copy again without
// asking the server: the rollcall steps are history entries of one page, so going back a step brings back the marks as
// they were when that step was first opened, not as they are saved now. A screen that shows live data asks this once
// as it mounts, and reloads its props when the answer is yes.
//
// Set by `popstate` (Back or Forward inside the app) and by a page load the browser itself calls a history traversal
// (Back into the app from another site, which Inertia also answers from history). Cleared by any visit that goes to the
// server (`inertia:start`): a page reached by a link after an earlier Back is fresh.
let restored = typeof performance !== 'undefined' && performance.getEntriesByType?.('navigation')?.[0]?.type === 'back_forward';

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => (restored = true));
    document.addEventListener('inertia:start', () => (restored = false));
}

// Answers once: the next page to ask hears no until history is walked again.
export function takeHistoryRestore() {
    const was = restored;
    restored = false;

    return was;
}
