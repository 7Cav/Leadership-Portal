import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// One picker for the whole shell: the top-nav control and the user menu both open the same
// dialog, so its open state lives at module level rather than in either component.
const pickerOpen = ref(false);

// The view-as preview (Story 1.13). The server decides everything: `viewAs` is shared on every
// request only when the stored target still holds against the viewer's real authority (AD-9),
// so the shell never shows a banner for a preview the server is not applying.
export function useViewAs() {
    const page = usePage();

    const viewAs = computed(() => page.props.viewAs ?? null);
    const active = computed(() => viewAs.value !== null);

    // "the leader of 2/1/A/1-7" for a unit, "SGT Vasquez.R" for a person: the subject of
    // "Viewing as ...".
    const subject = computed(() => {
        if (!viewAs.value) return null;

        return viewAs.value.kind === 'unit' ? `the leader of ${viewAs.value.designation}` : viewAs.value.label;
    });

    function openPicker() {
        pickerOpen.value = true;
    }

    function enter(kind, id, options = {}) {
        router.post('/view-as', { kind, id }, { preserveScroll: true, ...options });
    }

    function exit(options = {}) {
        router.delete('/view-as', { preserveScroll: true, ...options });
    }

    return { viewAs, active, subject, pickerOpen, openPicker, enter, exit };
}
