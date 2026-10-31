<script setup>
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { PhPencilSimple } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { postJson } from '@/lib/postJson';
import { localLabel, minuteToTime, nextStart, slotLabel, startInWeek, startLabel, timeToMinute, WEEKDAY_NAMES } from '@/lib/practiceSlot';

// The section's weekly practice slot on its roll call (#392): "Practice Thu 02 00Z" with the viewer's local time as a
// hint, and for those who may write here an editor for the slot and for this week's practice (move it or cancel it,
// that week only). `slot` is the `practiceSlot` prop (`PracticeSchedule::props()`), null when the section has none.
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The roll call's week, `YYYY-MM-DD` (its Saturday).
    week: { type: String, required: true },
    slot: { type: Object, default: null },
    // Why the slot cannot be changed here (a preview, a sent week, or a section the viewer only reads), or null.
    disabledReason: { type: String, default: null },
    canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(['updated']);

const open = ref(false);
const saving = ref(false);
const weekday = ref('4');
const time = ref('02:00');
const moveDay = ref('4');
const moveTime = ref('02:00');

const thisWeek = computed(() => props.slot?.week ?? null);
const label = computed(() => (props.slot ? slotLabel(props.slot) : null));
const localHint = computed(() => (props.slot ? localLabel(nextStart(props.slot)) : null));
// This week's practice when it differs from the slot: moved, or cancelled.
const weekNote = computed(() => {
    if (!thisWeek.value) return null;
    if (thisWeek.value.cancelled) return 'Cancelled this week';
    if (thisWeek.value.moved && thisWeek.value.start) return `This week ${startLabel(thisWeek.value.start)}`;

    return null;
});
const weekEditable = computed(() => Boolean(props.slot) && !thisWeek.value?.captured);

function startEditing() {
    weekday.value = String(props.slot?.weekday ?? 4);
    time.value = minuteToTime(props.slot?.startMinute ?? 120);
    const start = thisWeek.value?.start ? new Date(thisWeek.value.start) : null;
    moveDay.value = String(start ? start.getUTCDay() : (props.slot?.weekday ?? 4));
    moveTime.value = start ? minuteToTime(start.getUTCHours() * 60 + start.getUTCMinutes()) : time.value;
    open.value = true;
}

async function send(url, body, success) {
    saving.value = true;
    try {
        const result = await postJson(url, body).catch(unreachable);
        if (result.ok && result.data && 'practiceSlot' in result.data) {
            emit('updated', result.data.practiceSlot);
            toast.success(success);
            open.value = false;
        } else {
            toast.error('Could not change the practice slot', { description: messageFor(result) });
        }
    } finally {
        saving.value = false;
    }
}

function saveSlot() {
    const minute = timeToMinute(time.value);
    if (minute === null) {
        toast.error('Could not change the practice slot', { description: 'Enter a start time in Zulu, 0000 to 2359.' });

        return;
    }
    send(`/rollcall/${props.sectionId}/slot`, { weekday: Number(weekday.value), start_minute: minute, week: props.week }, 'Practice slot saved');
}

function clearSlot() {
    send(`/rollcall/${props.sectionId}/slot`, { weekday: null, start_minute: null, week: props.week }, 'Practice slot removed');
}

function moveWeek() {
    const minute = timeToMinute(moveTime.value);
    if (minute === null) {
        toast.error('Could not move the practice', { description: 'Enter a start time in Zulu, 0000 to 2359.' });

        return;
    }
    const movedTo = startInWeek(props.week, Number(moveDay.value), minute).toISOString();
    send(`/rollcall/${props.sectionId}/slot/week`, { week: props.week, cancelled: false, moved_to: movedTo }, 'Practice moved for this week');
}

function cancelWeek() {
    send(`/rollcall/${props.sectionId}/slot/week`, { week: props.week, cancelled: true, moved_to: null }, 'Practice cancelled for this week');
}

function restoreWeek() {
    send(`/rollcall/${props.sectionId}/slot/week`, { week: props.week, cancelled: false, moved_to: null }, 'Practice back on its usual time');
}
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-1.5" data-testid="practice-slot">
        <template v-if="slot">
            <span>Practice {{ label }}</span>
            <span class="text-muted-foreground/80" data-testid="practice-slot-local">({{ localHint }} your time)</span>
            <span v-if="weekNote" class="text-warning" data-testid="practice-slot-week">{{ weekNote }}</span>
        </template>
        <span v-else data-testid="practice-slot-none">No practice slot</span>
        <Button
            v-if="canEdit"
            variant="ghost"
            size="icon-sm"
            class="size-6"
            :disabled="Boolean(disabledReason)"
            :title="disabledReason ?? 'Change the practice slot'"
            aria-label="Change the practice slot"
            data-testid="practice-slot-edit"
            @click="startEditing"
        >
            <PhPencilSimple class="size-3.5" />
        </Button>
    </span>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Practice slot</DialogTitle>
                <DialogDescription>A section practice lasts one hour. Times are in Zulu.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-3" data-testid="practice-slot-form" @submit.prevent="saveSlot">
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="practice-weekday">Weekday</Label>
                        <NativeSelect id="practice-weekday" v-model="weekday" data-testid="practice-slot-weekday">
                            <option v-for="(name, i) in WEEKDAY_NAMES" :key="i" :value="String(i)">{{ name }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="practice-time">Start (Zulu)</Label>
                        <Input id="practice-time" v-model="time" type="time" data-testid="practice-slot-time" />
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="submit" size="sm" :disabled="saving" data-testid="practice-slot-save">Save slot</Button>
                    <Button v-if="slot" type="button" variant="outline" size="sm" :disabled="saving" data-testid="practice-slot-clear" @click="clearSlot">Remove slot</Button>
                </div>
            </form>

            <div v-if="weekEditable" class="mt-2 grid gap-3 border-t pt-4" data-testid="practice-week">
                <p class="text-sm font-medium">This week only</p>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="practice-move-day">Weekday</Label>
                        <NativeSelect id="practice-move-day" v-model="moveDay" data-testid="practice-week-weekday">
                            <option v-for="(name, i) in WEEKDAY_NAMES" :key="i" :value="String(i)">{{ name }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="practice-move-time">Start (Zulu)</Label>
                        <Input id="practice-move-time" v-model="moveTime" type="time" data-testid="practice-week-time" />
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" size="sm" variant="outline" :disabled="saving" data-testid="practice-week-move" @click="moveWeek">Move this week</Button>
                    <Button v-if="!thisWeek?.cancelled" type="button" size="sm" variant="outline" :disabled="saving" data-testid="practice-week-cancel" @click="cancelWeek">
                        Cancel this week
                    </Button>
                    <Button
                        v-if="thisWeek?.cancelled || thisWeek?.moved"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="saving"
                        data-testid="practice-week-restore"
                        @click="restoreWeek"
                    >
                        Back to the usual time
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
