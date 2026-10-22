<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhCaretLeft,
    PhCaretRight,
    PhClockCounterClockwise,
    PhCloudArrowUp,
    PhCloudCheck,
    PhCloudSlash,
    PhInfo,
    PhLockSimple,
    PhPaperPlaneTilt,
    PhPencilSimpleLine,
    PhSealCheck,
    PhStack,
} from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import HistoryPanel from '@/components/reports/HistoryPanel.vue';
import ReadinessChecklist from '@/components/reports/ReadinessChecklist.vue';
import ReportPreview from '@/components/reports/ReportPreview.vue';
import SendDialog from '@/components/reports/SendDialog.vue';
import { forumDate } from '@/lib/forumDate';
import {
    defaultSendReason,
    frozenHighlights,
    highlights as writeHighlights,
    percent,
    readinessChecks,
    stamp,
    timeOf,
    unitLabel,
} from '@/lib/report';

// The weekly report compose screen (Stories 5.3–5.5; binding design mockups/v2/report-p3.html,
// "Compose and check"). Left: what still stands between the leader and sending, with inline
// fixes, and the notes form. Right: the live preview. A sent report is the frozen snapshot,
// read-only. All numbers come from ReportController; nothing here estimates one.
const props = defineProps({
    unit: { type: Object, required: true },
    week: { type: Object, required: true },
    report: { type: Object, required: true },
    kpis: { type: Object, default: null },
    delta: { type: Object, default: null },
    kpiError: { type: String, default: null },
    baseline: { type: Object, required: true },
    trend: { type: Array, required: true },
    troopers: { type: Array, default: null },
    names: { type: Object, default: () => ({}) },
    children: { type: Array, default: null },
    missingChildren: { type: Array, default: () => [] },
    history: { type: Array, required: true },
    reasons: { type: Array, required: true },
    rosterSync: { type: Object, required: true },
    // A section's live draft only: where leave comes from and when it last synced (`LoaSyncState::toArray()`, AD-20).
    loaSync: { type: Object, default: null },
    computedAt: { type: String, required: true },
    // Whether the viewer may save or send this report: false for a unit they only see through a
    // staff grant, which reads but never writes (the server refuses those writes).
    canWrite: { type: Boolean, default: false },
});

const page = usePage();

const sent = computed(() => props.report.status === 'sent');
// View-as (1.13) and a staff grant are read-only: every write is refused server-side, so say so
// here rather than let a save fail.
const viewAs = computed(() => page.props.viewAs ?? null);
// A week before the week floor is shown read-only: the server refuses a draft or send for it.
const readOnly = computed(() => sent.value || !!viewAs.value || !props.canWrite || !!props.week.beforeFloor);
const readOnlyNote = computed(() => {
    if (viewAs.value) return `Read-only while viewing as ${viewAs.value.label}`;
    if (!props.canWrite) return 'Read-only: a staff grant lets you view this report, not edit or send it';
    return props.week.beforeFloor ? 'Read-only, this week is too far back to change' : null;
});

const weekDate = computed(() => props.week.start.slice(0, 10));
const baseUrl = computed(() => `/reports/${props.unit.id}/${weekDate.value}`);
const weekUrl = (date) => `/reports/${props.unit.id}/${date}`;
const rollcallUrl = computed(() => (props.unit.isLeaf ? `/rollcall/${props.unit.id}?week=${weekDate.value}` : null));
const reasonLabels = computed(() => Object.fromEntries(props.reasons.map((r) => [r.value, r.label])));
const parentLabel = computed(() => (props.unit.parent ? unitLabel(props.unit.parent) : null));
// "88%", or "—" with no percent sign when nothing was eligible.
const percentText = (ratio) => (percent(ratio) === null ? '—' : `${percent(ratio)}%`);

// ---- The leader's content, autosaved as a draft (5.5) ----
const content = reactive({
    notes: props.report.notes ?? '',
    trainingSubject: props.report.trainingSubject ?? '',
    unaccounted: [...(props.report.unaccounted ?? [])],
});
const reviewed = ref(false);
const staleReview = ref(false);
const subjectTouched = ref(false);

const save = reactive({ state: props.report.savedAt ? 'saved' : 'idle', at: props.report.savedAt });
let timer = null;
// Bumped on every edit, so a save that lands can tell whether it carried the latest content.
let revision = 0;

function payload() {
    return { notes: content.notes, training_subject: content.trainingSubject, unaccounted: content.unaccounted };
}

function saveDraft() {
    clearTimeout(timer);
    timer = null;
    if (readOnly.value) return;

    const sentRevision = revision;
    save.state = 'saving';
    router.put(baseUrl.value, payload(), {
        preserveState: true,
        preserveScroll: true,
        only: ['report', 'computedAt'],
        onSuccess: () => {
            save.at = props.report.savedAt;
            // An edit made while this save was in flight is not in it: the screen stays unsaved
            // (and leaving stays guarded) until that edit's own save lands.
            if (revision === sentRevision) {
                save.state = 'saved';
            } else {
                save.state = 'pending';
                rearm();
            }
        },
        onError: (errors) => saveFailed(Object.values(errors)[0]),
        onHttpException: (response) => {
            saveFailed(httpProblem(response, 'The portal could not save your draft. Try again.'));

            return false;
        },
        onNetworkError: () => {
            saveFailed('Could not reach the portal. Your changes are kept here; try again.');

            return false;
        },
    });
}

function saveFailed(description) {
    save.state = 'error';
    toast.error('Draft not saved', { description });
}

// A 419 is an expired session on a page left open a long time; anything else is the server's.
function httpProblem(response, fallback) {
    return response?.status === 419 ? 'Your session expired. Copy your notes, then reload the page to sign in again.' : fallback;
}

watch(
    () => [content.notes, content.trainingSubject, JSON.stringify(content.unaccounted)],
    ([notes], [notesBefore]) => {
        if (readOnly.value) return;
        // Editing what command will read after confirming it un-confirms it, so the tick stays honest.
        if (notes !== notesBefore && reviewed.value) {
            reviewed.value = false;
            staleReview.value = true;
        }
        revision++;
        save.state = 'pending';
        clearTimeout(timer);
        timer = setTimeout(saveDraft, 800);
    },
);

// Unsaved edits are never dropped silently: leaving the page (another week, another screen, or
// closing the tab) while a save is pending or failed asks first.
const unsaved = () => !readOnly.value && ['pending', 'saving', 'error'].includes(save.state);
const LEAVE = 'Your latest changes to this report are not saved yet. Leave anyway?';

function onBeforeUnload(event) {
    if (!unsaved()) return;
    event.preventDefault();
    event.returnValue = '';
}

let stopGuard = null;
onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    stopGuard = router.on?.('before', (event) => {
        const visit = event.detail.visit;
        // Our own autosave and send are writes to this report; only leaving is guarded.
        if (visit.method !== 'get' || !unsaved()) return;

        return window.confirm(LEAVE);
    });
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('beforeunload', onBeforeUnload);
    stopGuard?.();
});

// ---- Readiness and highlights ----
const checks = computed(() =>
    readinessChecks({
        isLeaf: props.unit.isLeaf,
        troopers: props.troopers,
        children: props.children,
        content,
        reviewed: reviewed.value,
    }),
);
const openChecks = computed(() => checks.value.filter((c) => !c.done));
// Children still out are named on their own in the overview and the reason, not as an open check.
const openItems = computed(() => openChecks.value.filter((c) => c.id !== 'children'));
const missingUnits = computed(() => (props.children ?? []).filter((c) => c.status !== 'sent').map(unitLabel));

const highlightItems = computed(() =>
    sent.value
        ? frozenHighlights({
              kpis: props.kpis,
              delta: props.delta,
              unaccounted: content.unaccounted,
              names: props.names,
              reasonLabels: reasonLabels.value,
              missingChildren: props.missingChildren,
          })
        : writeHighlights({
              kpis: props.kpis,
              delta: props.delta,
              troopers: props.troopers,
              reasons: new Map(content.unaccounted.map((r) => [r.trooper_id, r.reason])),
              reasonLabels: reasonLabels.value,
              children: props.children,
              isLeaf: props.unit.isLeaf,
          }),
);

const noAttendanceYet = computed(
    () => props.unit.isLeaf && !sent.value && (props.troopers ?? []).length > 0 && props.troopers.every((t) => t.status === null && t.rollcall === 'unsigned'),
);

// ---- Send (5.6 via the pre-send overview, 5.5) ----
const dialogOpen = ref(false);
const sending = ref(false);
const sendError = ref(null);

function openSend() {
    if (readOnly.value || props.kpiError) return;
    sendError.value = null;
    dialogOpen.value = true;
}

// The send cleared the pending autosave; when it fails, keep the edits saving as a draft.
function rearm() {
    if (save.state === 'pending' && !timer) timer = setTimeout(saveDraft, 800);
}

function confirmSend(reason) {
    clearTimeout(timer);
    timer = null;
    sending.value = true;
    router.post(`${baseUrl.value}/send`, { ...payload(), reason }, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            toast.success(`Report sent${parentLabel.value ? ` to ${parentLabel.value}` : ''}`, {
                description: `Frozen ${stamp(props.report.sentAt)}.`,
            });
            nextTick(() => document.getElementById('sent-title')?.focus());
        },
        onError: (errors) => {
            sendError.value = errors.reason ?? errors.report ?? errors.unaccounted ?? Object.values(errors)[0];
            rearm();
        },
        onHttpException: (response) => {
            sendError.value = httpProblem(response, 'The portal could not send the report. It has NOT been sent; try again.');
            rearm();

            return false;
        },
        onNetworkError: () => {
            sendError.value = 'Could not reach the portal. The report has NOT been sent; try again.';
            rearm();

            return false;
        },
        onFinish: () => {
            sending.value = false;
        },
    });
}

// ---- Header ----
const now = ref(Date.now());
let clock = null;
onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30_000);
});
onBeforeUnmount(() => clearInterval(clock));

const countdown = computed(() => {
    let seconds = Math.floor((new Date(props.week.due).getTime() - now.value) / 1000);
    if (seconds <= 0) return 'Deadline passed';
    const d = Math.floor(seconds / 86400);
    seconds -= d * 86400;
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds - h * 3600) / 60);

    return `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m left`;
});

const saveLabel = computed(
    () =>
        ({
            pending: 'Unsaved changes',
            saving: 'Saving',
            saved: `Draft saved ${timeOf(save.at) ?? ''}`.trim(),
            error: 'Not saved',
            idle: 'Nothing saved yet',
        })[save.state],
);
const saveIcon = computed(() => ({ saving: PhCloudArrowUp, pending: PhCloudArrowUp, error: PhCloudSlash })[save.state] ?? PhCloudCheck);

const historyEntries = computed(() => [
    ...(sent.value
        ? [
              {
                  week: props.week.start,
                  sentAt: props.report.sentAt,
                  sentBy: props.report.sentBy,
                  kpis: props.kpis,
                  delta: props.delta,
                  notes: props.report.notes,
                  trainingSubject: props.report.trainingSubject,
                  unaccounted: props.report.unaccounted ?? [],
                  missingChildren: props.missingChildren,
              },
          ]
        : []),
    ...props.history,
]);

function focusTarget(id) {
    const target = { 'training-subject': 'training-subject', reviewed: 'reviewed', preview: 'preview' }[id];
    const el = document.getElementById(target);
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    setTimeout(() => el?.focus({ preventScroll: true }), 250);
}

function jumpToNext() {
    const next = openChecks.value[0];
    if (!next) return;
    if (next.id === 'subject') return focusTarget('training-subject');
    if (next.id === 'reviewed') return focusTarget('reviewed');
    const el = document.querySelector(`[data-check="${next.id}"] button`);
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el?.focus({ preventScroll: true });
}

const subjectInvalid = computed(() => props.unit.isLeaf && subjectTouched.value && content.trainingSubject.trim().length < 3);
</script>

<template>
    <Head :title="`${unitLabel(unit)} report, week of ${forumDate(week.start)} · 7Cav Leadership Portal`" />

    <div class="flex flex-col gap-4" data-testid="report-page">
        <section aria-labelledby="page-title" class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">{{ unitLabel(unit) }} weekly report</p>
                <h1 id="page-title" class="flex flex-wrap items-center gap-2.5 text-xl font-semibold tracking-tight sm:text-[22px]">
                    Week of {{ forumDate(week.start) }}
                    <Badge v-if="sent" variant="success" data-testid="status-badge"><PhLockSimple aria-hidden="true" />Sent</Badge>
                    <Badge v-else variant="warning" data-testid="status-badge">Draft</Badge>
                </h1>
            </div>
            <nav class="flex items-center gap-1" aria-label="Choose week">
                <Button variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekUrl(week.previous)" :aria-label="`Previous week, ${forumDate(week.previous)}`"><PhCaretLeft /></Link>
                </Button>
                <Button v-if="week.next" variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekUrl(week.next)" :aria-label="`Next week, ${forumDate(week.next)}`"><PhCaretRight /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next week, not open yet"><PhCaretRight /></Button>
            </nav>
            <div class="w-full sm:ml-auto sm:w-auto sm:text-right">
                <p class="text-xs text-muted-foreground">{{ parentLabel ? `Due to ${parentLabel} ${stamp(week.due)}` : `Due ${stamp(week.due)}` }}</p>
                <p v-if="!sent" class="text-lg leading-tight font-semibold tabular-nums" data-testid="countdown">{{ countdown }}</p>
                <p v-else class="text-[13px] font-medium" data-testid="sent-line">
                    Sent {{ stamp(report.sentAt) }}<template v-if="report.sentBy"> by {{ report.sentBy }}</template>
                </p>
            </div>
        </section>

        <!-- The shared mode switch (5.3 AC): this screen creates and sends; the roll-up navigates. -->
        <nav aria-label="Report mode" class="inline-flex w-fit gap-0.5 rounded-md bg-muted p-0.5 text-xs font-medium">
            <span aria-current="page" class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-sm bg-card px-2.5 text-foreground shadow-sm">
                <PhPencilSimpleLine aria-hidden="true" />Create and send
            </span>
            <Link href="/reports" class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-sm px-2.5 text-muted-foreground hover:text-foreground">
                <PhStack aria-hidden="true" />Command roll-up
            </Link>
        </nav>

        <Tabs default-value="report" class="gap-4">
            <TabsList aria-label="Report views">
                <TabsTrigger value="report"><PhPencilSimpleLine aria-hidden="true" />{{ unit.isLeaf ? 'Report' : 'Roll-up' }}</TabsTrigger>
                <TabsTrigger value="history" data-testid="history-tab">
                    <PhClockCounterClockwise aria-hidden="true" />History
                    <Badge variant="outline" class="tabular-nums">{{ historyEntries.length }}</Badge>
                </TabsTrigger>
            </TabsList>

            <TabsContent value="report" class="flex flex-col">
                <div
                    v-if="noAttendanceYet"
                    class="mb-4 flex items-start gap-2.5 rounded-lg border border-info/30 bg-info/8 px-4 py-3 text-[13px]"
                    data-testid="no-attendance"
                >
                    <PhInfo class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                    <p>
                        No attendance yet this week. The numbers below are zero until rollcall is signed and practice is marked;
                        the draft fills in as you go.
                    </p>
                </div>

                <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    <div class="flex min-w-0 flex-col gap-5">
                        <section v-if="sent" class="rounded-lg border border-border bg-card p-5" aria-labelledby="sent-title" data-testid="sent-panel">
                            <div class="flex items-start gap-3">
                                <span class="grid size-10 flex-none place-items-center rounded-md border border-success/30 bg-success/8 text-xl text-success" aria-hidden="true">
                                    <PhSealCheck />
                                </span>
                                <div class="min-w-0">
                                    <h2 id="sent-title" tabindex="-1" class="text-[15px] font-semibold outline-none">
                                        Report sent{{ parentLabel ? ` to ${parentLabel}` : '' }}
                                    </h2>
                                    <p class="mt-0.5 text-[13px] text-muted-foreground">
                                        Frozen {{ stamp(report.sentAt) }}<template v-if="report.sentBy"> by {{ report.sentBy }}</template>.
                                    </p>
                                </div>
                            </div>
                            <dl class="mt-4 grid grid-cols-[7.5rem_minmax(0,1fr)] gap-y-2 text-[13px]">
                                <template v-if="unit.isLeaf">
                                    <dt class="text-muted-foreground">Trained</dt>
                                    <dd>{{ report.trainingSubject || 'Not filled in' }}</dd>
                                </template>
                                <template v-if="kpis">
                                    <dt class="text-muted-foreground">Key numbers</dt>
                                    <dd class="tabular-nums">
                                        RC {{ percentText(kpis.rc) }}, SP {{ percentText(kpis.sp) }}, {{ kpis.atRisk }} at-risk
                                    </dd>
                                </template>
                                <template v-if="missingChildren.length">
                                    <dt class="text-muted-foreground">Sent without</dt>
                                    <dd>{{ missingChildren.length }} {{ missingChildren.length === 1 ? 'unit' : 'units' }} that had not reported</dd>
                                </template>
                            </dl>
                            <p class="mt-4 text-xs text-muted-foreground">
                                The snapshot never changes. Later rollcall edits stay out of it.
                            </p>
                        </section>

                        <ReadinessChecklist
                            v-else
                            :checks="checks"
                            :troopers="troopers ?? []"
                            :children="children ?? []"
                            :unaccounted="content.unaccounted"
                            :reasons="reasons"
                            :rollcall-url="rollcallUrl"
                            :week-date="weekDate"
                            :read-only="readOnly"
                            @update:unaccounted="content.unaccounted = $event"
                            @focus="focusTarget"
                        />

                        <section class="rounded-lg border border-border bg-card" aria-labelledby="notes-title" data-testid="notes-panel">
                            <div class="flex items-center gap-2 border-b border-border px-4 pt-3.5 pb-3">
                                <h2 id="notes-title" class="text-[15px] font-semibold">Leader notes</h2>
                                <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-muted-foreground" aria-live="polite" data-testid="save-state">
                                    <template v-if="sent"><PhLockSimple aria-hidden="true" />Locked with the snapshot</template>
                                    <template v-else-if="readOnlyNote"><PhLockSimple aria-hidden="true" />{{ readOnlyNote }}</template>
                                    <template v-else>
                                        <component :is="saveIcon" aria-hidden="true" :class="{ 'text-destructive': save.state === 'error' }" />{{ saveLabel }}
                                        <button v-if="save.state === 'error'" type="button" class="underline underline-offset-2 hover:text-foreground" @click="saveDraft">Retry</button>
                                    </template>
                                </span>
                            </div>
                            <div class="space-y-5 p-4">
                                <div v-if="unit.isLeaf" class="space-y-1.5">
                                    <Label for="training-subject" class="text-[13px]">Training subject</Label>
                                    <Input
                                        id="training-subject"
                                        v-model="content.trainingSubject"
                                        :disabled="readOnly"
                                        maxlength="255"
                                        autocomplete="off"
                                        aria-required="true"
                                        :aria-invalid="subjectInvalid"
                                        :aria-describedby="`training-subject-help${subjectInvalid ? ' training-subject-error' : ''}`"
                                        @blur="subjectTouched = true"
                                    />
                                    <p id="training-subject-help" class="text-xs text-muted-foreground">
                                        One line, shown as the report headline. For example: Room clearing, two-man entries.
                                    </p>
                                    <p v-if="subjectInvalid" id="training-subject-error" class="text-xs text-destructive">
                                        Add a training subject. Command uses it to track what each section trained.
                                    </p>
                                </div>
                                <div class="space-y-1.5">
                                    <Label for="report-notes" class="text-[13px]">Notes to send with this report</Label>
                                    <Textarea
                                        id="report-notes"
                                        v-model="content.notes"
                                        :disabled="readOnly"
                                        rows="5"
                                        maxlength="5000"
                                        aria-describedby="report-notes-help"
                                    />
                                    <p id="report-notes-help" class="text-xs text-muted-foreground">
                                        What went well, concerns and follow-ups. Name troopers who stood out. Shown as written.
                                    </p>
                                </div>
                                <div
                                    v-if="!sent"
                                    class="rounded-md border p-3"
                                    :class="staleReview ? 'border-warning/45 bg-warning/6' : 'border-border'"
                                >
                                    <div class="flex items-start gap-2.5 text-[13px]">
                                        <Checkbox id="reviewed" v-model="reviewed" :disabled="readOnly" aria-describedby="reviewed-help" class="mt-0.5" @update:model-value="staleReview = false" />
                                        <div>
                                            <Label for="reviewed" class="text-[13px] font-medium">I have read the preview and my notes as command will see them</Label>
                                            <p id="reviewed-help" class="mt-0.5 text-xs text-muted-foreground">
                                                {{
                                                    staleReview
                                                        ? 'You edited the notes after confirming. Read them once more, then tick again.'
                                                        : 'Editing the notes afterwards clears this, so the check stays honest.'
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="min-w-0 lg:sticky lg:top-[calc(var(--shell-top,3rem)+1rem)]">
                        <ReportPreview
                            :unit="unit"
                            :week="week"
                            :sent="sent"
                            :sent-at="report.sentAt"
                            :sent-by="report.sentBy"
                            :kpis="kpis"
                            :kpi-error="kpiError"
                            :delta="delta"
                            :baseline="baseline"
                            :trend="trend"
                            :highlights="highlightItems"
                            :troopers="troopers"
                            :children="children"
                            :missing-children="missingChildren"
                            :content="content"
                            :reason-labels="reasonLabels"
                            :names="names"
                            :roster-sync="rosterSync"
                            :loa-sync="loaSync"
                            :computed-at="computedAt"
                        />
                    </div>
                </div>

                <div
                    v-if="!sent && !week.beforeFloor"
                    class="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-col items-stretch gap-x-4 gap-y-2 sm:flex-row sm:items-center bg-background/95 px-4 py-3 shadow-[0_-1px_0_var(--border),0_-12px_24px_-18px_hsl(75_20%_10%/0.35)] backdrop-blur sm:-mx-6 sm:px-6"
                    data-testid="send-bar"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <Progress :model-value="((checks.length - openChecks.length) / checks.length) * 100" class="w-16 flex-none" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium" id="send-bar-title">
                                {{
                                    openChecks.length
                                        ? `${openChecks.length} ${openChecks.length === 1 ? 'check' : 'checks'} left before you can send`
                                        : 'Ready to freeze and send'
                                }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                <template v-if="readOnlyNote">{{ readOnlyNote }}.</template>
                                <template v-else-if="openChecks.length">
                                    Next:
                                    <button type="button" class="underline underline-offset-2 hover:text-foreground" @click="jumpToNext">{{ openChecks[0].next }}</button>
                                </template>
                                <template v-else>Goes to {{ parentLabel ?? 'command' }}. The snapshot cannot be edited afterwards.</template>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            v-if="openChecks.length"
                            variant="outline"
                            :disabled="readOnly || !!kpiError"
                            :title="readOnlyNote ?? undefined"
                            data-testid="send-anyway"
                            @click="openSend"
                        >
                            Send anyway
                        </Button>
                        <Button
                            :disabled="readOnly || !!kpiError || openChecks.length > 0"
                            :title="readOnlyNote ?? undefined"
                            aria-describedby="send-bar-title"
                            data-testid="send-button"
                            @click="openSend"
                        >
                            <PhPaperPlaneTilt v-if="!openChecks.length" aria-hidden="true" />
                            <PhLockSimple v-else aria-hidden="true" />
                            Freeze &amp; send
                        </Button>
                    </div>
                </div>
            </TabsContent>

            <TabsContent value="history">
                <HistoryPanel :entries="historyEntries" :reason-labels="reasonLabels" :names="names" :is-leaf="unit.isLeaf" />
            </TabsContent>
        </Tabs>

        <SendDialog
            v-model:open="dialogOpen"
            :unit="unit"
            :kpis="kpis"
            :delta="delta"
            :content="content"
            :open-checks="openItems"
            :missing-units="missingUnits"
            :default-reason="defaultSendReason(openItems, missingUnits)"
            :processing="sending"
            :error="sendError"
            @confirm="confirmSend"
        />
    </div>
</template>
