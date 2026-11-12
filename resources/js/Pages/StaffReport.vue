<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    PhBroadcast,
    PhCalendarCheck,
    PhGameController,
    PhCaretDown,
    PhCaretLeft,
    PhCaretRight,
    PhCheck,
    PhCheckCircle,
    PhChalkboardTeacher,
    PhLockSimple,
    PhMedal,
    PhPresentationChart,
    PhMagnifyingGlass,
    PhPencilSimpleLine,
    PhTent,
    PhUserCheck,
    PhUsersThree,
    PhX,
    PhXCircle,
} from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import ReportDeadlineBanner from '@/components/reports/ReportDeadlineBanner.vue';
import { useDueCountdown } from '@/composables/useDueCountdown';
import StaffHqHealth from '@/components/reports/StaffHqHealth.vue';
import StaffReportDiscrepancy from '@/components/reports/StaffReportDiscrepancy.vue';
import StaffReportPresence from '@/components/reports/StaffReportPresence.vue';
import StaffReportRevisions from '@/components/reports/StaffReportRevisions.vue';
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { forumDate, weekLabel, weekPhrase } from '@/lib/forumDate';
import WeekHeading from '@/components/week/WeekHeading.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { onHttpException } from '@/lib/httpError';
import { stamp } from '@/lib/report';
import ReportEditRequestPanel from '@/components/reports/ReportEditRequestPanel.vue';
import OwedWeekNotice from '@/components/reports/OwedWeekNotice.vue';
import { NAMED_TYPES, attendedText, defaultTypes, heldNote, namedSentence, pickerCollapses, pickerGroups, pickerScopeText, presenceAnswer, sheetText, typesPhrase } from '@/lib/staffReport';
import { Checkbox } from '@/components/ui/checkbox';

// A platoon, company or battalion HQ staff member's own weekly staff report: their accountability (platoon and company
// HQ staff: a section practice in their unit or an FTX; battalion HQ staff: a section practice in the battalion, an
// operation, a class or another official event), their presence (a public server hour, an office hour, or neither),
// the other events they attended and their notes. The server says who may send it (the staff member alone, signed in as themselves), which sections
// the picker offers, which entries are already there and what the game server saw; this page only draws its answers
// and posts them. Once sent it reads back as sent; past its deadline it is locked until a leader above unlocks it.
const props = defineProps({
    // `{ id, displayName }`.
    trooper: { type: Object, required: true },
    // Whether the viewer is the staff member themselves.
    self: { type: Boolean, default: false },
    // The platoon, company or battalion HQ they were on when the week ended, `{ id, label, type }`, or null: no report that week.
    hq: { type: Object, default: null },
    // `{ date, start, due, overdueUntil, previous, next, beforeFloor, owed }`. `owed` is the week before, `YYYY-MM-DD`,
    // while the staff member still owes its report (their own page only), else null.
    week: { type: Object, required: true },
    // The report as sent, `{ sentAt, attended, practiceType, ftx: { name, date }, officeHour: { date, note }, presenceType,
    // otherEvents, notes, sheetPushedAt, presence, publicServer, practices: [{ sectionId, section, own }], revisions }`, or null. `revisions` are the versions an
    // edit replaced, newest first.
    report: { type: Object, default: null },
    // Whether the reports Google Sheet is set up: only then is a report not yet copied there called out.
    sheetConfigured: { type: Boolean, default: false },
    canSend: { type: Boolean, default: false },
    // Sent, and the staff member may still send it again with a reason, until it locks at its deadline.
    canEdit: { type: Boolean, default: false },
    // Sent and past its deadline: asking a leader above to unlock it, and their decision (`ReportEditProps`).
    editRequest: { type: Object, default: null },
    // The week's staff practice entries already there, `[{ sectionId, section, enteredBy, selfReported }]`.
    entries: { type: Array, default: () => [] },
    // Section ids whose capture saw them on the game server this week: a hint, never ticked for them.
    seen: { type: Array, default: () => [] },
    // `[{ id, label, own, group, platoon, held }]`: the sections under the HQ, in order of battle. `held` says whether the
    // section's practice that week was held (`held`), or why not (`upcoming`, `unmarked`, `not_held`, `no_slot`).
    sections: { type: Array, default: () => [] },
    // What the HQ reports under its accountability options, `{ types, own, battalion, otherGames }`; null reads as the default.
    accountability: { type: Object, default: null },
    maxPractices: { type: Number, default: 20 },
    // `{ ftxName, otherEvents, notes, officeHourNote }`: the most characters each takes.
    limits: { type: Object, default: () => ({ ftxName: 120, otherEvents: 500, notes: 1000, officeHourNote: 200 }) },
    // For a reader only: where the game server disagrees with the sent report, `{ notSeen, seenElsewhere }`, or null.
    discrepancy: { type: Object, default: null },
    // For a reader only: the HQ's unit health that week ({@see StaffHqHealth.vue}), or null.
    health: { type: Object, default: null },
});

const entered = computed(() => new Map(props.entries.map((entry) => [entry.sectionId, entry])));
const seenIds = computed(() => new Set(props.seen));
const labels = computed(() => new Map([...props.sections.map((section) => [section.id, section.label]), ...props.entries.map((entry) => [entry.sectionId, entry.section])]));
// By default battalion HQ staff report an operation, a class or another official event, platoon and company HQ staff an
// FTX; the HQ's accountability options may say otherwise.
const offered = computed(() => props.accountability?.types ?? defaultTypes(props.hq?.type));

const form = useForm({
    attended: props.entries.length ? true : null,
    section_ids: props.entries.map((entry) => entry.sectionId),
    practice_type: offered.value.includes('sp') ? 'sp' : (offered.value[0] ?? 'sp'),
    ftx_name: '',
    ftx_date: '',
    presence_type: null,
    office_hour_date: '',
    office_hour_note: '',
    other_events: '',
    notes: '',
    reason: '',
});

// Picks made but not sent ask before leaving; sending is a POST, so it never asks.
useUnsavedChanges(() => ((props.canSend && !props.report) || editingReport.value) && form.isDirty);

// Edit report: the form again, filled in with what was sent. Sending it replaces the sent report.
const editingReport = ref(false);
function startEdit() {
    const sent = props.report;
    form.defaults({
        attended: sent.attended,
        section_ids: sent.practices.map((practice) => practice.sectionId),
        // A type the HQ no longer reports (an office hour or FTX sent before) starts over as an SP.
        practice_type: TYPES.value.some((option) => option.value === sent.practiceType) ? sent.practiceType : (TYPES.value[0]?.value ?? 'sp'),
        ftx_name: sent.ftx?.name ?? '',
        ftx_date: sent.ftx?.date ?? '',
        presence_type: sent.presenceType ?? null,
        office_hour_date: sent.officeHour?.date ?? '',
        office_hour_note: sent.officeHour?.note ?? '',
        other_events: sent.otherEvents ?? '',
        notes: sent.notes ?? '',
        reason: '',
    });
    form.reset();
    form.clearErrors();
    notesVisited.value = true;
    step.value = 'attended';
    editingReport.value = true;
}

const ATTENDED = computed(() => [
    { value: true, label: 'Yes', hint: capitalized(typesPhrase(offered.value)), icon: PhCheckCircle },
    { value: false, label: 'No', hint: 'Not this week', icon: PhXCircle },
]);
const TYPES = computed(() =>
    [
        { value: 'sp', label: 'Section practice', hint: 'Pick the sections', icon: PhUsersThree },
        { value: 'ftx', label: 'FTX', hint: 'Its name and date', icon: PhTent },
        { value: 'operation', label: 'Operation', hint: 'Its name and date', icon: PhMedal },
        { value: 'class', label: 'Class', hint: 'Its name and date', icon: PhPresentationChart },
        { value: 'event', label: 'Other official event', hint: 'Its name and date', icon: PhCalendarCheck },
    ].filter((option) => offered.value.includes(option.value)),
);
const capitalized = (text) => text.charAt(0).toUpperCase() + text.slice(1);
const PRESENCE = [
    { value: 'public_server', label: 'Public server hour', hint: 'An hour on the public server', icon: PhGameController },
    { value: 'office_hour', label: 'Office hour', hint: 'An hour in Discord voice', icon: PhChalkboardTeacher },
    { value: 'none', label: 'I did not', hint: 'Not this week', icon: PhXCircle },
];

// The days a named event or an office hour can fall on: the Cav week, Saturday to Friday.
const weekEnd = computed(() => {
    const end = new Date(`${props.week.date}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 6);

    return end.toISOString().slice(0, 10);
});
const inWeek = (date) => date >= props.week.date && date <= weekEnd.value;
const type = computed(() => (form.attended === true ? form.practice_type : null));
// The named type picked (FTX, operation, class, event), or null.
const named = computed(() => (type.value && NAMED_TYPES[type.value] ? NAMED_TYPES[type.value] : null));
const namedLabel = computed(() => TYPES.value.find((option) => option.value === type.value)?.label ?? '');
const sheetLine = computed(() => (props.report ? sheetText(props.report.sheetPushedAt, props.sheetConfigured) : null));

const filter = ref('');
const matching = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle ? props.sections.filter((section) => section.label.toLowerCase().includes(needle)) : props.sections;
});
// Grouped in the order the server sent them, nearest unit first, then by platoon in order of battle.
const groups = computed(() => pickerGroups(matching.value));
// Across a battalion every platoon but the staff member's own starts collapsed; a search opens them all.
const collapses = computed(() => !filter.value.trim() && pickerCollapses(pickerGroups(props.sections)));
const toggled = ref(new Set());
const platoonId = (group, platoon) => `${group.key}|${platoon.key}`;
function platoonOpen(group, platoon) {
    const flipped = toggled.value.has(platoonId(group, platoon));
    return !collapses.value || platoon.own ? !flipped : flipped;
}
function togglePlatoon(group, platoon) {
    const next = new Set(toggled.value);
    const id = platoonId(group, platoon);
    if (!next.delete(id)) next.add(id);
    toggled.value = next;
}
const picked = computed(() => new Set(form.section_ids));
// Entries already there that are no longer picked: sending takes them off.
const dropped = computed(() => props.entries.filter((entry) => type.value !== 'sp' && type.value !== 'ftx' ? true : !picked.value.has(entry.sectionId)));

// A section practice report claims only practices that were held: one that was not is greyed out and cannot be ticked,
// and says why. One ticked already (an entry, or a report sent before) can still be unticked. An FTX's sections name
// who took part, so they are not held back.
const heldById = computed(() => new Map(props.sections.map((section) => [section.id, section.held])));
const blocked = (id) => (type.value === 'sp' ? heldNote(heldById.value.get(id)) : null);

function toggle(id) {
    if (!picked.value.has(id) && blocked(id)) return;
    form.section_ids = picked.value.has(id) ? form.section_ids.filter((sectionId) => sectionId !== id) : [...form.section_ids, id];
}

// What still stops the accountability details, then the presence answer.
const missingDetails = computed(() => {
    if (form.attended !== true) return null;
    if (form.section_ids.length > props.maxPractices && (type.value === 'sp' || type.value === 'ftx')) return `Pick at most ${props.maxPractices} practices.`;
    if (type.value === 'sp' && !form.section_ids.length) return 'Pick the section practice you attended.';
    const notHeld = type.value === 'sp' ? form.section_ids.filter((id) => blocked(id)) : [];
    if (notHeld.length) return `Untick ${notHeld.map((id) => labels.value.get(id) ?? 'a section').join(', ')}: no SP held there this week.`;
    if (named.value) {
        if (!form.ftx_name.trim()) return `Give the name of ${named.value.sentence.replace(/^an? /, 'the ')}.`;
        if (!inWeek(form.ftx_date)) return `Pick the ${named.value.short} date, ${forumDate(props.week.date)} to ${forumDate(weekEnd.value)}.`;
    }

    return null;
});
const missingPresence = computed(() => {
    if (form.presence_type === null) return 'Answer the presence question.';
    if (form.presence_type === 'office_hour') {
        if (!inWeek(form.office_hour_date)) return `Pick the office hour date, ${forumDate(props.week.date)} to ${forumDate(weekEnd.value)}.`;
        if (!form.office_hour_note.trim()) return 'Say in a few words what the office hour was.';
    }

    return null;
});
// What still stops the send, in the order the form asks it: shown beside the Send button.
const missing = computed(() => (form.attended === null ? 'Answer Yes or No.' : (missingDetails.value ?? missingPresence.value)));
const canSubmit = computed(() => !form.processing && missing.value === null && (!editingReport.value || !!form.reason.trim()));

// One question at a time: Yes or No, then (for Yes) what it was and its details, then the optional extras. A step is
// reachable once every step before it is answered; Send stays beside the steps the whole way.
const steps = computed(() => [
    { key: 'attended', label: 'Accountability' },
    ...(form.attended === false
        ? []
        : [
              { key: 'kind', label: 'What' },
              { key: 'details', label: named.value ? namedLabel.value : 'Practices' },
          ]),
    { key: 'presence', label: 'Presence' },
    { key: 'extras', label: 'Notes' },
]);
const step = ref(props.entries.length ? 'details' : 'attended');
// Whether the Notes step was ever open: Send asks first when it was not, so nobody skips it by accident.
const notesVisited = ref(false);
watch(step, (key) => {
    if (key === 'extras') notesVisited.value = true;
}, { immediate: true });
const askingNotes = ref(false);
const stepIndex = computed(() => Math.max(0, steps.value.findIndex((item) => item.key === step.value)));

// What still stops a step, in the words of `missing`.
function missingAt(key) {
    if (key === 'attended') return form.attended === null ? 'Answer Yes or No.' : null;
    if (key === 'details') return missingDetails.value;
    if (key === 'presence') return missingPresence.value;

    return null;
}
const stepMissing = computed(() => missingAt(step.value));
const reachable = (i) => steps.value.slice(0, i).every((item) => missingAt(item.key) === null);

// A step that leaves the list (Details once No is picked) falls back to the last one still there.
watch(steps, (list) => {
    if (!list.some((item) => item.key === step.value)) step.value = list[list.length - 1].key;
});

function next() {
    if (stepMissing.value === null && stepIndex.value < steps.value.length - 1) step.value = steps.value[stepIndex.value + 1].key;
}
function back() {
    if (stepIndex.value > 0) step.value = steps.value[stepIndex.value - 1].key;
}
function answer(value) {
    form.attended = value;
    step.value = value ? 'kind' : 'presence';
}
function pickType(value) {
    form.practice_type = value;
    step.value = 'details';
}
function pickPresence(value) {
    form.presence_type = value;
}

const tile = 'flex min-h-24 flex-col items-start gap-1 rounded-xl border p-4 text-left transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring';
const tileOn = 'border-primary bg-primary/10 text-foreground';
const tileOff = 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:bg-accent/60 hover:text-foreground';

// The answer as it will read once sent.
const summary = computed(() => {
    if (form.attended === null) return 'Not answered yet';

    return attendedText({
        attended: form.attended,
        practiceType: type.value,
        // An operation, a class or an event takes no section practice, as on submit.
        practices: type.value === 'sp' || type.value === 'ftx' ? form.section_ids.map((id) => labels.value.get(id)).filter(Boolean) : [],
        ftx: { name: form.ftx_name.trim(), date: inWeek(form.ftx_date) ? form.ftx_date : null },
    });
});
const presenceSummary = computed(
    () => presenceAnswer({ presenceType: form.presence_type, officeHour: { date: inWeek(form.office_hour_date) ? form.office_hour_date : null } }) || 'Not answered yet',
);

function send() {
    if (!canSubmit.value) return;
    if (!notesVisited.value) {
        askingNotes.value = true;

        return;
    }
    submit();
}
function sendAnyway() {
    askingNotes.value = false;
    submit();
}
function goToNotes() {
    askingNotes.value = false;
    step.value = 'extras';
}

function submit() {
    if (!canSubmit.value) return;
    form.transform((data) => {
        const kind = data.attended ? data.practice_type : null;

        return {
            attended: data.attended,
            // An operation, a class or an event takes no section practice.
            section_ids: kind === 'sp' || kind === 'ftx' ? data.section_ids : [],
            practice_type: kind,
            ftx_name: NAMED_TYPES[kind] ? data.ftx_name : null,
            ftx_date: NAMED_TYPES[kind] ? data.ftx_date : null,
            presence_type: data.presence_type,
            office_hour_date: data.presence_type === 'office_hour' ? data.office_hour_date : null,
            office_hour_note: data.presence_type === 'office_hour' ? data.office_hour_note : null,
            other_events: data.other_events,
            notes: data.notes,
            // Only an edit carries a reason: a first send posts the fields it always did.
            ...(editingReport.value ? { reason: data.reason } : {}),
        };
    }).post(`/staff-report/${props.trooper.id}/${props.week.date}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(editingReport.value ? 'Staff report sent again' : 'Staff report sent');
            editingReport.value = false;
        },
        onHttpException: onHttpException('The staff report was not sent', (message) => toast.error(message)),
    });
}

const weekUrl = (date) => `/staff-report/${props.trooper.id}/${date}`;
// The deadline banner already says overdue when it shows, so the Due line only does without it.
const dueNote = useDueCountdown(() => props.week.due, { sent: () => !!props.report, showOverdue: () => !props.canSend });

const hqLabel = computed(() => (props.hq ? `${props.hq.label} HQ` : null));

function entryNote(id) {
    const entry = entered.value.get(id);
    if (!entry) return null;

    return entry.selfReported ? 'Already entered by you' : `Entered by ${entry.enteredBy ?? 'a user no longer in the portal'}`;
}

</script>

<template>
    <Head :title="`Staff report, ${weekPhrase(week.start)} · 7Cav Leadership Portal`" />

    <div class="flex flex-col gap-4" :class="(canSend && !report) || editingReport ? 'max-w-5xl' : 'max-w-3xl'" data-testid="staff-report-page">
        <section aria-labelledby="page-title" class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground" data-testid="staff-report-who">
                    Staff report for {{ trooper.displayName }}<template v-if="hqLabel">, {{ hqLabel }}</template>
                </p>
                <h1 id="page-title" class="flex flex-wrap items-center gap-2.5 text-xl font-semibold tracking-tight sm:text-[22px]">
                    <WeekHeading :week="week.start" />
                    <Badge v-if="report" variant="success" data-testid="status-badge"><PhLockSimple aria-hidden="true" />Sent</Badge>
                    <Badge v-else-if="hq" variant="warning" data-testid="status-badge">Not sent</Badge>
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
            <div v-if="hq" class="w-full sm:ml-auto sm:w-auto sm:text-right">
                <p v-if="report" class="text-[13px] font-medium" data-testid="sent-line">Sent {{ stamp(report.sentAt) }}</p>
                <p v-else class="text-[13px]" data-testid="due-line">
                    <span class="text-muted-foreground">Due</span> <span class="font-medium tabular-nums">{{ stamp(week.due) }}</span>
                    <span v-if="dueNote" class="text-muted-foreground" data-testid="due-countdown"> ({{ dueNote }})</span>
                </p>
                <p class="text-xs text-muted-foreground">{{ forumDate(week.date) }} to {{ forumDate(weekEnd) }}</p>
            </div>
        </section>

        <OwedWeekNotice v-if="week.owed" :owed="week.owed" :href="weekUrl(week.owed)">
            <template v-if="canSend && !report">This page sends the report for {{ weekLabel(week.start) }}.</template>
        </OwedWeekNotice>

        <ReportDeadlineBanner v-if="canSend" :due="week.due" :overdue-until="week.overdueUntil" :sent="!!report" />

        <Card v-if="!hq" class="py-10" data-testid="staff-report-none">
            <EmptyState :icon="PhUserCheck" title="No staff report for this week">
                {{ self ? 'You were' : `${trooper.displayName} was` }} not on a platoon, company or battalion HQ when this week ended, so there is nothing to send.
            </EmptyState>
        </Card>

        <!-- Sent: read back as it was sent. -->
        <Card v-else-if="report && !editingReport" class="gap-0 py-0 text-[13px]" data-testid="staff-report-sent">
            <div class="flex items-start gap-3 border-b border-border px-4 py-3.5">
                <PhCheckCircle class="mt-0.5 size-5 shrink-0 text-success" weight="fill" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <span data-testid="staff-report-answer">
                            <template v-if="namedSentence(report)">{{ namedSentence(report) }}</template>
                            <template v-else-if="report.attended && report.practiceType === 'office_hour'">
                                Held an office hour<template v-if="report.officeHour?.date"> on {{ forumDate(report.officeHour.date) }}</template>.
                            </template>
                            <template v-else-if="report.attended">
                                Attended {{ report.practices.length }} {{ report.practices.length === 1 ? 'section practice' : 'section practices' }}.
                            </template>
                            <template v-else>Did not attend a section practice.</template>
                        </span>
                        <StaffReportDiscrepancy :discrepancy="discrepancy" />
                        <StaffReportPresence :presence="report.presence" />
                    </h2>
                    <p v-if="report.presenceType" class="mt-0.5 flex flex-wrap items-center gap-2" data-testid="staff-report-presence-answer">
                        Presence: {{ presenceAnswer(report) }}
                        <StaffReportPresence :presence="report.publicServer" :name="trooper.displayName" testid="staff-report-public-server" />
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Sent {{ stamp(report.sentAt) }}.
                        <span v-if="sheetLine" data-testid="staff-report-sheet">{{ sheetLine }}.</span>
                    </p>
                </div>
                <div v-if="canEdit" class="flex flex-none flex-col items-end gap-1">
                    <Button variant="outline" size="sm" data-testid="staff-report-edit" @click="startEdit"><PhPencilSimpleLine aria-hidden="true" />Edit report</Button>
                    <span class="text-xs text-muted-foreground">Open until {{ stamp(editRequest?.editableUntil ?? week.due) }}</span>
                </div>
            </div>
            <div v-if="editRequest" class="px-4 pb-3 empty:hidden">
                <ReportEditRequestPanel :edit-request="editRequest" :request-url="`${weekUrl(week.date)}/edit-request`" />
            </div>
            <p v-if="report.officeHour?.note" class="border-b border-border px-4 py-3 whitespace-pre-line" data-testid="staff-report-office-hour">
                {{ report.officeHour.note }}
            </p>
            <ul v-if="report.practices.length" class="divide-y divide-border" data-testid="staff-report-practices">
                <li v-for="practice in report.practices" :key="practice.sectionId" class="flex items-center gap-2.5 px-4 py-2.5">
                    <span class="font-mono">{{ practice.section ?? 'A section since removed' }}</span>
                    <Badge variant="outline" class="ml-auto font-normal">{{ practice.own ? 'Staff guest' : 'Guest' }}</Badge>
                </li>
            </ul>
            <dl v-if="report.otherEvents || report.notes" class="grid gap-3 border-t border-border px-4 py-3">
                <div v-if="report.otherEvents">
                    <dt class="text-xs font-medium text-muted-foreground">Other events attended</dt>
                    <dd class="whitespace-pre-line" data-testid="staff-report-other-events">{{ report.otherEvents }}</dd>
                </div>
                <div v-if="report.notes">
                    <dt class="text-xs font-medium text-muted-foreground">Notes</dt>
                    <dd class="whitespace-pre-line" data-testid="staff-report-notes">{{ report.notes }}</dd>
                </div>
            </dl>
            <StaffReportRevisions v-if="report.revisions?.length" :revisions="report.revisions" />
        </Card>

        <!-- Not sent, and this viewer may send it: one question at a time, with the answer so far beside it. -->
        <form v-else-if="canSend || editingReport" class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_17rem]" data-testid="staff-report-form" @submit.prevent="send">
            <!-- Send asks first when the Notes step was never opened. -->
            <AlertDialog :open="askingNotes" @update:open="(open) => (askingNotes = open)">
                <AlertDialogContent class="sm:max-w-md" data-testid="notes-dialog">
                    <AlertDialogTitle class="text-base leading-snug font-semibold">Send without notes?</AlertDialogTitle>
                    <AlertDialogDescription class="text-[13px] text-muted-foreground">
                        You have not opened the Notes step. Send now if there is nothing else command should know about your week.
                    </AlertDialogDescription>
                    <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                        <AlertDialogCancel class="mt-0" data-testid="notes-dialog-go" @click="goToNotes">Go to Notes</AlertDialogCancel>
                        <Button type="button" :disabled="!canSubmit" data-testid="notes-dialog-send" @click="sendAnyway">Send anyway</Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <Card class="gap-0 py-0 text-[13px]">
                <ol class="flex items-center gap-1 border-b px-3 py-2.5" aria-label="Steps" data-testid="stepper">
                    <li v-for="(item, i) in steps" :key="item.key" class="flex min-w-0 flex-1 items-center gap-1">
                        <button
                            type="button"
                            class="flex min-w-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-xs font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default"
                            :class="item.key === step ? 'text-foreground' : reachable(i) ? 'text-muted-foreground hover:text-foreground' : 'text-muted-foreground/60'"
                            :aria-current="item.key === step ? 'step' : undefined"
                            :disabled="!reachable(i)"
                            :data-testid="`step-${item.key}`"
                            @click="step = item.key"
                        >
                            <span
                                class="grid size-5 shrink-0 place-items-center rounded-full text-[11px] tabular-nums"
                                :class="item.key === step ? 'bg-primary text-primary-foreground' : i < stepIndex ? 'bg-primary/15 text-foreground' : 'bg-muted'"
                            >
                                <PhCheck v-if="i < stepIndex" class="size-3" aria-hidden="true" />
                                <template v-else>{{ i + 1 }}</template>
                            </span>
                            <span class="hidden truncate sm:inline">{{ item.label }}</span>
                        </button>
                        <span v-if="i < steps.length - 1" class="h-px flex-1 bg-border" aria-hidden="true" />
                    </li>
                </ol>

                <div class="min-h-[18rem] px-4 py-4">
                    <!-- 1. Yes or No. -->
                    <fieldset v-if="step === 'attended'" data-testid="step-panel-attended">
                        <legend class="text-base font-semibold">
                            Did you attend {{ typesPhrase(offered, true) }} this week?
                        </legend>
                        <div class="mt-4 grid grid-cols-2 gap-3" role="radiogroup" aria-label="Accountability">
                            <button
                                v-for="choice in ATTENDED"
                                :key="String(choice.value)"
                                type="button"
                                role="radio"
                                :aria-checked="form.attended === choice.value"
                                :class="[tile, form.attended === choice.value ? tileOn : tileOff]"
                                :data-testid="choice.value ? 'attended-yes' : 'attended-no'"
                                @click="answer(choice.value)"
                            >
                                <component :is="choice.icon" class="size-6" :class="form.attended === choice.value ? 'text-primary' : ''" aria-hidden="true" />
                                <span class="text-base font-semibold">{{ choice.label }}</span>
                                <span class="text-xs font-normal text-muted-foreground">{{ choice.hint }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.attended" class="mt-1.5 text-xs text-destructive" data-testid="error-attended">{{ form.errors.attended }}</p>
                    </fieldset>

                    <!-- 2. What it was. -->
                    <fieldset v-else-if="step === 'kind'" data-testid="practice-type">
                        <legend class="text-base font-semibold">What was it?</legend>
                        <div class="mt-4 grid gap-3" :class="TYPES.length >= 4 ? 'sm:grid-cols-4' : TYPES.length === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'" role="radiogroup" aria-label="What was it">
                            <button
                                v-for="option in TYPES"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.practice_type === option.value"
                                :class="[tile, form.practice_type === option.value ? tileOn : tileOff]"
                                :data-testid="`type-${option.value}`"
                                @click="pickType(option.value)"
                            >
                                <component :is="option.icon" class="size-6" :class="form.practice_type === option.value ? 'text-primary' : ''" aria-hidden="true" />
                                <span class="text-sm font-semibold">{{ option.label }}</span>
                                <span class="text-xs font-normal text-muted-foreground">{{ option.hint }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.practice_type" class="mt-1.5 text-xs text-destructive" data-testid="error-practice-type">{{ form.errors.practice_type }}</p>
                    </fieldset>

                    <!-- 3. The details of that kind. -->
                    <div v-else-if="step === 'details'" class="flex flex-col gap-4" data-testid="step-panel-details">
                        <div v-if="named" class="grid gap-3 sm:grid-cols-[1fr_auto]" data-testid="ftx-fields">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium">{{ type === 'ftx' ? 'Name of the FTX' : 'Name' }}</span>
                                <Input v-model="form.ftx_name" :maxlength="limits.ftxName" autocomplete="off" data-testid="ftx-name" />
                                <span v-if="form.errors.ftx_name" class="text-xs text-destructive" data-testid="error-ftx-name">{{ form.errors.ftx_name }}</span>
                            </label>
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium">Date</span>
                                <Input v-model="form.ftx_date" type="date" :min="week.date" :max="weekEnd" data-testid="ftx-date" />
                                <span v-if="form.errors.ftx_date" class="text-xs text-destructive" data-testid="error-ftx-date">{{ form.errors.ftx_date }}</span>
                            </label>
                        </div>

                        <div v-if="type === 'sp' || type === 'ftx'" data-testid="practice-picker">
                            <p class="text-base font-semibold">{{ type === 'ftx' ? 'Sections that took part (optional)' : 'Which practices did you attend?' }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ pickerScopeText(hq?.type, accountability) }} You are on their roll call as a staff guest.
                            </p>

                            <div class="relative mt-3">
                                <PhMagnifyingGlass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                                <Input v-model="filter" class="pl-8" autocomplete="off" placeholder="Find a section" aria-label="Find a section" data-testid="section-filter" />
                            </div>

                            <div class="mt-2 max-h-[min(32rem,60vh)] overflow-y-auto pr-1">
                                <div v-for="group in groups" :key="group.key" class="mb-2" :data-testid="`sections-${group.key || 'others'}`">
                                    <p class="sticky top-0 z-10 bg-card py-1 text-xs font-medium text-muted-foreground">{{ group.label }}</p>
                                    <div v-for="platoon in group.platoons" :key="platoon.key" class="mb-1.5" :data-testid="`platoon-${platoon.key || 'none'}`">
                                        <button
                                            v-if="platoon.label && (group.platoons.length > 1 || platoon.key !== group.key)"
                                            type="button"
                                            class="flex min-h-8 w-full items-center gap-1.5 rounded-md px-1 text-left text-xs font-medium text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-expanded="platoonOpen(group, platoon)"
                                            :aria-controls="`platoon-rows-${group.key || 'others'}-${platoon.key}`"
                                            :data-testid="`platoon-toggle-${platoon.key}`"
                                            @click="togglePlatoon(group, platoon)"
                                        >
                                            <PhCaretDown class="size-3.5 transition-transform" :class="platoonOpen(group, platoon) ? '' : '-rotate-90'" aria-hidden="true" />
                                            <span class="font-mono">{{ platoon.label }}</span>
                                            <span class="font-normal">({{ platoon.rows.length }})</span>
                                        </button>
                                        <ul
                                            v-show="platoonOpen(group, platoon)"
                                            :id="`platoon-rows-${group.key || 'others'}-${platoon.key}`"
                                            class="grid gap-1.5 sm:grid-cols-2"
                                        >
                                            <li v-for="section in platoon.rows" :key="section.id">
                                                <label
                                                    :for="`staff-section-${section.id}`"
                                                    class="flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2 transition-colors has-[button:focus-visible]:ring-2 has-[button:focus-visible]:ring-ring has-[button:focus-visible]:ring-inset"
                                                    :class="[
                                                        picked.has(section.id) ? 'border-primary bg-primary/10' : 'border-border',
                                                        blocked(section.id) && !picked.has(section.id) ? 'cursor-not-allowed bg-muted/40 text-muted-foreground' : 'cursor-pointer',
                                                        !blocked(section.id) && !picked.has(section.id) ? 'hover:bg-accent/70' : '',
                                                    ]"
                                                    :title="blocked(section.id)?.long"
                                                    :data-held="section.held ?? 'held'"
                                                    :data-testid="`section-row-${section.id}`"
                                                >
                                                    <Checkbox
                                                        :id="`staff-section-${section.id}`"
                                                        class="shrink-0 focus-visible:ring-0"
                                                        :model-value="picked.has(section.id)"
                                                        :disabled="!!blocked(section.id) && !picked.has(section.id)"
                                                        :aria-describedby="blocked(section.id) ? `held-${section.id}` : undefined"
                                                        :data-testid="`section-${section.id}`"
                                                        @update:model-value="toggle(section.id)"
                                                    />
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block font-mono">{{ section.label }}</span>
                                                        <span v-if="blocked(section.id)" :id="`held-${section.id}`" class="block text-xs" :class="picked.has(section.id) ? 'text-warning' : 'text-muted-foreground'" :data-testid="`held-${section.id}`">
                                                            {{ blocked(section.id).short }}<span class="sr-only">. {{ blocked(section.id).long }}</span>
                                                        </span>
                                                        <span v-if="entryNote(section.id)" class="block text-xs text-muted-foreground" :data-testid="`entered-${section.id}`">{{ entryNote(section.id) }}</span>
                                                    </span>
                                                    <Badge v-if="seenIds.has(section.id)" variant="outline" class="font-normal" :data-testid="`seen-${section.id}`">
                                                        <PhBroadcast aria-hidden="true" />Seen on the game server
                                                    </Badge>
                                                </label>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <p v-if="!matching.length" class="py-3 text-xs text-muted-foreground" data-testid="sections-empty">
                                    <template v-if="filter.trim()">No section matches "{{ filter.trim() }}".</template>
                                    <template v-else>No section plays your HQ's game.</template>
                                </p>
                            </div>
                            <p v-if="seen.length" class="mt-2 text-xs text-muted-foreground">
                                Seen on the game server means a capture saw you on the server that week. It is not picked for you.
                            </p>
                            <p v-if="form.errors.section_ids" class="mt-1.5 text-xs text-destructive" data-testid="error-sections">{{ form.errors.section_ids }}</p>
                        </div>
                    </div>

                    <!-- 4. Presence: a public server hour, an office hour, or neither. -->
                    <fieldset v-else-if="step === 'presence'" data-testid="step-panel-presence">
                        <legend class="text-base font-semibold">Did you spend an hour on the public server or hold an office hour this week?</legend>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Presence">
                            <button
                                v-for="option in PRESENCE"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.presence_type === option.value"
                                :class="[tile, form.presence_type === option.value ? tileOn : tileOff]"
                                :data-testid="`presence-${option.value}`"
                                @click="pickPresence(option.value)"
                            >
                                <component :is="option.icon" class="size-6" :class="form.presence_type === option.value ? 'text-primary' : ''" aria-hidden="true" />
                                <span class="text-sm font-semibold">{{ option.label }}</span>
                                <span class="text-xs font-normal text-muted-foreground">{{ option.hint }}</span>
                            </button>
                        </div>
                        <div v-if="form.presence_type === 'office_hour'" class="mt-4 grid gap-3" data-testid="office-hour-fields">
                            <label class="flex flex-col gap-1 sm:max-w-48">
                                <span class="text-xs font-medium">Date</span>
                                <Input v-model="form.office_hour_date" type="date" :min="week.date" :max="weekEnd" data-testid="office-hour-date" />
                                <span v-if="form.errors.office_hour_date" class="text-xs text-destructive" data-testid="error-office-hour-date">{{ form.errors.office_hour_date }}</span>
                            </label>
                            <label class="flex flex-col gap-1">
                                <span class="flex items-baseline text-xs font-medium">
                                    What was it?
                                    <span class="ml-auto font-normal text-muted-foreground tabular-nums">{{ form.office_hour_note.length }}/{{ limits.officeHourNote }}</span>
                                </span>
                                <Input v-model="form.office_hour_note" :maxlength="limits.officeHourNote" autocomplete="off" data-testid="office-hour-note" />
                                <span v-if="form.errors.office_hour_note" class="text-xs text-destructive" data-testid="error-office-hour-note">{{ form.errors.office_hour_note }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.presence_type" class="mt-1.5 text-xs text-destructive" data-testid="error-presence-type">{{ form.errors.presence_type }}</p>
                    </fieldset>

                    <!-- 5. Notes and other events, optional. -->
                    <div v-else class="flex flex-col gap-4" data-testid="step-panel-extras">
                        <p class="text-base font-semibold">Notes <span class="text-sm font-normal text-muted-foreground">(optional)</span></p>
                        <p class="-mt-3 text-xs text-muted-foreground">Anything command should know about your week, and any other events you attended.</p>
                        <label class="flex flex-col gap-1" data-testid="other-events-section">
                            <span class="text-xs font-medium">Other events attended</span>
                            <Textarea v-model="form.other_events" :maxlength="limits.otherEvents" rows="2" data-testid="other-events" />
                            <span v-if="form.errors.other_events" class="text-xs text-destructive" data-testid="error-other-events">{{ form.errors.other_events }}</span>
                        </label>
                        <label class="flex flex-col gap-1" data-testid="notes-section">
                            <span class="text-xs font-medium">Notes</span>
                            <Textarea v-model="form.notes" :maxlength="limits.notes" rows="3" data-testid="notes" />
                            <span v-if="form.errors.notes" class="text-xs text-destructive" data-testid="error-notes">{{ form.errors.notes }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center gap-2 border-t px-4 py-3">
                    <Button v-if="stepIndex > 0" type="button" variant="ghost" size="sm" data-testid="step-back" @click="back">
                        <PhCaretLeft aria-hidden="true" />Back
                    </Button>
                    <p v-if="stepMissing" class="ml-auto text-xs text-muted-foreground" data-testid="step-missing">{{ stepMissing }}</p>
                    <Button v-if="stepIndex < steps.length - 1" type="button" size="sm" :class="stepMissing ? '' : 'ml-auto'" :disabled="!!stepMissing" data-testid="step-next" @click="next">
                        Next<PhCaretRight aria-hidden="true" />
                    </Button>
                </div>
            </Card>

            <!-- The answer as it will be sent, and Send: beside the steps on a wide screen, under them on a phone. -->
            <Card class="gap-0 py-0 text-[13px] lg:sticky lg:top-4" data-testid="send-bar">
                <h2 class="border-b px-4 py-2.5 text-sm font-semibold">Your report</h2>
                <dl class="grid gap-2.5 px-4 py-3">
                    <div>
                        <dt class="text-xs text-muted-foreground">Accountability</dt>
                        <dd class="font-medium" data-testid="send-summary">{{ summary }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Presence</dt>
                        <dd class="font-medium" data-testid="send-presence">{{ presenceSummary }}</dd>
                    </div>
                    <div v-if="form.attended === true && (type === 'sp' || type === 'ftx') && form.section_ids.length">
                        <dt class="text-xs text-muted-foreground">{{ type === 'ftx' ? 'Sections' : 'Practices' }}</dt>
                        <dd>
                            <ul class="mt-1 flex flex-wrap gap-1.5" aria-label="Picked sections" data-testid="picked-chips">
                                <li v-for="id in form.section_ids" :key="id">
                                    <span class="inline-flex items-center gap-1 rounded-md border border-primary/40 bg-primary/10 py-0.5 pr-0.5 pl-2 font-mono text-xs">
                                        {{ labels.get(id) ?? 'A section' }}
                                        <button
                                            type="button"
                                            class="grid size-5 place-items-center rounded-sm text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                            :aria-label="`Remove ${labels.get(id) ?? 'this section'}`"
                                            :data-testid="`chip-remove-${id}`"
                                            @click="toggle(id)"
                                        >
                                            <PhX class="size-3" aria-hidden="true" />
                                        </button>
                                    </span>
                                </li>
                            </ul>
                        </dd>
                    </div>
                    <div v-if="form.other_events.trim() || form.notes.trim()">
                        <dt class="text-xs text-muted-foreground">Also</dt>
                        <dd data-testid="send-extras">{{ [form.other_events.trim() && 'Other events', form.notes.trim() && 'Notes'].filter(Boolean).join(', ') }}</dd>
                    </div>
                </dl>
                <div class="border-t px-4 py-3">
                    <p v-if="missing" class="mb-2 text-xs text-muted-foreground" data-testid="send-missing">{{ missing }}</p>
                    <p v-else-if="editingReport && !form.reason.trim()" class="mb-2 text-xs text-muted-foreground">Give a reason for the record.</p>
                    <p v-else class="mb-2 text-xs text-muted-foreground">You can edit it until {{ stamp(week.due) }}.</p>
                    <div v-if="editingReport" class="mb-3 space-y-1.5">
                        <label for="edit-reason" class="text-xs font-medium">Reason for the record</label>
                        <Textarea id="edit-reason" v-model="form.reason" rows="2" maxlength="1000" data-testid="staff-report-reason" />
                        <p v-if="form.errors.reason" role="alert" class="text-xs text-destructive">{{ form.errors.reason }}</p>
                    </div>
                    <Button type="submit" class="w-full" :disabled="!canSubmit" data-testid="staff-report-send">{{ editingReport ? 'Send again' : 'Send staff report' }}</Button>
                    <Button v-if="editingReport" type="button" variant="ghost" class="mt-2 w-full" :disabled="form.processing" data-testid="staff-report-cancel-edit" @click="editingReport = false">Cancel</Button>
                    <p v-if="dropped.length && form.attended !== null" class="mt-2 text-xs text-warning" data-testid="dropped-note">
                        Sending takes off the {{ dropped.length === 1 ? 'entry' : 'entries' }} you left out:
                        {{ dropped.map((entry) => entry.section).join(', ') }}.
                    </p>
                    <p v-if="form.errors.report" role="alert" class="mt-2 text-xs text-destructive" data-testid="error-report">{{ form.errors.report }}</p>
                </div>
            </Card>
        </form>

        <!-- Not sent, and this viewer only reads it, or the week can no longer be sent. -->
        <Card v-else class="py-10" data-testid="staff-report-unsent">
            <EmptyState :icon="PhUserCheck" title="Not sent yet">
                <template v-if="week.beforeFloor">This week is too far back to send.</template>
                <template v-else-if="self">You can no longer send this staff report.</template>
                <template v-else>Only {{ trooper.displayName }} can send their staff report.</template>
            </EmptyState>
        </Card>

        <StaffHqHealth v-if="health" :health="health" />
    </div>
</template>
