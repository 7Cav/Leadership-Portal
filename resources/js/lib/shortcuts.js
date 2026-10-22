import { PRACTICE, SIGNATURES } from './rollcall';

// Every keyboard shortcut in the portal, grouped by page. The `?` list (Layouts/partials/
// KeyboardShortcuts.vue) is built from this, and the handlers that can read their keys from here
// do (the `?` key itself, the rollcall's J/K and number keys), so the list and the keys cannot
// drift apart. A handler that still names its keys in place says so next to its entry: change
// both together.
//
// `keys` are `KeyboardEvent.key` values, any one of which triggers the shortcut. `range` shows a
// run of number keys as "1 to N". `where` names the part of the page it works in, when that is
// not the whole page. `claimed` replaces `where` while a page holds the key for itself (only `/`
// can be claimed, see composables/useSlashShortcut.js). `href` is the nav item the page sits under, so the list can put the page
// the viewer is on first.

export const SHORTCUT_GROUPS = [
    {
        id: 'global',
        title: 'Everywhere',
        href: null,
        shortcuts: [
            { id: 'help', keys: ['?'], label: 'Show this list of keyboard shortcuts' },
            // AppLayout.vue `onKeydown` (composables/useSlashShortcut.js). A page with a search of its own can claim
            // the key while it is mounted; the list then says so on this entry (`claimed`).
            { id: 'search', keys: ['/'], label: 'Find a trooper', claimed: 'This page uses it for its own search' },
            { id: 'close', keys: ['Escape'], label: 'Close a dialog, menu or side panel' },
        ],
    },
    {
        id: 'roster',
        title: 'Roster',
        href: '/roster',
        shortcuts: [
            // OrbatView.vue `useSlashClaim`: `/` goes to the roster's own filter here, not to Find a trooper.
            { id: 'roster.search', keys: ['/'], label: 'Jump to the roster search, in place of Find a trooper', where: 'Order of battle' },
            // `@keydown.esc` on the OrbatView and MatrixView search boxes.
            { id: 'roster.clearSearch', keys: ['Escape'], label: 'Clear the search', where: 'In the search box' },
            // OrbatView.vue `onRowKey`.
            { id: 'roster.rows', keys: ['ArrowDown', 'ArrowUp'], label: 'Next or previous trooper', where: 'Order of battle' },
            // MatrixView.vue `onGridKey`.
            { id: 'roster.cells', keys: ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'], label: 'Move between cells', where: 'Attendance matrix' },
            { id: 'roster.rowEnds', keys: ['Home', 'End'], label: 'Start or end of the row', where: 'Attendance matrix' },
            // Both views: OrbatView `onRowKey`, MatrixView `onGridKey`.
            { id: 'roster.open', keys: ['Enter', ' '], label: 'Open the trooper', where: 'On a trooper' },
        ],
    },
    {
        id: 'rollcall',
        title: 'Rollcall',
        href: '/rollcall',
        // Read by Rollcall.vue `onCardKeydown` and its keyboard card.
        shortcuts: [
            { id: 'rollcall.next', keys: ['j', 'ArrowDown'], label: 'Next trooper', where: 'On a trooper card' },
            { id: 'rollcall.previous', keys: ['k', 'ArrowUp'], label: 'Previous trooper', where: 'On a trooper card' },
            // The number keys follow the order of the buttons on the card.
            {
                id: 'rollcall.signature',
                keys: SIGNATURES.map((_, index) => String(index + 1)),
                range: true,
                label: `Set the signature (${SIGNATURES.map((option) => option.label).join(', ')})`,
                where: 'Signatures step',
            },
            {
                id: 'rollcall.practice',
                keys: PRACTICE.map((_, index) => String(index + 1)),
                range: true,
                label: `Set the practice mark (${PRACTICE.map((option) => option.label).join(', ')})`,
                where: 'Practice step',
            },
        ],
    },
    {
        id: 'reports',
        title: 'Reports',
        href: '/reports',
        shortcuts: [
            // Reports/Rollup.vue `onKeydown`.
            { id: 'reports.tree.rows', keys: ['ArrowDown', 'ArrowUp'], label: 'Next or previous unit', where: 'Unit tree' },
            { id: 'reports.tree.branch', keys: ['ArrowRight', 'ArrowLeft'], label: 'Open or close a unit, or go to its parent', where: 'Unit tree' },
            { id: 'reports.tree.ends', keys: ['Home', 'End'], label: 'First or last unit', where: 'Unit tree' },
            { id: 'reports.tree.select', keys: ['Enter', ' '], label: "Show the unit's report", where: 'Unit tree' },
            // reports/HistoryPanel.vue `onKey`.
            { id: 'reports.history', keys: ['ArrowDown', 'ArrowUp'], label: 'Next or previous sent report', where: 'History tab' },
        ],
    },
    {
        id: 'org-structure',
        title: 'Org structure',
        href: '/settings/org-structure',
        shortcuts: [
            // Settings/OrgTreeNode.vue.
            { id: 'org.select', keys: ['Enter', ' '], label: 'Show the unit', where: 'Chain of command' },
        ],
    },
];

export const SHORTCUTS = Object.fromEntries(SHORTCUT_GROUPS.flatMap((group) => group.shortcuts.map((shortcut) => [shortcut.id, shortcut])));

const KEY_LABELS = { ArrowDown: '↓', ArrowUp: '↑', ArrowLeft: '←', ArrowRight: '→', Escape: 'Esc', ' ': 'Space' };
const KEY_NAMES = { ArrowDown: 'Down arrow', ArrowUp: 'Up arrow', ArrowLeft: 'Left arrow', ArrowRight: 'Right arrow', Escape: 'Escape', ' ': 'Space', '/': 'Slash', '?': 'Question mark' };

// What a key looks like on a keycap ("J", "↓"), and what a screen reader should say for it.
export function keyLabel(key) {
    return KEY_LABELS[key] ?? (key.length === 1 ? key.toUpperCase() : key);
}

export function keyName(key) {
    return KEY_NAMES[key] ?? keyLabel(key);
}

// The keys to show for a shortcut: each key, or the first and last of a number range.
export function displayKeys(shortcut) {
    return shortcut.range ? [shortcut.keys[0], shortcut.keys.at(-1)] : shortcut.keys;
}

// Whether a key press is this shortcut. A press with Ctrl, Alt or Cmd held is the browser's or the
// system's, never ours; Shift is allowed, since `?` needs it on most layouts.
export function isShortcut(event, id) {
    if (event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return false;

    return SHORTCUTS[id].keys.includes(event.key);
}

// Which of a shortcut's keys was pressed, by position (the rollcall's number keys pick a button).
export function shortcutIndex(event, id) {
    return isShortcut(event, id) ? SHORTCUTS[id].keys.indexOf(event.key) : -1;
}

// A page-wide shortcut stays out of the way while the viewer types.
export function isTypingTarget(target) {
    return Boolean(target?.closest?.('input, textarea, select, [contenteditable]:not([contenteditable="false"])'));
}

// An open dialog, sheet, popover or menu owns the keyboard until it closes.
export function overlayOpen(root = document) {
    return Boolean(root.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"]'));
}
