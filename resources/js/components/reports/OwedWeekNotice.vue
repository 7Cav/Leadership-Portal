<script setup>
import { Link } from '@inertiajs/vue3';
import { PhWarning } from '@phosphor-icons/vue';
import { weekLabel } from '@/lib/forumDate';

// "The report for 31OCT26 to 06NOV26 is not sent." with a link to it: a report page opens on the running week, so a
// report still owed for the week before is pointed at first. The default slot adds a muted sentence after it.
defineProps({
    // `YYYY-MM-DD` of the week whose report is owed.
    owed: { type: String, required: true },
    // Where its report is sent from.
    href: { type: String, required: true },
    // The first sentence, when it is not the one above (the overview counts the staff who owe it).
    title: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2 text-[13px]" data-testid="owed-week">
        <PhWarning class="size-4 shrink-0 text-warning" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            <span class="font-semibold">{{ title ?? `The report for ${weekLabel(owed)} is not sent.` }}</span>
            <span v-if="$slots.default" class="text-muted-foreground">{{ ' ' }}<slot /></span>
        </p>
        <Link :href="href" class="font-medium underline underline-offset-2 hover:text-foreground" data-testid="owed-week-link">
            Open {{ weekLabel(owed) }}
        </Link>
    </div>
</template>
