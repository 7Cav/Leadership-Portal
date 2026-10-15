import { ref } from 'vue';

// One trooper search for the whole shell: the header button and the `/` key open the same dialog, so its open state
// lives at module level rather than in either place.
const searchOpen = ref(false);

/** The server refuses shorter searches, so the dialog does not send them. */
export const MIN_CHARS = 2;

/** How long typing must pause before a search goes out. */
export const DEBOUNCE_MS = 250;

export function useTrooperSearch() {
    return { searchOpen, openSearch: () => (searchOpen.value = true) };
}
