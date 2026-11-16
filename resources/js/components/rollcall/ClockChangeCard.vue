<script setup>
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { PhClockClockwise } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import WarningBanner from '@/components/WarningBanner.vue';
import { forumDate } from '@/lib/forumDate';
import { localLabel, slotLabel } from '@/lib/practiceSlot';
import { postJson } from '@/lib/postJson';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';

// A clock change where the platoon leader lives, in the week before it or the week of it (16NOV26). The slot is kept in
// Zulu, so the practice moves an hour on everyone's own clock unless the slot moves. The platoon leader (or anyone who may
// change the platoon) keeps it or moves it; the section's weekly report waits for the answer. Times are shown in Zulu and
// in the viewer's own time zone, before and after the change.
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The week's date (`YYYY-MM-DD`).
    week: { type: String, required: true },
    // `{ at, phase: 'before' | 'of', timezone, slot: [weekday, minute], keepLocal: [weekday, minute], confirmed, canConfirm }`.
    change: { type: Object, required: true },
    // This week's practice start (ISO), from the practice slot.
    practiceStart: { type: String, default: null },
    // The viewer's own time zone; the browser's when not given (tests pass one).
    timeZone: { type: String, default: undefined },
});
const emit = defineEmits(['confirmed']);

const WEEK_MS = 7 * 24 * 60 * 60 * 1000;
const asSlot = ([weekday, startMinute]) => ({ weekday, startMinute });

// A practice before the change and one after it, a week apart.
const before = computed(() => {
    if (!props.practiceStart) return null;
    const start = new Date(props.practiceStart).getTime();

    return new Date(props.change.phase === 'before' ? start : start - WEEK_MS);
});
const after = computed(() => (before.value ? new Date(before.value.getTime() + WEEK_MS) : null));
const local = (date) => (date ? localLabel(date, undefined, props.timeZone) : '');
// The slot that keeps the practice at its old local time, a week after the one before.
const movedStart = computed(() => {
    if (!before.value) return null;
    const [weekday, minute] = props.change.slot;
    const [toDay, toMinute] = props.change.keepLocal;
    const shift = ((toDay - weekday) * 1440 + (toMinute - minute)) * 60000;

    return new Date(after.value.getTime() + shift);
});

const current = computed(() => slotLabel(asSlot(props.change.slot)));
const moved = computed(() => slotLabel(asSlot(props.change.keepLocal)));
const when = computed(() => forumDate(props.change.at));
const answer = computed(() => props.change.confirmed);

const busy = ref(false);
async function confirm(move) {
    if (busy.value || !props.change.canConfirm) return;
    busy.value = true;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/slot/clock-change`, { week: props.week, move }).catch(unreachable);
        if (!result.ok) {
            toast.error('Could not confirm the practice slot', { description: messageFor(result) });
            return;
        }
        toast.success(move ? `Practice moved to ${moved.value}` : `Practice kept at ${current.value}`, {
            description: move ? 'From the first practice after the clocks change.' : undefined,
        });
        emit('confirmed', result.data);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <WarningBanner :level="change.confirmed ? 'medium' : 'critical'" class="mt-3" data-testid="clock-change">
        <p class="flex items-center gap-1.5 font-medium">
            <PhClockClockwise class="size-4" aria-hidden="true" />Clocks {{ change.phase === 'before' ? 'change' : 'changed' }} on {{ when }}
        </p>
        <p v-if="before" data-testid="clock-change-times">
            Practice at {{ current }} was {{ local(before) }} your time and is {{ local(after) }} from
            {{ change.phase === 'before' ? 'next week' : 'this week' }}. Move it to {{ moved }} to keep it at {{ local(before) }}.
        </p>
        <p class="text-xs" data-testid="clock-change-zone">The clocks change where your platoon leader is ({{ change.timezone }}).</p>

        <p v-if="answer" class="mt-1" data-testid="clock-change-answer">
            <template v-if="answer.moved">{{ answer.by ?? 'Your platoon leader' }} moved it to {{ moved }}.</template>
            <template v-else>{{ answer.by ?? 'Your platoon leader' }} kept it at {{ current }}.</template>
        </p>
        <div v-else-if="change.canConfirm" class="mt-2 flex flex-wrap gap-2">
            <Button size="sm" :disabled="busy" data-testid="clock-change-move" @click="confirm(true)">
                Move to {{ moved }}<template v-if="movedStart"> ({{ local(movedStart) }})</template>
            </Button>
            <Button size="sm" variant="outline" :disabled="busy" data-testid="clock-change-keep" @click="confirm(false)">
                Keep {{ current }}<template v-if="after"> ({{ local(after) }})</template>
            </Button>
        </div>
        <p v-else class="mt-1" data-testid="clock-change-waiting">Your platoon leader confirms the slot here. The weekly report waits for it.</p>
    </WarningBanner>
</template>
