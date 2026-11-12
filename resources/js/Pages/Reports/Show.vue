<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhCalendarBlank,
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
    PhWarning,
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
import PlatoonTopicField from '@/components/reports/PlatoonTopicField.vue';
import ReadinessChecklist from '@/components/reports/ReadinessChecklist.vue';
import ReportDeadlineBanner from '@/components/reports/ReportDeadlineBanner.vue';
import ReportPreview from '@/components/reports/ReportPreview.vue';
import EditReportDialog from '@/components/reports/EditReportDialog.vue';
import ReportEditRequestPanel from '@/components/reports/ReportEditRequestPanel.vue';
import SendDialog from '@/components/reports/SendDialog.vue';
import StaffReportsPanel from '@/components/reports/StaffReportsPanel.vue';
import TimeAgo from '@/components/TimeAgo.vue';
import Guidon from '@/components/Guidon.vue';
import { weekLabel, weekPhrase } from '@/lib/forumDate';
import WeekHeading from '@/components/week/WeekHeading.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    defaultSendReason,
    frozenHighlights,
    highlights as writeHighlights,
    percent,
    readinessChecks,
    stamp,
    timeOf,
    unaccountedTroopers,
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
    // The platoon's training topic for the week: on a section's draft, its platoon's; on a
    // platoon's screen, the one it set for its sections.
    topic: { type: Object, default: null },
    canSetTopic: { type: Boolean, default: false },
    // Carry-forward: the subject of the section's last sent report, offered on an empty field.
    suggestedSubject: { type: String, default: null },
    // The reason the portal's data gives for a trooper (`{ [trooperId]: reason }`): departed, moved out, or leave that
    // needs review. Pre-selected for an unaccounted trooper with no reason yet, for the leader to keep or change.
    suggestedReasons: { type: Object, default: () => ({}) },
    // A platoon's or company's HQ staff and their own staff reports for the week, read only and
    // live; null for any other unit.
    staffReports: { type: Array, default: null },
    // A section's draft with troopers: whether the week's practice is confirmed (`{ confirmed, held }`), which the send
    // needs (#634). Null when nothing needs confirming.
    practice: { type: Object, default: null },
});

const page = usePage();

// "Sent" here means frozen. A sent report reopened with Edit report (`report.editing`) is composed again like a draft,
// but nothing is saved until it is sent again: its sent version stays the record until then.
const editing = computed(() => !!props.report.editing);
const sent = computed(() => props.report.status === 'sent' && !editing.value);
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
// The training subject follows the platoon's topic: enforced, it is the subject and cannot be
// changed; otherwise it fills an empty field and the leader may change it. The server applies the
// same rule, so an enforced topic holds whatever this page sends.
const sectionTopic = computed(() => (props.unit.isLeaf && !sent.value ? props.topic : null));
const subjectLocked = computed(() => !!sectionTopic.value?.enforced);
const topicFrom = computed(() => unitLabel(sectionTopic.value?.platoon) || 'your platoon');

function initialSubject() {
    if (subjectLocked.value) return sectionTopic.value.subject;

    return props.report.trainingSubject || sectionTopic.value?.subject || '';
}

// A suggested reason is pre-selected but not yet the leader's: it stays out of what is saved, previewed and sent, and
// its check stays open, until they keep it or choose another. So nothing reaches command without them seeing it.
function initialSuggestions() {
    if (!props.unit.isLeaf || readOnly.value) return [];
    const saved = new Set((props.report.unaccounted ?? []).map((r) => r.trooper_id));

    return unaccountedTroopers(props.troopers)
        .filter((t) => !saved.has(t.id) && props.suggestedReasons?.[t.id])
        .map((t) => ({ trooper_id: t.id, reason: props.suggestedReasons[t.id] }));
}

const suggestions = initialSuggestions();
const content = reactive({
    notes: props.report.notes ?? '',
    trainingSubject: initialSubject(),
    unaccounted: [...(props.report.unaccounted ?? []), ...suggestions],
});
const unconfirmed = ref(new Set(suggestions.map((r) => r.trooper_id)));
const suggested = computed(() => Object.fromEntries(content.unaccounted.filter((r) => unconfirmed.value.has(r.trooper_id)).map((r) => [r.trooper_id, r.reason])));
// What the leader has actually chosen: saved, previewed and sent.
const chosen = computed(() => ({
    notes: content.notes,
    trainingSubject: content.trainingSubject,
    unaccounted: content.unaccounted.filter((r) => !unconfirmed.value.has(r.trooper_id)),
}));

function confirmSuggestion(trooperId) {
    if (!unconfirmed.value.has(trooperId)) return;
    const next = new Set(unconfirmed.value);
    next.delete(trooperId);
    unconfirmed.value = next;
}
const reviewed = ref(false);
const staleReview = ref(false);
const subjectTouched = ref(false);

const save = reactive({ state: props.report.savedAt ? 'saved' : 'idle', at: props.report.savedAt });
// A report nobody has saved yet (no row, `status: none`) is "Not started", as on Home; it is a draft from the first save.
const notStarted = computed(() => props.report.status === 'none' && !save.at);
let timer = null;
// Bumped on every edit, so a save that lands can tell whether it carried the latest content.
let revision = 0;

function payload() {
    return { notes: content.notes, training_subject: content.trainingSubject, unaccounted: chosen.value.unaccounted };
}

function saveDraft() {
    clearTimeout(timer);
    timer = null;
    if (readOnly.value || editing.value) return;

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
    toast.error('Could not save the draft', { description });
}

// A 419 is an expired session on a page left open a long time, a 429 the per-minute limit on saves or sends; anything
// else is the server's.
function httpProblem(response, fallback) {
    if (response?.status === 419) return 'Your session expired. Copy your notes, then reload the page to sign in again.';
    if (response?.status === 429) return 'Too many requests in a minute. Your changes are kept here; try again in a moment.';

    return fallback;
}

watch(
    () => [content.notes, content.trainingSubject, JSON.stringify(chosen.value.unaccounted)],
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
        // An edited sent report has no draft to save into: the edits are kept here until it is sent again.
        if (!editing.value) timer = setTimeout(saveDraft, 800);
    },
);

// Unsaved edits are never dropped silently: leaving the page (another week, another screen, or
// closing the tab) while a save is pending or failed asks first.
const unsaved = () => !readOnly.value && ['pending', 'saving', 'error'].includes(save.state);
useUnsavedChanges(unsaved, { message: 'Your latest changes to this report are not saved yet. Leave anyway?' });
onBeforeUnmount(() => clearTimeout(timer));

// ---- Readiness and highlights ----
const checks = computed(() =>
    readinessChecks({
        isLeaf: props.unit.isLeaf,
        troopers: props.troopers,
        children: props.children,
        content: chosen.value,
        reviewed: reviewed.value,
        suggested: Object.keys(suggested.value).map(Number),
        practice: props.practice,
    }),
);
const openChecks = computed(() => checks.value.filter((c) => !c.done));
// A check the server enforces (the practice confirmation, #634): "Send anyway" cannot pass it.
const blocked = computed(() => openChecks.value.some((c) => c.blocking));
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
              reasons: new Map(chosen.value.unaccounted.map((r) => [r.trooper_id, r.reason])),
              reasonLabels: reasonLabels.value,
              children: props.children,
              isLeaf: props.unit.isLeaf,
          }),
);

const noAttendanceYet = computed(
    () => props.unit.isLeaf && !sent.value && (props.troopers ?? []).length > 0 && props.troopers.every((t) => t.status === null && t.rollcall === 'unsigned'),
);

// ---- Send (5.6 via the pre-send overview, 5.5) ----
// Who asked for a make-up on this week's roll call and has none recorded: the send asks, for each, whether they made it up.
const makeupOwed = computed(() =>
    props.unit.isLeaf && !sent.value ? (props.troopers ?? []).filter((t) => t.owesMakeup).map((t) => ({ trooperId: t.id, name: t.name })) : [],
);
const dialogOpen = ref(false);
const sending = ref(false);
const sendError = ref(null);

function openSend() {
    if (readOnly.value || props.kpiError) return;
    sendError.value = null;
    dialogOpen.value = true;
}

// "Send for review" on the roll call (#703) lands here with `?send=1`: the send dialog opens at once, as "Send anyway"
// would open it, so the leader sees exactly what freezes and answers the make-up checks. A report the send bar would
// not open (sent, read-only, practice not confirmed, KPIs unavailable) stays as it is.
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('send') === '1' && !blocked.value) openSend();
});

// The send cleared the pending autosave; when it fails, keep the edits saving as a draft.
function rearm() {
    if (save.state === 'pending' && !timer && !editing.value) timer = setTimeout(saveDraft, 800);
}

function confirmSend(reason, makeupChecks = []) {
    clearTimeout(timer);
    timer = null;
    sending.value = true;
    const again = editing.value;
    router.post(`${baseUrl.value}/send`, { ...payload(), reason, makeup_checks: makeupChecks }, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            save.state = 'saved';
            toast.success(`Report sent${again ? ' again' : ''}${parentLabel.value ? ` to ${parentLabel.value}` : ''}`, {
                description: `Frozen ${stamp(props.report.sentAt)}.`,
            });
            nextTick(() => document.getElementById('sent-title')?.focus());
        },
        onError: (errors) => {
            sendError.value = errors.reason ?? errors.report ?? errors.makeup_checks ?? errors.unaccounted ?? Object.values(errors)[0];
            rearm();
        },
        onHttpException: (response) => {
            sendError.value = httpProblem(response, 'The portal could not send the report. It has not been sent; try again.');
            rearm();

            return false;
        },
        onNetworkError: () => {
            sendError.value = 'Could not reach the portal. The report has not been sent; try again.';
            rearm();

            return false;
        },
        onFinish: () => {
            sending.value = false;
        },
    });
}

// ---- Edit report: reopen a sent report until its deadline, or until an approved request unlocked it to ----
const editOpen = ref(false);
const reopening = ref(false);
const editError = ref(null);

function openEdit() {
    editError.value = null;
    editOpen.value = true;
}

function confirmEdit(reason) {
    reopening.value = true;
    router.post(`${baseUrl.value}/edit`, { reason }, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
            reviewed.value = false;
        },
        onError: (errors) => {
            editError.value = errors.reason ?? errors.report ?? Object.values(errors)[0];
        },
        onHttpException: (response) => {
            editError.value = httpProblem(response, 'The portal could not open the report for editing. Try again.');

            return false;
        },
        onNetworkError: () => {
            editError.value = 'Could not reach the portal. Try again.';

            return false;
        },
        onFinish: () => {
            reopening.value = false;
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
// Until when a sent report can be edited: its deadline, or the later time an approved request to edit it unlocked it to.
const editUntil = computed(() => props.report.editRequest?.editableUntil ?? props.week.due);

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
                  revisions: props.report.revisions ?? [],
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

// Last week's subject, offered (never filled in on its own) while the field is empty.
const suggestion = computed(() =>
    props.unit.isLeaf && !readOnly.value && !subjectLocked.value && props.suggestedSubject && !content.trainingSubject.trim() ? props.suggestedSubject : null,
);

function useSuggestion() {
    content.trainingSubject = suggestion.value;
    subjectTouched.value = true;
}
</script>

<template>
    <Head :title="`${unitLabel(unit)} report, ${weekPhrase(week.start)} · 7Cav Leadership Portal`" />

    <div class="flex flex-col gap-4" data-testid="report-page">
        <section aria-labelledby="page-title" class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Guidon :design="unit.guidon" :height="16" />{{ unitLabel(unit) }} weekly report
                </p>
                <h1 id="page-title" class="flex flex-wrap items-center gap-2.5 text-xl font-semibold tracking-tight sm:text-[22px]">
                    <WeekHeading :week="week.start" />
                    <Badge v-if="sent" variant="success" data-testid="status-badge"><PhLockSimple aria-hidden="true" />Sent</Badge>
                    <Badge v-else-if="editing" variant="warning" data-testid="status-badge">Being edited</Badge>
                    <Badge v-else-if="notStarted" variant="outline" data-testid="status-badge">Not started</Badge>
                    <Badge v-else variant="warning" data-testid="status-badge">Draft</Badge>
                </h1>
            </div>
            <nav class="flex items-center gap-1" aria-label="Choose week">
                <Button variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekUrl(week.previous)" :aria-label="`Previous week, ${weekLabel(week.previous)}`"><PhCaretLeft /></Link>
                </Button>
                <Button v-if="week.next" variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekUrl(week.next)" :aria-label="`Next week, ${weekLabel(week.next)}`"><PhCaretRight /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next week, not open yet"><PhCaretRight /></Button>
            </nav>
            <div class="w-full sm:ml-auto sm:w-auto sm:text-right">
                <p class="text-xs text-muted-foreground">{{ parentLabel ? `Due to ${parentLabel} ${stamp(week.due)}` : `Due ${stamp(week.due)}` }}</p>
                <p v-if="!sent" class="text-lg leading-tight font-semibold tabular-nums" data-testid="countdown">{{ countdown }}</p>
                <p v-else class="text-[13px] font-medium" data-testid="sent-line">
                    Sent <TimeAgo :at="report.sentAt">{{ stamp(report.sentAt) }}</TimeAgo><template v-if="report.sentBy"> by {{ report.sentBy }}</template>
                </p>
            </div>
        </section>

        <ReportDeadlineBanner :due="week.due" :overdue-until="week.overdueUntil" :sent="sent" />

        <div
            v-if="week.owed"
            class="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2 text-[13px]"
            data-testid="owed-week"
        >
            <PhWarning class="size-4 shrink-0 text-warning" aria-hidden="true" />
            <p class="min-w-0 flex-1">
                <span class="font-semibold">The report for {{ weekLabel(week.owed) }} is not sent.</span>
                <span class="text-muted-foreground"> This page sends the report for {{ weekLabel(week.start) }}.</span>
            </p>
            <Link :href="weekUrl(week.owed)" class="font-medium underline underline-offset-2 hover:text-foreground" data-testid="owed-week-link">
                Open {{ weekLabel(week.owed) }}
            </Link>
        </div>

        <!-- The shared mode switch (5.3 AC): this screen creates and sends; the roll-up navigates. -->
        <nav aria-label="Report mode" class="inline-flex w-fit gap-0.5 rounded-md bg-muted p-0.5 text-xs font-medium">
            <span aria-current="page" class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-sm bg-card px-2.5 text-foreground shadow-sm">
                <PhPencilSimpleLine aria-hidden="true" />Create and send
            </span>
            <Link href="/reports" class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-sm px-2.5 text-muted-foreground hover:text-foreground">
                <PhStack aria-hidden="true" />Command roll-up
            </Link>
            <!-- The month's training plan (#380): a platoon's own, or above one, every platoon's beneath. -->
            <Link
                v-if="!unit.isLeaf && !week.beforeFloor"
                :href="`/reports/${unit.id}/plans/${weekDate.slice(0, 7)}`"
                class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-sm px-2.5 text-muted-foreground hover:text-foreground"
                data-testid="training-plan-link"
            >
                <PhCalendarBlank aria-hidden="true" />Training plan
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
                        No roll call signatures or practice marks yet this week. The 30-day rates below include earlier weeks; this
                        week's numbers fill in as roll call is signed and practice is marked.
                    </p>
                </div>

                <div
                    v-if="editing"
                    class="mb-4 flex items-start gap-2.5 rounded-lg border border-warning/35 bg-warning/8 px-4 py-3 text-[13px]"
                    data-testid="editing-banner"
                >
                    <PhPencilSimpleLine class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                    <p>
                        You are editing the report sent {{ stamp(report.sentAt) }}. Until you send it again, the sent version stays the
                        record and your changes are kept on this page only. It locks at {{ stamp(editUntil) }}.
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
                                        Frozen <TimeAgo :at="report.sentAt">{{ stamp(report.sentAt) }}</TimeAgo><template v-if="report.sentBy"> by {{ report.sentBy }}</template>.
                                    </p>
                                </div>
                            </div>
                            <dl class="mt-4 grid grid-cols-[7.5rem_minmax(0,1fr)] gap-y-2 text-[13px]">
                                <template v-if="unit.isLeaf">
                                    <dt class="text-muted-foreground">Trained</dt>
                                    <dd>{{ report.trainingSubject || 'Not filled in' }}</dd>
                                </template>
                                <template v-if="kpis">
                                    <dt class="text-muted-foreground">Key figures</dt>
                                    <dd class="tabular-nums">
                                        RC {{ percentText(kpis.rc) }}, SP {{ percentText(kpis.sp) }}, {{ kpis.atRisk }} at-risk
                                    </dd>
                                </template>
                                <template v-if="missingChildren.length">
                                    <dt class="text-muted-foreground">Sent without</dt>
                                    <dd>{{ missingChildren.length }} {{ missingChildren.length === 1 ? 'unit' : 'units' }} that had not reported</dd>
                                </template>
                            </dl>
                            <div
                                v-if="report.changedChildren?.length"
                                class="mt-4 flex items-start gap-2.5 rounded-md border border-info/30 bg-info/8 px-3 py-2.5 text-[13px]"
                                data-testid="changed-children"
                            >
                                <PhInfo class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                                <p>
                                    {{ report.changedChildren.map(unitLabel).join(', ') }}
                                    {{ report.changedChildren.length === 1 ? 'changed its report' : 'changed their reports' }} after this one was
                                    sent. The numbers here are from before the {{ report.changedChildren.length === 1 ? 'change' : 'changes' }}.
                                </p>
                            </div>
                            <div v-if="report.canEdit" class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2">
                                <Button variant="outline" size="sm" data-testid="edit-report" @click="openEdit">
                                    <PhPencilSimpleLine aria-hidden="true" />Edit report
                                </Button>
                                <p class="text-xs text-muted-foreground">Open until {{ stamp(editUntil) }}.</p>
                            </div>
                            <p v-else class="mt-4 text-xs text-muted-foreground">
                                The snapshot never changes. Later roll call edits stay out of it.
                            </p>
                            <ReportEditRequestPanel v-if="report.editRequest" :edit-request="report.editRequest" :request-url="`${baseUrl}/edit-request`" />
                        </section>

                        <ReadinessChecklist
                            v-else
                            :checks="checks"
                            :troopers="troopers ?? []"
                            :children="children ?? []"
                            :unaccounted="content.unaccounted"
                            :suggested="suggested"
                            :reasons="reasons"
                            :rollcall-url="rollcallUrl"
                            :week-date="weekDate"
                            :read-only="readOnly"
                            @update:unaccounted="content.unaccounted = $event"
                            @confirm="confirmSuggestion"
                            @focus="focusTarget"
                        />

                        <section class="rounded-lg border border-border bg-card" aria-labelledby="notes-title" data-testid="notes-panel">
                            <div class="flex items-center gap-2 border-b border-border px-4 pt-3.5 pb-3">
                                <h2 id="notes-title" class="text-[15px] font-semibold">Leader notes</h2>
                                <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-muted-foreground" aria-live="polite" data-testid="save-state">
                                    <template v-if="sent"><PhLockSimple aria-hidden="true" />Locked with the snapshot</template>
                                    <template v-else-if="readOnlyNote"><PhLockSimple aria-hidden="true" />{{ readOnlyNote }}</template>
                                    <template v-else-if="editing">{{ save.state === 'pending' ? 'Unsaved changes' : 'Saved when you send it again' }}</template>
                                    <template v-else>
                                        <component :is="saveIcon" aria-hidden="true" :class="{ 'text-destructive': save.state === 'error' }" />
                                        <span v-if="save.state === 'saved' && save.at">Draft saved <TimeAgo :at="save.at" quiet>{{ timeOf(save.at) }}</TimeAgo></span>
                                        <template v-else>{{ saveLabel }}</template>
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
                                        :disabled="readOnly || subjectLocked"
                                        maxlength="255"
                                        autocomplete="off"
                                        aria-required="true"
                                        :aria-invalid="subjectInvalid"
                                        :aria-describedby="`training-subject-help${subjectInvalid ? ' training-subject-error' : ''}`"
                                        @blur="subjectTouched = true"
                                    />
                                    <p v-if="subjectLocked" id="training-subject-help" class="flex items-center gap-1.5 text-xs text-muted-foreground" data-testid="topic-note">
                                        <PhLockSimple aria-hidden="true" />Set by {{ topicFrom }} for this week. Your platoon leader locked it.
                                    </p>
                                    <p v-else-if="sectionTopic" id="training-subject-help" class="text-xs text-muted-foreground" data-testid="topic-note">
                                        {{ topicFrom }} set "{{ sectionTopic.subject }}" for this week. You can change it.
                                    </p>
                                    <p v-else id="training-subject-help" class="text-xs text-muted-foreground">
                                        One line, shown as the report headline. For example: Room clearing, two-man entries.
                                    </p>
                                    <p v-if="suggestion" class="text-xs text-muted-foreground" data-testid="subject-suggestion">
                                        Last week: {{ suggestion }}.
                                        <button type="button" class="font-medium text-foreground underline underline-offset-2" @click="useSuggestion">Use it again</button>
                                    </p>
                                    <p v-if="subjectInvalid" id="training-subject-error" class="text-xs text-destructive">
                                        Add a training subject. Command uses it to track what each section trained.
                                    </p>
                                </div>
                                <PlatoonTopicField
                                    v-if="unit.type === 'platoon' && (topic || canSetTopic)"
                                    :topic="topic"
                                    :can-set="canSetTopic"
                                    :read-only="readOnly"
                                    :url="`${baseUrl}/topic`"
                                />
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
                                                        : 'Editing the notes afterward clears this, so the check stays honest.'
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
                            :not-started="notStarted"
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
                            :content="sent ? { ...content, makeupChecks: report.makeupChecks ?? [] } : chosen"
                            :reason-labels="reasonLabels"
                            :names="names"
                            :roster-sync="rosterSync"
                            :loa-sync="loaSync"
                            :computed-at="computedAt"
                        />
                    </div>
                </div>

                <StaffReportsPanel v-if="staffReports" class="mt-5" :rows="staffReports" />

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
                                <template v-else>Goes to {{ parentLabel ?? 'command' }}. You can edit it until {{ stamp(editUntil) }}.</template>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            v-if="openChecks.length"
                            variant="outline"
                            :disabled="readOnly || !!kpiError || blocked"
                            :title="readOnlyNote ?? (blocked ? 'Confirm the practice in roll call first' : undefined)"
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
                            {{ editing ? 'Send again' : 'Freeze and send' }}
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
            :content="chosen"
            :open-checks="openItems"
            :missing-units="missingUnits"
            :makeup-owed="makeupOwed"
            :default-reason="defaultSendReason(openItems, missingUnits)"
            :due="editUntil"
            :again="editing"
            :processing="sending"
            :error="sendError"
            @confirm="confirmSend"
        />
        <EditReportDialog v-model:open="editOpen" :due="editUntil" :processing="reopening" :error="editError" @confirm="confirmEdit" />
    </div>
</template>
