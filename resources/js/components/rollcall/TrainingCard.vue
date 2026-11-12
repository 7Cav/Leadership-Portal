<script setup>
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { forumDateTime } from '@/lib/forumDate';
import { postJson } from '@/lib/postJson';

// The week's training on the roll call (#703): when practice starts and the training topic, the two values the forum
// post prints as `{{training_date}}` and `{{training_topic}}`. The topic is the weekly report's training subject, one
// value: saving it here changes the report, and the platoon's topic prefills it and locks it when enforced. The date
// is the section's slot or this week's own time, changed through the practice slot editor (`change-date`).
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The roll call's week, `YYYY-MM-DD` (its Saturday).
    week: { type: String, required: true },
    // The `training` prop: `{ subject, enforced, platoon, canEdit }`.
    training: { type: Object, required: true },
    // When this week's practice starts (ISO), or null with no practice: no slot, or cancelled this week.
    start: { type: String, default: null },
    // Whether the screen may write at all (not a preview, a sent week or a read-only section).
    editable: { type: Boolean, default: false },
    // Why nothing here can be changed, or null.
    disabledReason: { type: String, default: null },
    // Whether this week's practice can be moved here: the leader may write and the section has a slot.
    canChangeDate: { type: Boolean, default: false },
});

const emit = defineEmits(['updated', 'change-date']);

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const dateLabel = computed(() => (props.start ? `${WEEKDAYS[new Date(props.start).getUTCDay()]} ${forumDateTime(props.start)}Z` : 'No practice this week'));

const topic = ref(props.training.subject ?? '');
watch(
    () => props.training.subject,
    (subject) => (topic.value = subject ?? ''),
);
const topicEditable = computed(() => props.editable && props.training.canEdit);
const changed = computed(() => topic.value.trim() !== (props.training.subject ?? ''));
const saving = ref(false);

async function save() {
    if (!changed.value || saving.value) return;
    saving.value = true;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/training-topic`, { week: props.week, subject: topic.value.trim() }).catch(unreachable);
        if (result.ok && result.data?.training) {
            emit('updated', result.data.training);
            toast.success('Training topic saved', { description: 'The weekly report shows the same topic.' });
        } else {
            toast.error('Could not save the training topic', { description: messageFor(result) });
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Card class="gap-3 p-4 text-[13px]" data-testid="training-card">
        <div class="grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">Training date</p>
                <p class="mt-0.5 flex flex-wrap items-baseline gap-x-2 font-medium">
                    <span class="tabular-nums" data-testid="training-date">{{ dateLabel }}</span>
                    <button
                        v-if="canChangeDate"
                        type="button"
                        class="text-xs font-normal text-primary underline-offset-2 hover:underline disabled:opacity-50"
                        :disabled="Boolean(disabledReason)"
                        :title="disabledReason ?? 'Change this week\'s practice time'"
                        data-testid="training-date-change"
                        @click="emit('change-date')"
                    >
                        Change
                    </button>
                </p>
            </div>
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Label v-if="topicEditable" for="training-topic" class="text-xs font-normal text-muted-foreground">Training topic</Label>
                    <span v-else>Training topic</span>
                    <Badge
                        v-if="training.platoon && training.enforced"
                        variant="outline"
                        class="border-primary/40 bg-primary/10 px-1.5 text-[11px] font-normal text-foreground"
                        data-testid="training-topic-source"
                    >
                        Set by {{ training.platoon }}
                    </Badge>
                </p>
                <form v-if="topicEditable" class="mt-1 flex gap-2" @submit.prevent="save">
                    <Input id="training-topic" v-model="topic" maxlength="255" placeholder="Type this week's topic" class="h-8 text-[13px]" data-testid="training-topic-input" />
                    <Button type="submit" size="sm" variant="outline" :disabled="!changed || saving" data-testid="training-topic-save">Save</Button>
                </form>
                <p v-else class="mt-0.5 flex items-center gap-1.5 font-medium" data-testid="training-topic">
                    <PhLockSimple v-if="training.enforced" class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span v-if="training.subject">{{ training.subject }}</span>
                    <span v-else class="font-normal text-muted-foreground">No topic set</span>
                </p>
                <p v-if="training.enforced" class="mt-1 text-xs text-muted-foreground">Your platoon enforces this topic, so it can't be changed here.</p>
            </div>
        </div>
    </Card>
</template>
