<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhClockCountdown, PhWarning } from '@phosphor-icons/vue';
import { forumDateTime } from '@/lib/forumDate';
import { reportDeadline } from '@/lib/reportDeadline';

// A slim line over the rollcall and report screens once the week's report is due within 24 hours,
// or overdue, and not sent. It links to the report unless `href` is null (on the report itself) or
// the screen is read-only (a view-as preview), where it only informs.
const props = defineProps({
    // When the report falls due, ISO-8601 UTC (`WeekOf::reportDue`).
    due: { type: String, required: true },
    sent: { type: Boolean, default: false },
    href: { type: String, default: null },
    readOnly: { type: Boolean, default: false },
});

// Thirty seconds is plenty for an hours display; it also moves the line from "due" to "overdue".
const now = ref(Date.now());
let clock = null;
onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30_000);
});
onBeforeUnmount(() => clearInterval(clock));

const deadline = computed(() => reportDeadline(props.due, now.value, props.sent));
const dueLabel = computed(() => `${forumDateTime(props.due)}Z`);
</script>

<template>
    <div
        v-if="deadline"
        class="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-lg border px-3.5 py-2 text-[13px]"
        :class="deadline.overdue ? 'border-destructive/35 bg-destructive/7' : 'border-warning/35 bg-warning/8'"
        data-testid="report-deadline"
        :data-state="deadline.overdue ? 'overdue' : 'due'"
    >
        <component :is="deadline.overdue ? PhWarning : PhClockCountdown" class="size-4 shrink-0" :class="deadline.overdue ? 'text-destructive' : 'text-warning'" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            <span class="font-semibold">{{ deadline.text }}</span>
            <span class="text-muted-foreground"> (due {{ dueLabel }})</span>
        </p>
        <Link v-if="href && !readOnly" :href="href" class="font-medium underline underline-offset-2 hover:text-foreground" data-testid="report-deadline-link">Open the report</Link>
    </div>
</template>
