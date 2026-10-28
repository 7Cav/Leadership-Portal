import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

// One picker for the whole shell, opened from the user menu, so its open state lives at module level.
const pickerOpen = ref(false);

// Acting as a person below you (#432). `canActAs` is shared on every request: true only for a leader with people below
// them (or an Admin acting as one). It only decides whether the menu offers the picker; the server checks every choice.
export function useActAs() {
    const page = usePage();

    const canActAs = computed(() => page.props.canActAs === true);

    function openPicker() {
        pickerOpen.value = true;
    }

    return { canActAs, pickerOpen, openPicker };
}
