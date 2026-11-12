<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Card } from '@/components/ui/card';
import { forumDateTime } from '@/lib/forumDate';
import { countsOf } from '@/lib/rollcall';

// The roll call at the top of a section's report (#703, mockup 703-5-platoon-review): when it trained and on what, the
// forum thread it was posted in, and how the week signed and attended, so Platoon Staff read the week in one place.
// Read-only; the review itself works as before.
const props = defineProps({
    // `{ trainingStart, threadUrl, href, troopers }`: the week's practice start (ISO, null with none), the forum thread, the
    // roll call, and its troopers' marks.
    rollcall: { type: Object, required: true },
    // The report's training subject, and whether the platoon enforced it.
    subject: { type: String, default: null },
    enforced: { type: Boolean, default: false },
    // The section's troopers with their marks: `{ id, rollcall, status }`.
    troopers: { type: Array, default: () => [] },
    // `[{ trooper_id, reason }]` with each trooper's name and the reason's label.
    unaccounted: { type: Array, default: () => [] },
    names: { type: Object, default: () => ({}) },
    reasonLabels: { type: Object, default: () => ({}) },
});

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const training = computed(() =>
    props.rollcall.trainingStart ? `${WEEKDAYS[new Date(props.rollcall.trainingStart).getUTCDay()]} ${forumDateTime(props.rollcall.trainingStart)}Z` : 'No practice this week',
);
const counts = computed(() => countsOf(props.troopers));
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
const signatures = computed(() =>
    [
        counts.value.willAttend && `${counts.value.willAttend} will attend`,
        counts.value.cannotAttend && `${counts.value.cannotAttend} will not attend`,
        counts.value.makeupRequested && `${counts.value.makeupRequested} make-up requested`,
        counts.value.unsigned && `${counts.value.unsigned} unsigned`,
    ]
        .filter(Boolean)
        .join(' · ') || 'None yet',
);
const practice = computed(
    () => [`${counts.value.present} present`, counts.value.missed && `${counts.value.missed} missed`, counts.value.makeup && plural(counts.value.makeup, 'make-up', 'make-ups')].filter(Boolean).join(' · '),
);
</script>

<template>
    <Card class="gap-0 p-4 text-[13px] sm:p-5" data-testid="rollcall-summary">
        <div class="mb-2 flex items-baseline justify-between gap-3">
            <h2 class="text-[15px] font-semibold">Roll call</h2>
            <Link :href="rollcall.href" class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">Open the roll call</Link>
        </div>
        <dl class="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-4 gap-y-1.5">
            <dt class="text-muted-foreground">Training</dt>
            <dd data-testid="summary-training">{{ training }}</dd>
            <dt class="text-muted-foreground">Topic</dt>
            <dd data-testid="summary-topic">
                <template v-if="subject">{{ subject }}<span v-if="enforced" class="text-muted-foreground"> (platoon topic, enforced)</span></template>
                <span v-else class="text-muted-foreground">Not set</span>
            </dd>
            <dt class="text-muted-foreground">Forum post</dt>
            <dd data-testid="summary-thread">
                <a v-if="rollcall.threadUrl" :href="rollcall.threadUrl" target="_blank" rel="noopener noreferrer" class="text-primary underline underline-offset-2">Roll call thread</a>
                <span v-else class="text-muted-foreground">No link given</span>
            </dd>
            <dt class="text-muted-foreground">Signatures</dt>
            <dd class="tabular-nums" data-testid="summary-signatures">{{ signatures }}</dd>
            <dt class="text-muted-foreground">Practice</dt>
            <dd class="tabular-nums" data-testid="summary-practice">{{ practice }}</dd>
            <template v-if="unaccounted.length">
                <dt class="text-muted-foreground">Unaccounted</dt>
                <dd data-testid="summary-unaccounted">
                    <p v-for="row in unaccounted" :key="row.trooper_id">{{ names[row.trooper_id] ?? `#${row.trooper_id}` }}: {{ reasonLabels[row.reason] ?? row.reason }}</p>
                </dd>
            </template>
        </dl>
    </Card>
</template>
