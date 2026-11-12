<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhCheck, PhCheckCircle, PhCircle, PhCopy, PhLockSimple, PhPaperPlaneTilt } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { unaccountedTroopers } from '@/lib/report';
import { displayName, signatureOf } from '@/lib/rollcall';

// Step 4 of a week on the new flow (#703, mockup 703-4-close-for-review): re-copy the same post, now with every mark,
// and edit the forum post with it; then close, which sends the weekly report through its own send dialog (the notes,
// reasons for anyone unaccounted for and the make-up checks are asked there, as always). Sending locks signatures and
// marks (AD-12); an edit of the report reopens the roll call.
const props = defineProps({
    // `{ at, by, threadUrl }` of the latest "posted" stamp, or null.
    posted: { type: Object, default: null },
    // Marks or the post changed since that stamp: the forum post needs editing again.
    editedSincePost: { type: Boolean, default: false },
    // The BBCode on screen is the one last copied.
    copied: { type: Boolean, default: false },
    // The post can be copied (the template renders).
    postReady: { type: Boolean, default: false },
    // `countsOf()` of the section's troopers.
    counts: { type: Object, required: true },
    confirmed: { type: Boolean, default: false },
    // When practice starts this week (ISO), or null, and the training topic, or null.
    trainingStart: { type: String, default: null },
    topic: { type: String, default: null },
    // The section's platoon, for who the report goes to.
    platoon: { type: String, default: null },
    // The section's report for the week: `{ sent, href }`.
    report: { type: Object, required: true },
    // Why nothing here can be changed, or null.
    disabledReason: { type: String, default: null },
    posting: { type: Boolean, default: false },
    sending: { type: Boolean, default: false },
    // The section's troopers with their marks on screen: who missed practice needs a reason.
    troopers: { type: Array, default: () => [] },
    // The report's draft as the close step writes it: `{ notes, unaccounted: [{ trooper_id, reason }], reasons: [{ value, label }] }`.
    draft: { type: Object, default: () => ({ notes: null, unaccounted: [], reasons: [] }) },
});

const emit = defineEmits(['copy', 'mark-posted', 'send', 'update:draft']);

// Who missed practice with no make-up (mockup 703-4): each is reported with a reason, as on the weekly report.
const missed = computed(() => unaccountedTroopers(props.troopers));
const reasonOf = (id) => (props.draft.unaccounted ?? []).find((row) => row.trooper_id === id)?.reason ?? '';
function setNotes(value) {
    emit('update:draft', { ...props.draft, notes: value });
}
function setReason(id, value) {
    const others = (props.draft.unaccounted ?? []).filter((row) => row.trooper_id !== id);
    emit('update:draft', { ...props.draft, unaccounted: value ? [...others, { trooper_id: id, reason: value }] : others });
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const trainingLabel = computed(() => {
    const date = props.trainingStart ? `${WEEKDAYS[new Date(props.trainingStart).getUTCDay()]} ${forumDateTime(props.trainingStart)}Z` : 'No practice this week';

    return props.topic ? `${date} · ${props.topic}` : date;
});
const edited = computed(() => Boolean(props.posted) && !props.editedSincePost);
const allMarked = computed(() => props.counts.total > 0 && props.counts.unmarked === 0);
const checks = computed(() => [
    { key: 'confirmed', label: 'Practice confirmed', done: props.confirmed },
    { key: 'marked', label: 'Every trooper has a mark', done: allMarked.value },
    { key: 'edited', label: 'Forum post edited', done: edited.value },
    ...missed.value.map((trooper) => ({ key: `reason-${trooper.id}`, label: `Reason for ${displayName(trooper)}`, done: Boolean(reasonOf(trooper.id)) })),
]);
const destination = computed(() => (props.platoon ? `${props.platoon} staff` : 'Platoon Staff'));

function onEdited(value) {
    if (value && !edited.value) emit('mark-posted');
}
</script>

<template>
    <section aria-labelledby="step-4-title" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18.5rem]" data-testid="rollcall-close">
        <div class="min-w-0 space-y-4">
            <h2 id="step-4-title" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">Close for review</h2>

            <div
                v-if="report.sent"
                role="status"
                class="flex flex-wrap items-start gap-2.5 rounded-lg border border-info/35 bg-info/7 px-3.5 py-3 text-[13px]"
                data-testid="close-sent"
            >
                <PhLockSimple class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                <div class="min-w-[14rem] flex-1">
                    <p class="font-medium">Closed and sent to {{ destination }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">Signatures and marks are locked. An edit of the report reopens this roll call.</p>
                </div>
                <Button variant="outline" size="sm" as-child><Link :href="report.href">Open the weekly report</Link></Button>
            </div>

            <Card class="gap-3 p-5" data-testid="close-post">
                <h3 class="text-[15px] font-semibold">A. Update your forum post</h3>
                <p class="text-[13px] text-muted-foreground">
                    The post now has every signature and practice mark. Copy it and edit the post you made<template v-if="posted"> on {{ forumDate(posted.at) }}</template>.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <Button variant="outline" :disabled="!postReady" data-testid="close-copy" @click="emit('copy')">
                        <PhCopy />{{ copied ? 'Copied' : 'Copy updated BBCode' }}
                    </Button>
                    <a
                        v-if="posted?.threadUrl"
                        :href="posted.threadUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-[13px] text-primary underline underline-offset-2"
                        data-testid="close-thread"
                    >
                        Open your forum post
                    </a>
                    <label class="ml-auto inline-flex items-center gap-2 text-[13px]">
                        <Checkbox
                            :model-value="edited"
                            :disabled="edited || Boolean(disabledReason) || posting || !copied"
                            :title="!copied && !edited ? 'Copy the updated BBCode first' : undefined"
                            data-testid="close-edited"
                            @update:model-value="onEdited"
                        />
                        I edited the forum post
                    </label>
                </div>
            </Card>

            <Card class="gap-3 p-5" data-testid="close-report">
                <h3 class="text-[15px] font-semibold">B. Weekly report for Platoon Staff</h3>
                <dl class="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13px]">
                    <dt class="text-muted-foreground">Training</dt>
                    <dd data-testid="close-training">{{ trainingLabel }}</dd>
                    <dt class="text-muted-foreground">Attendance</dt>
                    <dd class="tabular-nums" data-testid="close-attendance">
                        {{ counts.present + counts.makeup }} attended · {{ counts.missed }} not attended<template v-if="counts.unsigned"> · {{ counts.unsigned }} unsigned</template>
                    </dd>
                </dl>
                <div class="grid gap-1.5">
                    <Label for="close-notes">Notes to send with this report</Label>
                    <Textarea
                        id="close-notes"
                        :model-value="draft.notes ?? ''"
                        rows="3"
                        maxlength="5000"
                        :disabled="Boolean(disabledReason)"
                        data-testid="close-notes"
                        @update:model-value="setNotes"
                    />
                </div>
                <div v-for="trooper in missed" :key="trooper.id" class="grid gap-1.5" :data-reason-trooper="trooper.id">
                    <Label :for="`close-reason-${trooper.id}`">
                        {{ displayName(trooper) }} missed{{ signatureOf(trooper) === 'unsigned' ? ' without signing' : '' }}. Why?
                    </Label>
                    <NativeSelect
                        :id="`close-reason-${trooper.id}`"
                        :model-value="reasonOf(trooper.id)"
                        :disabled="Boolean(disabledReason)"
                        @update:model-value="(value) => setReason(trooper.id, value)"
                    >
                        <option value="">Reason for Platoon Staff</option>
                        <option v-for="reason in draft.reasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                    </NativeSelect>
                </div>
                <p class="text-xs text-muted-foreground">
                    These save as you go and are the report's own notes and reasons. When you close, the report opens to send, where you answer any make-up still owed.
                </p>
            </Card>

            <slot />
        </div>

        <aside class="space-y-4 lg:sticky lg:top-[calc(var(--shell-top,3rem)+5.5rem)]" aria-label="Before you close">
            <Card class="gap-3 p-4 text-[13px]">
                <p class="font-semibold">Before you close</p>
                <ul class="space-y-1.5" data-testid="close-checks">
                    <li v-for="check in checks" :key="check.key" :data-check="check.key" :data-done="String(check.done)" class="flex items-center gap-2">
                        <PhCheck v-if="check.done" class="size-4 text-success" aria-label="Done" />
                        <PhCircle v-else class="size-4 text-muted-foreground" aria-label="Not yet" />
                        <span :class="!check.done && 'text-muted-foreground'">{{ check.label }}</span>
                    </li>
                </ul>
                <Button
                    v-if="!report.sent"
                    class="h-11 w-full sm:h-9"
                    :disabled="Boolean(disabledReason) || sending || !confirmed"
                    :title="disabledReason ?? (confirmed ? undefined : 'Confirm the practice took place first')"
                    data-testid="close-send"
                    @click="emit('send')"
                >
                    <PhPaperPlaneTilt />Close and send for review
                </Button>
                <p v-else class="flex items-center gap-1.5 text-success"><PhCheckCircle class="size-4" aria-hidden="true" />Sent for review</p>
                <p class="text-xs text-muted-foreground">
                    Closing locks signatures and marks and sends the report to {{ destination }}. They can return it to you to reopen.
                </p>
            </Card>
        </aside>
    </section>
</template>
