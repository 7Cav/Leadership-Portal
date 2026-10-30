<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { PhBroadcast, PhCaretLeft, PhCaretRight, PhCheckCircle, PhLockSimple, PhMagnifyingGlass, PhUserCheck, PhX } from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import ReportDeadlineBanner from '@/components/reports/ReportDeadlineBanner.vue';
import StaffReportDiscrepancy from '@/components/reports/StaffReportDiscrepancy.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { stamp } from '@/lib/report';
import { attendedText, sheetText } from '@/lib/staffReport';

// A platoon, company or battalion HQ staff member's own weekly staff report: whether they attended a section
// practice this week, an FTX or (battalion HQ staff only) an office hour, which, with the other events they attended
// and their notes. The server says who may send it (the staff member alone, signed in as themselves), which sections
// the picker offers, which entries are already there and what the game server saw; this page only draws its answers
// and posts them. Once sent it is final and reads back as sent.
const props = defineProps({
    // `{ id, displayName }`.
    trooper: { type: Object, required: true },
    // Whether the viewer is the staff member themselves.
    self: { type: Boolean, default: false },
    // The platoon, company or battalion HQ they were on when the week ended, `{ id, label, type }`, or null: no report that week.
    hq: { type: Object, default: null },
    // `{ date, start, due, overdueUntil, previous, next, beforeFloor, ended, opensAt }`.
    week: { type: Object, required: true },
    // The report as sent, `{ sentAt, attended, practiceType, ftx: { name, date }, officeHour: { date, note }, otherEvents,
    // notes, sheetPushedAt, practices: [{ sectionId, section, own }] }`, or null.
    report: { type: Object, default: null },
    // Whether the reports Google Sheet is set up: only then is a report not yet copied there called out.
    sheetConfigured: { type: Boolean, default: false },
    canSend: { type: Boolean, default: false },
    // The week's staff practice entries already there, `[{ sectionId, section, enteredBy, selfReported }]`.
    entries: { type: Array, default: () => [] },
    // Section ids whose capture saw them on the game server this week: a hint, never ticked for them.
    seen: { type: Array, default: () => [] },
    // `[{ id, label, own, group }]`, nearest first: `group` is the HQ, company or battalion holding it, or null.
    sections: { type: Array, default: () => [] },
    maxPractices: { type: Number, default: 20 },
    // `{ ftxName, otherEvents, notes, officeHourNote }`: the most characters each takes.
    limits: { type: Object, default: () => ({ ftxName: 120, otherEvents: 500, notes: 1000, officeHourNote: 200 }) },
    // For a reader only: where the game server disagrees with the sent report, `{ notSeen, seenElsewhere }`, or null.
    discrepancy: { type: Object, default: null },
});

const entered = computed(() => new Map(props.entries.map((entry) => [entry.sectionId, entry])));
const seenIds = computed(() => new Set(props.seen));
const labels = computed(() => new Map([...props.sections.map((section) => [section.id, section.label]), ...props.entries.map((entry) => [entry.sectionId, entry.section])]));
// An office hour counts in place of the hour of play for battalion HQ staff only.
const officeHourOffered = computed(() => props.hq?.type === 'battalion');

const form = useForm({
    attended: props.entries.length ? true : null,
    section_ids: props.entries.map((entry) => entry.sectionId),
    practice_type: 'sp',
    ftx_name: '',
    ftx_date: '',
    office_hour_date: '',
    office_hour_note: '',
    other_events: '',
    notes: '',
});

const TYPES = computed(() => [
    { value: 'sp', label: 'Section practice' },
    { value: 'ftx', label: 'FTX' },
    ...(officeHourOffered.value ? [{ value: 'office_hour', label: 'Office hour' }] : []),
]);

// The days an FTX or an office hour can fall on: the Cav week, Saturday to Friday.
const weekEnd = computed(() => {
    const end = new Date(`${props.week.date}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 6);

    return end.toISOString().slice(0, 10);
});
const inWeek = (date) => date >= props.week.date && date <= weekEnd.value;
const type = computed(() => (form.attended === true ? form.practice_type : null));
const sheetLine = computed(() => (props.report ? sheetText(props.report.sheetPushedAt, props.sheetConfigured) : null));

const filter = ref('');
const matching = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle ? props.sections.filter((section) => section.label.toLowerCase().includes(needle)) : props.sections;
});
// Grouped in the order the server sent them, nearest unit first.
const groups = computed(() => {
    const out = [];
    for (const section of matching.value) {
        const key = section.group ?? '';
        let group = out.find((candidate) => candidate.key === key);
        if (!group) {
            group = { key, label: section.group ? `In ${section.group}` : 'Other sections', rows: [] };
            out.push(group);
        }
        group.rows.push(section);
    }

    return out;
});
const picked = computed(() => new Set(form.section_ids));
// Entries already there that are no longer picked: sending takes them off.
const dropped = computed(() => props.entries.filter((entry) => type.value !== 'sp' && type.value !== 'ftx' ? true : !picked.value.has(entry.sectionId)));

function toggle(id) {
    form.section_ids = picked.value.has(id) ? form.section_ids.filter((sectionId) => sectionId !== id) : [...form.section_ids, id];
}

// What still stops the send, in the order the form asks it: shown beside the Send button.
const missing = computed(() => {
    if (form.attended === null) return 'Answer Yes or No.';
    if (form.attended === false) return null;
    if (form.section_ids.length > props.maxPractices && type.value !== 'office_hour') return `Pick at most ${props.maxPractices} practices.`;
    if (type.value === 'sp' && !form.section_ids.length) return 'Pick the section practice you attended.';
    if (type.value === 'ftx') {
        if (!form.ftx_name.trim()) return 'Give the name of the FTX.';
        if (!inWeek(form.ftx_date)) return `Pick the FTX date, ${forumDate(props.week.date)} to ${forumDate(weekEnd.value)}.`;
    }
    if (type.value === 'office_hour') {
        if (!inWeek(form.office_hour_date)) return `Pick the office hour date, ${forumDate(props.week.date)} to ${forumDate(weekEnd.value)}.`;
        if (!form.office_hour_note.trim()) return 'Say in a few words what the office hour was.';
    }

    return null;
});
const canSubmit = computed(() => !form.processing && missing.value === null);

// The answer as it will read once sent.
const summary = computed(() => {
    if (form.attended === null) return 'Not answered yet';

    return attendedText({
        attended: form.attended,
        practiceType: type.value,
        practices: form.section_ids.map((id) => labels.value.get(id)).filter(Boolean),
        ftx: { name: form.ftx_name.trim(), date: inWeek(form.ftx_date) ? form.ftx_date : null },
        officeHour: { date: inWeek(form.office_hour_date) ? form.office_hour_date : null },
    });
});

function send() {
    if (!canSubmit.value) return;
    form.transform((data) => {
        const kind = data.attended ? data.practice_type : null;

        return {
            attended: data.attended,
            // An office hour takes no section practice.
            section_ids: kind === 'sp' || kind === 'ftx' ? data.section_ids : [],
            practice_type: kind,
            ftx_name: kind === 'ftx' ? data.ftx_name : null,
            ftx_date: kind === 'ftx' ? data.ftx_date : null,
            office_hour_date: kind === 'office_hour' ? data.office_hour_date : null,
            office_hour_note: kind === 'office_hour' ? data.office_hour_note : null,
            other_events: data.other_events,
            notes: data.notes,
        };
    }).post(`/staff-report/${props.trooper.id}/${props.week.date}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Staff report sent'),
        onHttpException: onHttpException('The staff report was not sent', (message) => toast.error(message)),
    });
}

const weekUrl = (date) => `/staff-report/${props.trooper.id}/${date}`;
const hqLabel = computed(() => (props.hq ? `${props.hq.label} HQ` : null));

function entryNote(id) {
    const entry = entered.value.get(id);
    if (!entry) return null;

    return entry.selfReported ? 'Already entered by you' : `Entered by ${entry.enteredBy ?? 'a user no longer in the portal'}`;
}

const segment = 'flex-1 rounded-md px-3 py-1.5 text-[13px] font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring';
const segmentOn = 'bg-card text-foreground shadow-xs';
const segmentOff = 'text-muted-foreground hover:text-foreground';
</script>

<template>
    <Head :title="`Staff report, week of ${forumDate(week.start)} · 7Cav Leadership Portal`" />

    <div class="flex max-w-3xl flex-col gap-4" data-testid="staff-report-page">
        <section aria-labelledby="page-title" class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground" data-testid="staff-report-who">
                    Staff report for {{ trooper.displayName }}<template v-if="hqLabel">, {{ hqLabel }}</template>
                </p>
                <h1 id="page-title" class="flex flex-wrap items-center gap-2.5 text-xl font-semibold tracking-tight sm:text-[22px]">
                    Week of {{ forumDate(week.start) }}
                    <Badge v-if="report" variant="success" data-testid="status-badge"><PhLockSimple aria-hidden="true" />Sent</Badge>
                    <Badge v-else-if="hq" variant="warning" data-testid="status-badge">Not sent</Badge>
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
            <div v-if="hq" class="w-full sm:ml-auto sm:w-auto sm:text-right">
                <p v-if="report" class="text-[13px] font-medium" data-testid="sent-line">Sent {{ stamp(report.sentAt) }}</p>
                <p v-else class="text-[13px]" data-testid="due-line">
                    <span class="text-muted-foreground">Due</span> <span class="font-medium tabular-nums">{{ stamp(week.due) }}</span>
                </p>
                <p class="text-xs text-muted-foreground">{{ forumDate(week.date) }} to {{ forumDate(weekEnd) }}</p>
            </div>
        </section>

        <ReportDeadlineBanner v-if="canSend" :due="week.due" :overdue-until="week.overdueUntil" :sent="!!report" />

        <Card v-if="!hq" class="py-10" data-testid="staff-report-none">
            <EmptyState :icon="PhUserCheck" title="No staff report for this week">
                {{ self ? 'You were' : `${trooper.displayName} was` }} not on a platoon, company or battalion HQ when this week ended, so there is nothing to send.
            </EmptyState>
        </Card>

        <!-- Sent: read back as it was sent. -->
        <Card v-else-if="report" class="gap-0 py-0 text-[13px]" data-testid="staff-report-sent">
            <div class="flex items-start gap-3 border-b border-border px-4 py-3.5">
                <PhCheckCircle class="mt-0.5 size-5 shrink-0 text-success" weight="fill" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <span data-testid="staff-report-answer">
                            <template v-if="report.attended && report.practiceType === 'ftx'">
                                Attended an FTX, {{ report.ftx?.name }}<template v-if="report.ftx?.date"> on {{ forumDate(report.ftx.date) }}</template>.
                            </template>
                            <template v-else-if="report.attended && report.practiceType === 'office_hour'">
                                Held an office hour<template v-if="report.officeHour?.date"> on {{ forumDate(report.officeHour.date) }}</template>.
                            </template>
                            <template v-else-if="report.attended">
                                Attended {{ report.practices.length }} {{ report.practices.length === 1 ? 'section practice' : 'section practices' }}.
                            </template>
                            <template v-else>Did not attend a section practice.</template>
                        </span>
                        <StaffReportDiscrepancy :discrepancy="discrepancy" />
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Sent {{ stamp(report.sentAt) }}. A sent staff report is final.
                        <span v-if="sheetLine" data-testid="staff-report-sheet">{{ sheetLine }}.</span>
                    </p>
                </div>
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
        </Card>

        <!-- Not sent, and this viewer may send it. -->
        <form v-else-if="canSend" class="flex flex-col gap-4" data-testid="staff-report-form" @submit.prevent="send">
            <Card class="gap-0 py-0 text-[13px]">
                <fieldset class="px-4 py-4">
                    <legend class="text-sm font-semibold">Did you attend a section practice this week?</legend>
                    <p class="mt-0.5 text-xs text-muted-foreground">HQ staff attend at least one a week, preferably in their own unit.</p>
                    <div class="mt-3 grid grid-cols-2 gap-2" role="radiogroup" aria-label="Attended a section practice">
                        <button
                            v-for="choice in [{ value: true, label: 'Yes' }, { value: false, label: 'No' }]"
                            :key="String(choice.value)"
                            type="button"
                            role="radio"
                            :aria-checked="form.attended === choice.value"
                            class="h-12 rounded-lg border text-sm font-semibold transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="form.attended === choice.value ? 'border-primary bg-primary/10 text-foreground' : 'border-border bg-card text-muted-foreground hover:bg-accent/70 hover:text-foreground'"
                            :data-testid="choice.value ? 'attended-yes' : 'attended-no'"
                            @click="form.attended = choice.value"
                        >
                            {{ choice.label }}
                        </button>
                    </div>
                    <p v-if="form.errors.attended" class="mt-1.5 text-xs text-destructive" data-testid="error-attended">{{ form.errors.attended }}</p>
                </fieldset>

                <div v-if="form.attended === true" class="border-t border-border px-4 py-4" data-testid="practice-type">
                    <p id="practice-type-label" class="text-sm font-semibold">What was it?</p>
                    <div class="mt-2 flex gap-1 rounded-lg bg-muted p-1" role="radiogroup" aria-labelledby="practice-type-label">
                        <button
                            v-for="option in TYPES"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.practice_type === option.value"
                            :class="[segment, form.practice_type === option.value ? segmentOn : segmentOff]"
                            :data-testid="`type-${option.value}`"
                            @click="form.practice_type = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <p v-if="form.errors.practice_type" class="mt-1.5 text-xs text-destructive" data-testid="error-practice-type">{{ form.errors.practice_type }}</p>

                    <div v-if="type === 'ftx'" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto]" data-testid="ftx-fields">
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium">Name of the FTX</span>
                            <Input v-model="form.ftx_name" :maxlength="limits.ftxName" autocomplete="off" data-testid="ftx-name" />
                            <span v-if="form.errors.ftx_name" class="text-xs text-destructive" data-testid="error-ftx-name">{{ form.errors.ftx_name }}</span>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium">Date</span>
                            <Input v-model="form.ftx_date" type="date" :min="week.date" :max="weekEnd" data-testid="ftx-date" />
                            <span v-if="form.errors.ftx_date" class="text-xs text-destructive" data-testid="error-ftx-date">{{ form.errors.ftx_date }}</span>
                        </label>
                    </div>

                    <div v-if="type === 'office_hour'" class="mt-4 grid gap-3" data-testid="office-hour-fields">
                        <p class="text-xs text-muted-foreground">An hour of office hours counts in place of the hour at a section practice. It is not checked against the game server.</p>
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
                </div>

                <div v-if="type === 'sp' || type === 'ftx'" class="border-t border-border px-4 py-4" data-testid="practice-picker">
                    <p class="text-sm font-semibold">{{ type === 'ftx' ? 'Sections that took part (optional)' : 'Which practices did you attend?' }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        In your own unit you are listed on that section's roll call as a staff guest, anywhere else as a guest. Neither counts in its numbers.
                    </p>

                    <ul v-if="form.section_ids.length" class="mt-3 flex flex-wrap gap-1.5" aria-label="Picked sections" data-testid="picked-chips">
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

                    <div class="relative mt-3">
                        <PhMagnifyingGlass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <Input v-model="filter" class="pl-8" autocomplete="off" placeholder="Find a section" aria-label="Find a section" data-testid="section-filter" />
                    </div>

                    <div class="mt-2 max-h-80 overflow-y-auto rounded-md border border-border">
                        <div v-for="group in groups" :key="group.key" :data-testid="`sections-${group.key || 'others'}`">
                            <p class="sticky top-0 z-10 border-b border-border bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">{{ group.label }}</p>
                            <ul class="divide-y divide-border">
                                <li v-for="section in group.rows" :key="section.id">
                                    <label class="flex cursor-pointer flex-wrap items-center gap-x-2.5 gap-y-1 px-2.5 py-2 hover:bg-accent/70">
                                        <input
                                            type="checkbox"
                                            class="size-4 accent-primary"
                                            :checked="picked.has(section.id)"
                                            :data-testid="`section-${section.id}`"
                                            @change="toggle(section.id)"
                                        />
                                        <span class="font-mono">{{ section.label }}</span>
                                        <Badge v-if="seenIds.has(section.id)" variant="outline" class="font-normal" :data-testid="`seen-${section.id}`">
                                            <PhBroadcast aria-hidden="true" />Seen on the game server
                                        </Badge>
                                        <span v-if="entryNote(section.id)" class="ml-auto text-xs text-muted-foreground" :data-testid="`entered-${section.id}`">
                                            {{ entryNote(section.id) }}
                                        </span>
                                    </label>
                                </li>
                            </ul>
                        </div>
                        <p v-if="!matching.length" class="px-2.5 py-3 text-xs text-muted-foreground" data-testid="sections-empty">No section matches "{{ filter.trim() }}".</p>
                    </div>
                    <p v-if="seen.length" class="mt-2 text-xs text-muted-foreground">
                        Seen on the game server means a capture saw you on the server that week. It is not picked for you.
                    </p>
                    <p v-if="form.errors.section_ids" class="mt-1.5 text-xs text-destructive" data-testid="error-sections">{{ form.errors.section_ids }}</p>
                </div>

                <details class="group border-t border-border" :open="!!form.other_events || !!form.errors.other_events" data-testid="other-events-section">
                    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold marker:hidden hover:bg-accent/40">
                        Other events attended <span class="font-normal text-muted-foreground">(optional)</span>
                    </summary>
                    <div class="px-4 pb-4">
                        <Textarea v-model="form.other_events" :maxlength="limits.otherEvents" rows="2" aria-label="Other events attended" data-testid="other-events" />
                        <span v-if="form.errors.other_events" class="text-xs text-destructive" data-testid="error-other-events">{{ form.errors.other_events }}</span>
                    </div>
                </details>
                <details class="group border-t border-border" :open="!!form.notes || !!form.errors.notes" data-testid="notes-section">
                    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold marker:hidden hover:bg-accent/40">
                        Notes <span class="font-normal text-muted-foreground">(optional)</span>
                    </summary>
                    <div class="px-4 pb-4">
                        <Textarea v-model="form.notes" :maxlength="limits.notes" rows="3" aria-label="Notes" data-testid="notes" />
                        <span v-if="form.errors.notes" class="text-xs text-destructive" data-testid="error-notes">{{ form.errors.notes }}</span>
                    </div>
                </details>
            </Card>

            <!-- The answer as it will be sent, and Send, kept in reach at the bottom of the screen. -->
            <div class="sticky bottom-0 z-20 -mx-4 border-t border-border bg-background/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border" data-testid="send-bar">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div class="min-w-0 flex-1 text-[13px]">
                        <p class="truncate font-medium" data-testid="send-summary">{{ summary }}</p>
                        <p v-if="missing" class="text-xs text-muted-foreground" data-testid="send-missing">{{ missing }}</p>
                        <p v-else class="text-xs text-muted-foreground">Once sent, your staff report cannot be changed.</p>
                    </div>
                    <Button type="submit" :disabled="!canSubmit" data-testid="staff-report-send">Send staff report</Button>
                </div>
                <p v-if="dropped.length && form.attended !== null" class="mt-1.5 text-xs text-warning" data-testid="dropped-note">
                    Sending takes off the {{ dropped.length === 1 ? 'entry' : 'entries' }} you left out:
                    {{ dropped.map((entry) => entry.section).join(', ') }}.
                </p>
                <p v-if="form.errors.report" role="alert" class="mt-1.5 text-xs text-destructive" data-testid="error-report">{{ form.errors.report }}</p>
            </div>
        </form>

        <!-- Not sent, and this viewer only reads it, or the week can no longer be sent. -->
        <Card v-else class="py-10" data-testid="staff-report-unsent">
            <EmptyState :icon="PhUserCheck" title="Not sent yet">
                <template v-if="week.beforeFloor">This week is too far back to send.</template>
                <template v-else-if="self && week.ended === false">You can send it from {{ stamp(week.opensAt) }}, once the week ends.</template>
                <template v-else-if="self">You can no longer send this staff report.</template>
                <template v-else>Only {{ trooper.displayName }} can send their staff report.</template>
            </EmptyState>
        </Card>
    </div>
</template>
