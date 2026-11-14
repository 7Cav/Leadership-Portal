import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';

// The sidebar's bookmarks, shared with every page as `bookmarks` (App\Bookmarks\SidebarBookmarks): the person's own
// list, the slot limit, and the page on screen when it can be bookmarked. Null during view as, act as and the tutorial,
// which hides the section and the button. Each write reloads the page's props, so the list follows the server.
export function useBookmarks() {
    const page = usePage();
    const bookmarks = computed(() => page.props.bookmarks ?? null);
    const items = computed(() => bookmarks.value?.items ?? []);
    const limit = computed(() => bookmarks.value?.limit ?? 10);
    const current = computed(() => bookmarks.value?.current ?? null);
    const full = computed(() => items.value.length >= limit.value);

    const options = (failed) => ({
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => toast.error(errors.bookmark ?? Object.values(errors)[0] ?? `${failed}. Try again.`),
        onHttpException: onHttpException(failed, (message) => toast.error(message)),
    });

    function add(target) {
        router.post('/bookmarks', { route: target.route, params: target.params ?? {} }, options('The page was not bookmarked'));
    }

    function remove(id) {
        router.delete(`/bookmarks/${id}`, options('The bookmark was not removed'));
    }

    function move(id, direction) {
        router.patch(`/bookmarks/${id}`, { direction }, options('The bookmark was not moved'));
    }

    return { bookmarks, items, limit, current, full, add, remove, move };
}
