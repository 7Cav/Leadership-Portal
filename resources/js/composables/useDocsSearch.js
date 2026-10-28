import { onBeforeUnmount, onMounted, ref } from 'vue';

// One docs search for every docs page (#413): the search boxes and Ctrl or Cmd K open the same dialog, so its open
// state lives at module level. `/` stays the trooper search's.
const searchOpen = ref(false);

export function useDocsSearch() {
    return { searchOpen, openSearch: () => (searchOpen.value = true) };
}

// Ctrl K (Cmd K on a Mac) opens the docs search while a docs page is mounted.
export function useDocsSearchShortcut() {
    const onKeydown = (event) => {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            searchOpen.value = true;
        }
    };
    onMounted(() => document.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
}
