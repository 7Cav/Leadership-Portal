import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useSlashClaim } from '@/composables/useSlashShortcut';

// One docs search for every docs page (#413): the search boxes, `/` and Ctrl or Cmd K open the same dialog, so its
// open state lives at module level. A docs page claims `/` from the trooper search while it is mounted, the way the
// roster's filter does.
const searchOpen = ref(false);

export function useDocsSearch() {
    return { searchOpen, openSearch: () => (searchOpen.value = true) };
}

// `/`, and Ctrl K (Cmd K on a Mac), open the docs search while a docs page is mounted.
export function useDocsSearchShortcut() {
    useSlashClaim(() => (searchOpen.value = true));
    const onKeydown = (event) => {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            searchOpen.value = true;
        }
    };
    onMounted(() => document.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
}
