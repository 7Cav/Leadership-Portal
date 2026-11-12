<script setup>
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { forumDate } from '@/lib/forumDate';
import { postJson } from '@/lib/postJson';
import { localLabel, minuteToTime, slotLabel, startInWeek, timeToMinute, WEEKDAYS } from '@/lib/practiceSlot';

// Step 1 of a week on the new flow (#703, mockup 703-1-create): create this week's roll call with its training date
// and topic. The date is this week's practice, prefilled from the section's slot; a different one moves this week's
// practice only. The topic is the weekly report's training subject as it stands (the section's own, else the
// platoon's), locked when the platoon enforces it or the viewer may not change it. "Practice will not take place"
// cancels this week's practice and creates the roll call. A week with no practice to move (no slot, or one that only
// starts later) is created without a date.
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The roll call's week, `YYYY-MM-DD` (its Saturday).
    week: { type: String, required: true },
    // The `practiceSlot` prop as it now stands, or null with no slot.
    slot: { type: Object, default: null },
    // The `training` prop: `{ subject, enforced, platoon, canEdit }`.
    training: { type: Object, required: true },
    editable: { type: Boolean, default: false },
    // Why nothing here can be changed, or null.
    disabledReason: { type: String, default: null },
});

const emit = defineEmits(['created', 'set-slot', 'locked']);

const DAY_MS = 86_400_000;
// The seven days of the Cav week, Saturday to Friday, each with its date: the practice moves within the week only.
const days = computed(() => {
    const saturday = Date.parse(`${props.week}T00:00:00Z`);

    return Array.from({ length: 7 }, (_, i) => {
        const date = new Date(saturday + i * DAY_MS);

        return { weekday: date.getUTCDay(), label: `${WEEKDAYS[date.getUTCDay()]} ${forumDate(date)}` };
    });
});

const start = computed(() => (props.slot?.week?.cancelled ? null : (props.slot?.week?.start ?? null)));
const cancelled = computed(() => Boolean(props.slot?.week?.cancelled));
const day = ref('3');
const time = ref('23:00');
const topic = ref('');
function prefill() {
    const at = start.value ? new Date(start.value) : null;
    day.value = String(at ? at.getUTCDay() : (props.slot?.weekday ?? 3));
    time.value = at ? minuteToTime(at.getUTCHours() * 60 + at.getUTCMinutes()) : minuteToTime(props.slot?.startMinute ?? 1380);
    topic.value = props.training.subject ?? '';
}
prefill();
watch(() => [props.slot, props.training], prefill);

const slotHint = computed(() => (props.slot ? `${slotLabel(props.slot)}, ${localLabel(start.value ?? Date.now())} your time` : null));
const topicLocked = computed(() => props.training.enforced || !props.training.canEdit);
const creating = ref(false);
const disabled = computed(() => !props.editable || creating.value);

async function create(notHeld = false) {
    let movedTo = null;
    if (start.value && !notHeld) {
        const minute = timeToMinute(time.value);
        if (minute === null) {
            toast.error('Could not create the roll call', { description: 'Enter a start time in Zulu, 0000 to 2359.' });

            return;
        }
        movedTo = startInWeek(props.week, Number(day.value), minute).toISOString();
    }

    creating.value = true;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/create`, {
            week: props.week,
            moved_to: movedTo,
            cancelled: notHeld,
            // A locked topic is left as it is, so none is sent; an empty one clears the section's own.
            ...(topicLocked.value ? {} : { subject: topic.value.trim() }),
        }).catch(unreachable);
        if (result.ok && result.data?.flow) {
            emit('created', result.data);
            toast.success(notHeld ? 'Roll call created, practice cancelled this week' : 'Roll call created', { description: 'Next: copy the post to the forum.' });
        } else {
            toast.error('Could not create the roll call', { description: messageFor(result) });
            // The report was sent since the page loaded: the page shows the week as the server now has it.
            if (result.status === 409 && result.data?.locked) emit('locked');
        }
    } finally {
        creating.value = false;
    }
}
</script>

<template>
    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18.5rem]" data-testid="rollcall-create">
        <Card class="gap-0 p-5 sm:p-6">
            <h2 id="step-1-title" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">Create this week's roll call</h2>
            <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                Both values go into the forum post and the weekly report. You can change the date later; the topic follows your platoon.
            </p>

            <form class="mt-5 grid gap-5" @submit.prevent="create(false)">
                <div class="grid gap-1.5">
                    <Label for="create-day">Training date</Label>
                    <template v-if="start">
                        <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
                            <NativeSelect id="create-day" v-model="day" :disabled="disabled" data-testid="create-day">
                                <option v-for="d in days" :key="d.weekday" :value="String(d.weekday)">{{ d.label }}</option>
                            </NativeSelect>
                            <Input v-model="time" type="time" aria-label="Start (Zulu)" :disabled="disabled" data-testid="create-time" />
                        </div>
                        <p class="text-xs text-muted-foreground">From your section's practice slot ({{ slotHint }}). Changing it affects this week only.</p>
                    </template>
                    <p v-else-if="cancelled" class="text-[13px]" data-testid="create-cancelled">Practice is cancelled this week.</p>
                    <p v-else-if="slot" class="text-[13px] text-muted-foreground" data-testid="create-slot-later">
                        No practice this week: the section's slot starts in a later week.
                    </p>
                    <div v-else class="flex flex-wrap items-center gap-2 text-[13px]" data-testid="create-no-slot">
                        <span class="text-muted-foreground">This section has no practice slot, so the post has no date.</span>
                        <Button type="button" variant="outline" size="sm" :disabled="disabled" @click="emit('set-slot')">Set the practice slot</Button>
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <Label for="create-topic">Training topic</Label>
                        <Badge
                            v-if="training.platoon && training.enforced"
                            variant="outline"
                            class="border-primary/40 bg-primary/10 px-1.5 text-[11px] font-normal text-foreground"
                            data-testid="create-topic-source"
                        >
                            Set by {{ training.platoon }}
                        </Badge>
                    </div>
                    <div class="relative">
                        <PhLockSimple v-if="topicLocked" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="create-topic"
                            v-model="topic"
                            maxlength="255"
                            :readonly="topicLocked"
                            :disabled="disabled"
                            :class="topicLocked && 'pl-9'"
                            :placeholder="topicLocked ? '' : 'Type this week\'s topic'"
                            data-testid="create-topic"
                        />
                    </div>
                    <p v-if="training.enforced" class="text-xs text-muted-foreground">
                        Your platoon enforces this topic, so it can't be changed here.
                    </p>
                    <p v-else-if="training.platoon" class="text-xs text-muted-foreground">From {{ training.platoon }}. You can change it for your section.</p>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-border pt-5">
                    <Button type="submit" class="h-11 sm:h-9" :disabled="disabled" :title="disabledReason ?? undefined" data-testid="create-rollcall">Create roll call</Button>
                    <Button
                        v-if="start"
                        type="button"
                        variant="outline"
                        class="h-11 sm:h-9"
                        :disabled="disabled"
                        :title="disabledReason ?? undefined"
                        data-testid="create-not-held"
                        @click="create(true)"
                    >
                        Practice will not take place
                    </Button>
                </div>
            </form>
        </Card>

        <aside class="space-y-4" aria-label="About this step">
            <Card class="gap-2 p-4 text-[13px]">
                <p class="font-semibold">What happens next</p>
                <ol class="list-decimal space-y-1 pl-4 text-muted-foreground">
                    <li>Copy the post and put it on the forum.</li>
                    <li>Troopers sign; you set signatures and practice marks.</li>
                    <li>Close the roll call. That sends the weekly report to Platoon Staff.</li>
                </ol>
            </Card>
            <Card v-if="!training.platoon" class="gap-2 p-4 text-[13px]" data-testid="create-no-topic">
                <p class="font-semibold">No topic from the platoon yet?</p>
                <p class="text-muted-foreground">The field is empty and editable. Type your own topic; Platoon Staff see it in the report.</p>
            </Card>
        </aside>
    </div>
</template>
