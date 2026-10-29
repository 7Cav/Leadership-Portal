<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { PhBroadcast, PhCaretLeft, PhCaretRight, PhLockSimple, PhUserCheck } from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import ReportDeadlineBanner from '@/components/reports/ReportDeadlineBanner.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { stamp } from '@/lib/report';
import { sheetText } from '@/lib/staffReport';

// A platoon, company or battalion HQ staff member's own weekly staff report: whether they attended a section
// practice or an FTX this week, and which, with the other events they attended and their notes. The server says who may send it (the staff member alone, signed in as themselves),
// which sections the picker offers, which entries are already there and what the game server saw; this page only
// draws its answers and posts the picks. Once sent it is final and reads back as sent.
const props = defineProps({
    // `{ id, displayName }`.
    trooper: { type: Object, required: true },
    // Whether the viewer is the staff member themselves.
    self: { type: Boolean, default: false },
    // The platoon, company or battalion HQ they were on when the week ended, `{ id, label, type }`, or null: no report that week.
    hq: { type: Object, default: null },
    // `{ date, start, due, overdueUntil, previous, next, beforeFloor, ended, opensAt }`.
    week: { type: Object, required: true },
    // The report as sent, `{ sentAt, attended, practiceType, ftx: { name, date }, otherEvents, notes, sheetPushedAt,
    // practices: [{ sectionId, section, own }] }`, or null.
    report: { type: Object, default: null },
    // Whether the reports Google Sheet is set up: only then is a report not yet copied there called out.
    sheetConfigured: { type: Boolean, default: false },
    canSend: { type: Boolean, default: false },
    // The week's staff practice entries already there, `[{ sectionId, section, enteredBy, selfReported }]`.
    entries: { type: Array, default: () => [] },
    // Section ids whose capture saw them on the game server this week: a hint, never ticked for them.
    seen: { type: Array, default: () => [] },
    // `[{ id, label, own }]`, their own unit's sections first.
    sections: { type: Array, default: () => [] },
    maxPractices: { type: Number, default: 20 },
    // `{ ftxName, otherEvents, notes }`: the most characters each takes.
    limits: { type: Object, default: () => ({ ftxName: 120, otherEvents: 500, notes: 1000 }) },
});

const entered = computed(() => new Map(props.entries.map((entry) => [entry.sectionId, entry])));
const seenIds = computed(() => new Set(props.seen));

const form = useForm({
    attended: props.entries.length ? true : null,
    section_ids: props.entries.map((entry) => entry.sectionId),
    practice_type: 'sp',
    ftx_name: '',
    ftx_date: '',
    other_events: '',
    notes: '',
});

// The days an FTX can fall on: the Cav week, Saturday to Friday.
const weekEnd = computed(() => {
    const end = new Date(`${props.week.date}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 6);

    return end.toISOString().slice(0, 10);
});
const isFtx = computed(() => form.attended === true && form.practice_type === 'ftx');
const ftxDateInWeek = computed(() => form.ftx_date >= props.week.date && form.ftx_date <= weekEnd.value);
const sheetLine = computed(() => (props.report ? sheetText(props.report.sheetPushedAt, props.sheetConfigured) : null));

const filter = ref('');
const matching = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle ? props.sections.filter((section) => section.label.toLowerCase().includes(needle)) : props.sections;
});
const own = computed(() => matching.value.filter((section) => section.own));
const others = computed(() => matching.value.filter((section) => !section.own));
const picked = computed(() => new Set(form.section_ids));
// Entries already there that are no longer ticked: sending takes them off.
const dropped = computed(() => props.entries.filter((entry) => form.attended === false || !picked.value.has(entry.sectionId)));

function toggle(id) {
    form.section_ids = picked.value.has(id) ? form.section_ids.filter((sectionId) => sectionId !== id) : [...form.section_ids, id];
}

const canSubmit = computed(() => {
    if (form.processing || form.attended === null) return false;

    if (form.attended === false) return true;
    if (form.section_ids.length > props.maxPractices) return false;
    // An FTX needs its name and a date in the week; its sections are optional. An SP needs a section.
    if (isFtx.value) return form.ftx_name.trim() !== '' && ftxDateInWeek.value;

    return form.section_ids.length > 0;
});

function send() {
    if (!canSubmit.value) return;
    form.transform((data) => {
        const ftx = data.attended && data.practice_type === 'ftx';

        return {
            attended: data.attended,
            section_ids: data.attended ? data.section_ids : [],
            practice_type: data.attended ? data.practice_type : null,
            ftx_name: ftx ? data.ftx_name : null,
            ftx_date: ftx ? data.ftx_date : null,
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
                <p v-if="report" class="text-[13px] font-medium" data-testid="sent-line">Sent {{ stamp(report.sentAt) }}Z</p>
                <p v-else class="text-xs text-muted-foreground" data-testid="due-line">Due {{ stamp(week.due) }}Z</p>
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
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">Section practice this week</h2>
                <p class="mt-0.5" data-testid="staff-report-answer">
                    <template v-if="report.attended && report.practiceType === 'ftx'">
                        Attended an FTX, {{ report.ftx?.name }}<template v-if="report.ftx?.date"> on {{ forumDate(report.ftx.date) }}</template>.
                    </template>
                    <template v-else-if="report.attended">
                        Attended {{ report.practices.length }} {{ report.practices.length === 1 ? 'section practice' : 'section practices' }}.
                    </template>
                    <template v-else>Did not attend a section practice.</template>
                </p>
                <p v-if="sheetLine" class="mt-1 text-xs text-muted-foreground" data-testid="staff-report-sheet">{{ sheetLine }}</p>
            </div>
            <ul v-if="report.practices.length" class="divide-y divide-border" data-testid="staff-report-practices">
                <li v-for="practice in report.practices" :key="practice.sectionId" class="flex items-center gap-2.5 px-4 py-2.5">
                    <span class="font-mono">{{ practice.section ?? 'A section since removed' }}</span>
                    <Badge variant="outline" class="ml-auto font-normal">{{ practice.own ? 'Staff guest' : 'Guest' }}</Badge>
                </li>
            </ul>
            <dl v-if="report.otherEvents || report.notes" class="border-t border-border px-4 py-3">
                <template v-if="report.otherEvents">
                    <dt class="text-xs font-medium text-muted-foreground">Other events attended</dt>
                    <dd class="mb-2 whitespace-pre-line" data-testid="staff-report-other-events">{{ report.otherEvents }}</dd>
                </template>
                <template v-if="report.notes">
                    <dt class="text-xs font-medium text-muted-foreground">Notes</dt>
                    <dd class="whitespace-pre-line" data-testid="staff-report-notes">{{ report.notes }}</dd>
                </template>
            </dl>
        </Card>

        <!-- Not sent, and this viewer may send it. -->
        <Card v-else-if="canSend" class="gap-0 py-0 text-[13px]" data-testid="staff-report-form">
            <form @submit.prevent="send">
                <fieldset class="border-b border-border px-4 py-3">
                    <legend class="text-sm font-semibold">Did you attend a section practice this week?</legend>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        HQ staff attend at least one section practice a week, preferably in their own unit.
                    </p>
                    <div class="mt-2 flex gap-4">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.attended" type="radio" name="attended" :value="true" class="size-4 accent-primary" data-testid="attended-yes" />Yes
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.attended" type="radio" name="attended" :value="false" class="size-4 accent-primary" data-testid="attended-no" />No
                        </label>
                    </div>
                    <p v-if="form.errors.attended" class="mt-1 text-xs text-destructive" data-testid="error-attended">{{ form.errors.attended }}</p>
                </fieldset>

                <fieldset v-if="form.attended === true" class="border-b border-border px-4 py-3" data-testid="practice-type">
                    <legend class="text-sm font-semibold">What type of practice?</legend>
                    <div class="mt-2 flex gap-4">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.practice_type" type="radio" name="practice_type" value="sp" class="size-4 accent-primary" data-testid="type-sp" />Section practice (SP)
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.practice_type" type="radio" name="practice_type" value="ftx" class="size-4 accent-primary" data-testid="type-ftx" />FTX
                        </label>
                    </div>
                    <p v-if="form.errors.practice_type" class="mt-1 text-xs text-destructive" data-testid="error-practice-type">{{ form.errors.practice_type }}</p>
                    <div v-if="isFtx" class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto]" data-testid="ftx-fields">
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium">Name of the FTX</span>
                            <Input v-model="form.ftx_name" :maxlength="limits.ftxName" autocomplete="off" data-testid="ftx-name" />
                            <span v-if="form.errors.ftx_name" class="text-xs text-destructive" data-testid="error-ftx-name">{{ form.errors.ftx_name }}</span>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium">Date of the FTX</span>
                            <Input v-model="form.ftx_date" type="date" :min="week.date" :max="weekEnd" data-testid="ftx-date" />
                            <span v-if="form.errors.ftx_date" class="text-xs text-destructive" data-testid="error-ftx-date">{{ form.errors.ftx_date }}</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset v-if="form.attended === true" class="border-b border-border px-4 py-3" data-testid="practice-picker">
                    <legend class="text-sm font-semibold">{{ isFtx ? 'Which sections took part? (optional)' : 'Which practices did you attend?' }}</legend>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        A practice in your own unit lists you on that section's roll call as a staff guest, anywhere else as a guest.
                        Neither counts in that section's numbers.
                    </p>
                    <Input v-model="filter" class="mt-2" autocomplete="off" placeholder="Find a section" aria-label="Find a section" data-testid="section-filter" />

                    <template v-for="group in [{ key: 'own', label: hqLabel ? `In ${hq.label}` : 'Your unit', rows: own }, { key: 'others', label: 'Other sections', rows: others }]" :key="group.key">
                        <div v-if="group.rows.length" class="mt-3" :data-testid="`sections-${group.key}`">
                            <p class="text-xs font-medium text-muted-foreground">{{ group.label }}</p>
                            <ul class="mt-1 max-h-72 divide-y divide-border overflow-y-auto rounded-md border border-border">
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
                    </template>
                    <p v-if="!matching.length" class="mt-3 text-xs text-muted-foreground" data-testid="sections-empty">No section matches "{{ filter.trim() }}".</p>
                    <p v-if="seen.length" class="mt-2 text-xs text-muted-foreground">
                        Seen on the game server means a capture saw you on the server that week. It is not ticked for you.
                    </p>
                    <p v-if="form.errors.section_ids" class="mt-1 text-xs text-destructive" data-testid="error-sections">{{ form.errors.section_ids }}</p>
                </fieldset>

                <div class="flex flex-col gap-3 border-b border-border px-4 py-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-semibold">What other events did you attend? (optional)</span>
                        <Textarea v-model="form.other_events" :maxlength="limits.otherEvents" rows="2" data-testid="other-events" />
                        <span v-if="form.errors.other_events" class="text-xs text-destructive" data-testid="error-other-events">{{ form.errors.other_events }}</span>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-semibold">Notes (optional)</span>
                        <Textarea v-model="form.notes" :maxlength="limits.notes" rows="3" data-testid="notes" />
                        <span v-if="form.errors.notes" class="text-xs text-destructive" data-testid="error-notes">{{ form.errors.notes }}</span>
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                    <Button type="submit" :disabled="!canSubmit" data-testid="staff-report-send">Send staff report</Button>
                    <p class="text-xs text-muted-foreground">Once sent, your staff report cannot be changed.</p>
                    <p v-if="dropped.length && form.attended !== null" class="w-full text-xs text-warning" data-testid="dropped-note">
                        Sending takes off the {{ dropped.length === 1 ? 'entry' : 'entries' }} you left unticked:
                        {{ dropped.map((entry) => entry.section).join(', ') }}.
                    </p>
                    <p v-if="form.errors.report" role="alert" class="w-full text-xs text-destructive" data-testid="error-report">{{ form.errors.report }}</p>
                </div>
            </form>
        </Card>

        <!-- Not sent, and this viewer only reads it, or the week can no longer be sent. -->
        <Card v-else class="py-10" data-testid="staff-report-unsent">
            <EmptyState :icon="PhUserCheck" title="Not sent yet">
                <template v-if="week.beforeFloor">This week is too far back to send.</template>
                <template v-else-if="self && week.ended === false">You can send it from {{ stamp(week.opensAt) }}Z, once the week ends.</template>
                <template v-else-if="self">You can no longer send this staff report.</template>
                <template v-else>Only {{ trooper.displayName }} can send their staff report.</template>
            </EmptyState>
        </Card>
    </div>
</template>
