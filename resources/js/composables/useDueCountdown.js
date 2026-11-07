import { computed, toValue } from 'vue';
import { dueCountdown } from '@/lib/reportDeadline';
import { useNow } from '@/composables/useNow';

// The `(in 6h)` note for a Due line, on the shared once-a-minute clock (which stops when the page
// unmounts). `sent` hides it; `showOverdue: false` drops the overdue note where the page already
// says so, e.g. under a deadline banner.
export function useDueCountdown(due, { sent = false, showOverdue = true } = {}) {
    const now = useNow();

    return computed(() => {
        if (toValue(sent)) return null;
        const note = dueCountdown(toValue(due), now.value);

        return note === 'overdue' && !toValue(showOverdue) ? null : note;
    });
}
