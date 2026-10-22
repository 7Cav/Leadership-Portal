import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// The `/` key, owned in one place. The shell listens once and opens the trooper search; a page with a search of its
// own (the roster's order-of-battle filter) claims the key while it is mounted, and `/` then goes there instead. The
// newest claim wins, and the shell's search takes the key back when the page unmounts.
const claims = ref([]);

/** Whether a page currently holds `/` for its own search, so the shell can stop advertising it. */
export const slashClaimed = computed(() => claims.value.length > 0);

// Typing a `/` into a field, or pressing it while a dialog or sheet holds focus, is never a shortcut.
const EDITABLE = 'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="dialog"], [role="alertdialog"]';

/** True for a plain `/` press that should act as the shortcut. */
export function isSlashShortcut(event) {
    if (event.key !== '/' || event.defaultPrevented || event.isComposing) return false;
    if (event.ctrlKey || event.metaKey || event.altKey) return false;
    if (event.target?.closest?.(EDITABLE)) return false;

    // A modal dialog is open (its content is only in the document while open), even if focus slipped out of it.
    return !document.querySelector('[role="dialog"], [role="alertdialog"]');
}

/** The shell's handler: runs the newest claim, or `fallback` (open the trooper search) when nothing claims `/`. */
export function handleSlash(event, fallback) {
    if (!isSlashShortcut(event)) return;
    event.preventDefault();
    (claims.value.at(-1) ?? fallback)();
}

/** For a page component: route `/` to `action` while this component is mounted. */
export function useSlashClaim(action) {
    onMounted(() => (claims.value = [...claims.value, action]));
    onBeforeUnmount(() => (claims.value = claims.value.filter((claim) => claim !== action)));
}
