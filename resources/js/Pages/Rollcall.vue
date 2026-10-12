<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhArrowsClockwise,
    PhCaretLeft,
    PhCaretRight,
    PhCaretUpDown,
    PhCheck,
    PhChecks,
    PhCircleNotch,
    PhClipboardText,
    PhCloudCheck,
    PhCloudSlash,
    PhHandSwipeRight,
    PhClock,
    PhCheckCircle,
    PhClockCounterClockwise,
    PhCopy,
    PhEye,
    PhUsersThree,
    PhUserCircleDashed,
    PhWarning,
} from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import EmptyState from '@/components/EmptyState.vue';
import AttendanceSourceLegend from '@/components/attendance/AttendanceSourceLegend.vue';
import SectionPicker from '@/components/rollcall/SectionPicker.vue';
import StepReview from '@/components/rollcall/StepReview.vue';
import TrooperCard from '@/components/rollcall/TrooperCard.vue';
import UnmatchedDrawer from '@/components/rollcall/UnmatchedDrawer.vue';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import ForumPost from '@/components/rollcall/ForumPost.vue';
import { messageFor, unreachable, useRollcallAutosave } from '@/composables/useRollcallAutosave';
import { postJson } from '@/lib/postJson';
import { rollcallBbcode } from '@/lib/rollcallBbcode';
import {
    countsOf,
    displayName,
    isLocked,
    optionsFor,
    practiceGroups,
    practiceLabel,
    signatureGroups,
    signatureLabel,
    stepsOf,
} from '@/lib/rollcall';
import { cn } from '@/lib/utils';

// The weekly rollcall of one section (Stories 3.7–3.10), binding design mockups/v2/rollcall-r3.html
// "Guided week": three steps — signatures, Section Practice, review and post — over the section's
// troopers. With no section chosen (`section` null) it is the section picker.
const props = defineProps({
    // `{ id, designation, name, platoon }`, or null for the picker.
    section: { type: Object, default: null },
    // Every section in the viewer's scope: `[{ id, designation, name, platoon }]`.
    sections: { type: Array, required: true },
    // `{ start, date, closes, isCurrent, previous, next, current }` (AD-11).
    week: { type: Object, required: true },
    // `[{ id, cavId, rank, name, rollcall, status, source, priorStatus }]`.
    troopers: { type: Array, default: () => [] },
    priorWeekMarked: { type: Boolean, default: false },
    // `[{ trooperId, missed, windowWeeks }]`, from the shared KPI service (AD-21).
    atRisk: { type: Array, default: () => [] },
    // `{ source, syncedAt }` (AD-20).
    roster: { type: Object, default: null },
    practice: { type: Object, default: null },
    // `{ source, capturedAt }` once a capture run stamped this practice, else null (7.7). The seam
    // the capture banner and override (7.8) and the telemetry-unavailable state (7.9) hang on.
    capture: { type: Object, default: null },
    // `[{ platform, platformLabel, playerName, platformId }]`: players the capture saw that no
    // trooper is linked to (7.7).
    unmatched: { type: Array, default: () => [] },
});

const page = usePage();

// The screen's working copy of the marks, and what they were when the page loaded (the review
// step lists the difference as this visit's changes).
const rows = ref([]);
const baseline = ref(new Map());
watch(
    () => props.troopers,
    (troopers) => {
        rows.value = troopers.map((trooper) => ({ ...trooper }));
        baseline.value = new Map(troopers.map((trooper) => [trooper.id, { rollcall: trooper.rollcall, status: trooper.status }]));
    },
    { immediate: true },
);

// The players still waiting for a trooper: leaves the list as the leader links or dismisses them.
const unmatchedPlayers = ref(props.unmatched);
watch(() => props.unmatched, (unmatched) => (unmatchedPlayers.value = unmatched));
const showUnmatched = computed(() => Boolean(props.capture) && unmatchedPlayers.value.length > 0);

const liveAtRisk = ref(props.atRisk);
watch(() => props.atRisk, (atRisk) => (liveAtRisk.value = atRisk));

// A view-as preview (Story 1.13) is read-only: the server refuses every write during one, so the
// screen says so up front instead of letting saves fail.
const viewAs = computed(() => page.props.viewAs ?? null);
const editable = computed(() => !viewAs.value);
const readOnlyReason = computed(() => (viewAs.value ? `Read-only while viewing as ${viewAs.value.label}` : null));

const autosave = props.section
    ? useRollcallAutosave({
          url: `/rollcall/${props.section.id}`,
          week: props.week.date,
          onSaved: (data) => (liveAtRisk.value = data.atRisk ?? liveAtRisk.value),
          nameOf: (id) => displayName(rows.value.find((t) => t.id === id) ?? { cavId: id }),
      })
    : null;

function set(trooper, kind, value, options = {}) {
    if (!editable.value || isLocked(trooper)) return;
    const field = kind === 'signature' ? 'rollcall' : 'status';
    if (trooper[field] === value) return;

    trooper[field] = value;
    if (field === 'status') trooper.source = 'manual';
    if (posted.value) editedSincePost.value = true;
    autosave.queue(trooper.id, field, value, options);
}

// "Mark all present" and "use last week's marks" set many rows at once. Their saves are held for
// the undo window, because a saved practice mark cannot be cleared again (3.3).
const UNDO_MS = 6000;

// Undo toasts still open; marking posted saves their held changes early, so it closes them.
const undoToasts = new Set();

function applyMany(entries, title) {
    if (!entries.length) return;
    const before = entries.map(({ trooper, value }) => ({ trooper, value, status: trooper.status, source: trooper.source }));
    for (const { trooper, value } of entries) set(trooper, 'practice', value, { holdMs: UNDO_MS });

    const id = toast.success(title, {
        description: entries.map(({ trooper }) => displayName(trooper)).join(', '),
        duration: UNDO_MS,
        action: {
            label: 'Undo',
            onClick: () => {
                // Only rows still holding the bulk value: a mark changed by hand since then stays.
                // A change already saved stays: a saved practice mark cannot be cleared again.
                const held = before.filter(({ trooper, value }) => trooper.status === value);
                const cancelled = new Set(autosave.cancel(held.map(({ trooper }) => trooper.id), 'status'));
                for (const { trooper, status, source } of held) {
                    if (cancelled.has(trooper.id)) Object.assign(trooper, { status, source });
                }
                if (cancelled.size < held.length) {
                    toast.warning('Some marks were already saved', {
                        description: `${held.length - cancelled.size} could not be undone. Change them by hand if they are wrong.`,
                    });
                }
            },
        },
        onDismiss: () => undoToasts.delete(id),
        onAutoClose: () => undoToasts.delete(id),
    });
    undoToasts.add(id);
}

const sameUnmatched = (a, b) => a.platform === b.platform && a.platformId === b.platformId;

// The server linked the player and pre-marked the trooper (a manual mark is kept, so the row takes
// what the server says it now holds).
function onLinked({ player, marked, trooper, atRisk }) {
    unmatchedPlayers.value = unmatchedPlayers.value.filter((p) => !sameUnmatched(p, player));
    const row = rows.value.find((t) => t.id === trooper.id);
    if (row && trooper.status) Object.assign(row, { status: trooper.status, source: trooper.source });
    liveAtRisk.value = atRisk ?? liveAtRisk.value;
    const name = row ? displayName(row) : `trooper ${trooper.id}`;
    if (marked) toast.success(`Linked ${player.playerName} to ${name}`, { description: 'Marked present for this practice.' });
    else toast.success(`Linked ${player.playerName} to ${name}`, { description: 'Their existing mark was kept.' });
}

function onDismissed(player) {
    unmatchedPlayers.value = unmatchedPlayers.value.filter((p) => !sameUnmatched(p, player));
    toast.success(`Dismissed ${player.playerName}`, { description: 'They stay off the unmatched lists until linked to a trooper.' });
}

const unmarked = computed(() => rows.value.filter((t) => !isLocked(t) && t.status === null));
const prefillable = computed(() => unmarked.value.filter((t) => t.priorStatus && t.priorStatus !== 'loa'));

function markAllPresent() {
    const entries = unmarked.value.map((trooper) => ({ trooper, value: 'present' }));
    applyMany(entries, `Marked ${entries.length} as present`);
}

function useLastWeek() {
    const entries = prefillable.value.map((trooper) => ({ trooper, value: trooper.priorStatus }));
    applyMany(entries, `Used last week's marks for ${entries.length}`);
}

// Keyboard (mockup aside): J / K or the arrows move between cards, 1–4 set the focused card.
function onCardKeydown(event) {
    const face = event.target.closest?.('[data-card-face]');
    if (!face || event.target !== face || event.metaKey || event.ctrlKey || event.altKey) return;
    const faces = [...event.currentTarget.querySelectorAll('[data-card-face]')];
    const index = faces.indexOf(face);

    if (event.key === 'j' || event.key === 'ArrowDown') {
        event.preventDefault();
        faces[index + 1]?.focus();
    } else if (event.key === 'k' || event.key === 'ArrowUp') {
        event.preventDefault();
        faces[index - 1]?.focus();
    } else if (/^[1-4]$/.test(event.key)) {
        const id = Number(face.closest('[data-trooper]').dataset.trooper);
        const kind = step.value === 1 ? 'signature' : 'practice';
        const option = optionsFor(kind)[Number(event.key) - 1];
        const trooper = rows.value.find((t) => t.id === id);
        if (!option || !trooper) return;
        event.preventDefault();
        set(trooper, kind, option.value);
        // The card may have moved to another group; keep the focus on the same trooper.
        nextTick(() => document.querySelector(`[data-trooper="${id}"] [data-card-face]`)?.focus());
    }
}

// What changed on this visit, for the review step.
const changes = computed(() =>
    rows.value.flatMap((trooper) => {
        const was = baseline.value.get(trooper.id);
        if (!was) return [];
        const out = [];
        if (was.rollcall !== trooper.rollcall) {
            out.push({ key: `${trooper.id}-sig`, trooper, what: 'Signature', from: signatureLabel(was.rollcall), to: signatureLabel(trooper.rollcall) });
        }
        if (was.status !== trooper.status) {
            out.push({ key: `${trooper.id}-sp`, trooper, what: 'SP', from: practiceLabel(was.status), to: practiceLabel(trooper.status) });
        }

        return out;
    }),
);

// The forum post (3.10): generated client-side from the current marks, copied by the leader. The
// portal makes no outbound call on copy or on marking posted (AD-20).
const bbcode = computed(() =>
    props.section ? rollcallBbcode({ designation: props.section.designation, weekStart: props.week.start, troopers: rows.value }) : '',
);
const copied = ref(false);
const posting = ref(false);

async function copyBbcode() {
    try {
        await navigator.clipboard.writeText(bbcode.value);
        copied.value = true;
        toast.success('BBCode copied', { description: `Paste it as a reply in the ${props.section.designation} rollcall thread.` });
    } catch {
        toast.error('Could not copy the BBCode', { description: 'Open the BBCode tab and copy it by hand.' });
    }
}

async function markPosted() {
    posting.value = true;
    try {
        // The post on the forum was made from the marks on screen, so they must be saved first.
        for (const id of undoToasts) toast.dismiss(id);
        undoToasts.clear();
        if (!(await autosave.flush({ includeHeld: true }))) {
            toast.error('Changes are not saved yet', { description: autosave.error.value });

            return;
        }
        const result = await postJson(`/rollcall/${props.section.id}/posted`, { week: props.week.date }).catch(unreachable);
        if (result.ok && result.data?.posted) {
            posted.value = result.data.posted;
            editedSincePost.value = false;
            toast.success('Rollcall marked posted', { description: `${props.section.designation}, week of ${weekLabel.value}` });
        } else {
            toast.error('Could not mark the rollcall posted', { description: messageFor(result) });
        }
    } finally {
        posting.value = false;
    }
}

const postedLabel = computed(() =>
    posted.value ? `${forumDateTime(posted.value.at)}Z${posted.value.by ? ` by ${posted.value.by}` : ''}` : null,
);

const saveLabel = computed(() => {
    if (!autosave) return null;
    if (autosave.state.value === 'saving') return 'Saving...';
    if (autosave.state.value === 'error') return 'Not saved';
    if (autosave.savedAt.value) return `Saved ${forumDateTime(autosave.savedAt.value).split(' ')[1]}Z`;
    if (autosave.pendingCount.value) return 'Unsaved changes';

    return null;
});

// Rollcall posted to the forum (3.10): when and by whom, and whether marks changed since — then
// the post on the forum is stale and needs reposting.
const posted = ref(props.practice?.posted ?? null);
const editedSincePost = ref(
    Boolean(posted.value && props.practice?.lastMarkedAt && new Date(props.practice.lastMarkedAt) > new Date(posted.value.at)),
);

const counts = computed(() => countsOf(rows.value));
const steps = computed(() => stepsOf(rows.value, posted.value && !editedSincePost.value ? posted.value.at : null));

// The step is part of the URL (`?step=2`), so a step can be linked to and Back walks the steps.
const query = computed(() => new URL(page.url, 'http://portal.local').searchParams);
const step = computed(() => {
    const requested = Number(query.value.get('step'));

    return [1, 2, 3].includes(requested) ? requested : 1;
});
const stepHeading = ref(null);

function go(n) {
    const params = new URLSearchParams(query.value);
    params.set('step', String(n));
    router.push({ url: `${new URL(page.url, 'http://portal.local').pathname}?${params}`, preserveState: true, preserveScroll: true });
    nextTick(() => stepHeading.value?.focus());
}

function weekHref(date) {
    const params = date && date !== props.week.current ? `?week=${date}` : '';

    return `/rollcall/${props.section.id}${params}`;
}

const weekQuery = computed(() => (props.week.isCurrent ? null : props.week.date));
const weekLabel = computed(() => forumDate(props.week.start));
const closesLabel = computed(() => {
    const at = new Date(props.week.closes);

    return `${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][at.getUTCDay()]} ${forumDateTime(at)}Z`;
});
const syncedLabel = computed(() => (props.roster?.syncedAt ? `${forumDateTime(props.roster.syncedAt)}Z` : null));

const pickerOpen = ref(false);
const practiceFilter = ref('all');

const signatureView = computed(() => signatureGroups(rows.value));
const practiceView = computed(() => practiceGroups(rows.value, practiceFilter.value === 'open'));
</script>

<template>
    <Head :title="section ? `Rollcall ${section.designation} · 7Cav Leadership Portal` : 'Rollcall · 7Cav Leadership Portal'" />

    <!-- No section chosen: the picker. -->
    <div v-if="!section" class="mx-auto max-w-xl space-y-4">
        <div>
            <p class="text-xs text-muted-foreground">Week of {{ forumDate(week.start) }}</p>
            <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]">Choose a section</h1>
        </div>
        <SectionPicker v-if="sections.length" :sections="sections" :week="weekQuery" />
        <Card v-else class="py-0">
            <EmptyState :icon="PhUsersThree" title="No sections in your scope" class="py-12">
                Rollcalls are kept per section. Once you lead a section, or hold a grant over one, it is listed here.
            </EmptyState>
        </Card>
    </div>

    <div v-else class="mx-auto max-w-[1240px]">
        <!-- Page head -->
        <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">{{ section.designation }} weekly cycle</p>
                <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]">Rollcall, week of {{ weekLabel }}</h1>
            </div>
            <div class="flex items-center gap-1 text-muted-foreground">
                <Button variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekHref(week.previous)" aria-label="Previous week" preserve-scroll><PhCaretLeft /></Link>
                </Button>
                <Button v-if="!week.isCurrent" variant="ghost" size="sm" as-child>
                    <Link :href="weekHref(week.current)">This week</Link>
                </Button>
                <Button v-else variant="ghost" size="sm" disabled>This week</Button>
                <Button v-if="week.next" variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekHref(week.next)" aria-label="Next week" preserve-scroll><PhCaretRight /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next week"><PhCaretRight /></Button>
                <Button v-if="sections.length > 1" variant="outline" size="sm" class="ml-1" @click="pickerOpen = true">
                    {{ section.designation }}<PhCaretUpDown class="text-muted-foreground" />
                    <span class="sr-only">Switch section</span>
                </Button>
            </div>
            <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground sm:ml-auto sm:w-auto">
                <span class="inline-flex items-center gap-1.5" data-testid="roster-provenance">
                    <PhArrowsClockwise class="size-4" aria-hidden="true" />
                    <template v-if="syncedLabel">Roster from {{ roster.source }}, synced {{ syncedLabel }}</template>
                    <template v-else>Roster from {{ roster?.source ?? 'milpac' }}, not synced yet</template>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <PhClock class="size-4" aria-hidden="true" />Signatures close {{ closesLabel }}
                </span>
                <span role="status" aria-live="polite" class="inline-flex items-center gap-1.5 text-foreground" data-testid="save-state">
                    <template v-if="saveLabel">
                        <PhCircleNotch v-if="autosave.state.value === 'saving'" class="size-4 animate-spin text-muted-foreground" aria-hidden="true" />
                        <PhCloudSlash v-else-if="autosave.state.value === 'error'" class="size-4 text-destructive" aria-hidden="true" />
                        <PhCloudCheck v-else-if="autosave.state.value === 'saved'" class="size-4 text-success" aria-hidden="true" />
                        <span :class="autosave.state.value === 'saving' && 'text-muted-foreground'">{{ saveLabel }}</span>
                    </template>
                </span>
            </div>
        </div>

        <div
            v-if="autosave?.state.value === 'error'"
            role="alert"
            class="mt-3 flex flex-wrap items-center gap-2.5 rounded-lg border border-destructive/35 bg-destructive/7 px-3.5 py-2.5 text-[13px]"
            data-testid="save-error"
        >
            <PhCloudSlash class="size-4 shrink-0 text-destructive" aria-hidden="true" />
            <p class="min-w-0 flex-1">
                Your last changes are not saved. {{ autosave.error.value }} They stay on this screen until they save.
            </p>
            <Button variant="outline" size="sm" @click="autosave.retry()">Retry</Button>
        </div>

        <div
            v-if="autosave?.rejectedMessage.value"
            role="alert"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/7 px-3.5 py-2.5 text-[13px]"
            data-testid="save-rejected"
        >
            <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <p class="min-w-0 flex-1">{{ autosave.rejectedMessage.value }}</p>
        </div>

        <div
            v-if="readOnlyReason"
            role="status"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-info/35 bg-info/7 px-3.5 py-2.5 text-[13px]"
            data-testid="read-only-notice"
        >
            <PhEye class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
            <p>{{ readOnlyReason }}. Exit view as to mark attendance.</p>
        </div>

        <div
            v-if="!week.isCurrent"
            role="status"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/7 px-3.5 py-2.5 text-[13px]"
            data-testid="past-week-notice"
        >
            <PhClockCounterClockwise class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <p>
                Editing a past week, {{ weekLabel }}. Changes correct that week's record and are logged like any other mark.
            </p>
        </div>

        <!-- Stepper -->
        <div
            class="sticky top-12 z-20 -mx-4 mt-3 border-b border-border bg-background/95 px-4 pt-3 pb-2 backdrop-blur sm:-mx-6 sm:px-6"
        >
            <nav aria-label="Rollcall steps">
                <ol class="grid grid-cols-3 gap-1.5">
                    <li v-for="s in steps" :key="s.n">
                        <button
                            type="button"
                            :aria-current="step === s.n ? 'step' : undefined"
                            :aria-label="`Step ${s.n}, ${s.title}, ${s.sub}`"
                            :class="
                                cn(
                                    'relative grid min-h-[3.75rem] w-full grid-cols-1 items-center justify-items-center gap-x-2.5 gap-y-0.5 rounded-md px-1 pt-1.5 pb-3 text-center hover:bg-accent/70 sm:min-h-14 sm:grid-cols-[auto_minmax(0,1fr)] sm:justify-items-start sm:px-2.5 sm:pt-2 sm:text-left',
                                    'after:absolute after:inset-x-2.5 after:bottom-1 after:h-[3px] after:rounded-full after:bg-border',
                                    s.state === 'done' && 'after:bg-success/70',
                                    s.state === 'warn' && 'after:bg-warning/70',
                                    step === s.n && 'after:bg-primary',
                                )
                            "
                            :data-step="s.n"
                            @click="go(s.n)"
                        >
                            <span
                                :class="
                                    cn(
                                        'grid size-7 place-items-center rounded-full border border-border bg-card text-xs font-semibold text-muted-foreground sm:row-span-2',
                                        s.state === 'done' && 'border-success/35 bg-success/8 text-success',
                                        s.state === 'warn' && 'border-warning/40 bg-warning/8 text-warning',
                                        step === s.n && 'border-transparent bg-primary text-primary-foreground',
                                    )
                                "
                                aria-hidden="true"
                            >
                                <PhCheck v-if="s.state === 'done' && step !== s.n" />
                                <template v-else>{{ s.n }}</template>
                            </span>
                            <span class="w-full truncate text-xs font-medium sm:text-[13px] sm:leading-tight sm:font-semibold">{{ s.title }}</span>
                            <span class="hidden w-full truncate text-xs leading-tight text-muted-foreground tabular-nums sm:block">{{ s.sub }}</span>
                        </button>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="mt-4 grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18.5rem]">
            <section class="min-w-0" :aria-labelledby="`step-${step}-title`" @keydown="onCardKeydown">
                <!-- Empty roster -->
                <Card v-if="!rows.length" class="py-0">
                    <EmptyState :icon="PhClipboardText" title="No troopers placed in this section" class="py-14">
                        Troopers appear here once the roster sync maps them to {{ section.designation }} as their primary section.
                    </EmptyState>
                </Card>

                <!-- Step 1: signatures -->
                <template v-else-if="step === 1">
                    <div class="mb-1">
                        <h2 :id="`step-${step}-title`" ref="stepHeading" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">
                            Signatures
                        </h2>
                        <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                            Who signed the rollcall thread for this week. You can record a signature for a trooper who told you directly.
                        </p>
                    </div>
                    <p v-if="editable" class="mt-2 hidden items-center gap-2 text-xs text-muted-foreground pointer-coarse:flex">
                        <PhHandSwipeRight class="size-4" aria-hidden="true" />Swipe a card right for will attend, left for cannot attend.
                    </p>
                    <div v-for="group in signatureView" :key="group.id" role="group" :aria-label="group.title">
                        <div class="flex items-baseline justify-between gap-3 px-1 pt-3.5 pb-1.5 text-[13px] font-semibold">
                            <span>{{ group.title }}</span>
                            <span class="text-xs font-normal text-muted-foreground tabular-nums">{{ group.troopers.length }}</span>
                        </div>
                        <div class="space-y-2">
                            <TrooperCard
                                v-for="trooper in group.troopers"
                                :key="trooper.id"
                                :trooper="trooper"
                                kind="signature"
                                :editable="editable"
                                :disabled-reason="readOnlyReason"
                                @set="(value) => set(trooper, 'signature', value)"
                            />
                        </div>
                    </div>
                    <div class="mt-5 flex items-center gap-2 py-3">
                        <Button class="ml-auto h-11 flex-1 sm:h-9 sm:flex-none" @click="go(2)">Next: section practice<PhCaretRight /></Button>
                    </div>
                </template>

                <!-- Step 2: section practice -->
                <template v-else-if="step === 2">
                    <div class="mb-1 flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1 basis-full sm:basis-0">
                            <h2 :id="`step-${step}-title`" ref="stepHeading" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">
                                Section practice
                            </h2>
                            <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                                Mark everyone who was expected. Troopers who said they could not attend are listed first.
                            </p>
                        </div>
                        <div class="inline-flex gap-0.5 rounded-md bg-muted p-0.5" role="group" aria-label="Show">
                            <button
                                v-for="option in [
                                    { id: 'all', label: 'All', count: rows.length },
                                    { id: 'open', label: 'Needs a look', count: counts.unmarked + counts.missed },
                                ]"
                                :key="option.id"
                                type="button"
                                :aria-pressed="practiceFilter === option.id"
                                class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-medium text-muted-foreground aria-pressed:bg-card aria-pressed:text-foreground aria-pressed:shadow-xs"
                                @click="practiceFilter = option.id"
                            >
                                {{ option.label }} <span class="text-[11px] text-muted-foreground tabular-nums">{{ option.count }}</span>
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="showUnmatched"
                        role="status"
                        class="mt-3 flex items-start gap-2.5 rounded-lg border border-info/35 bg-info/7 px-3.5 py-2.5 text-[13px]"
                        data-testid="unmatched-banner"
                    >
                        <PhUserCircleDashed class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                        <p>
                            <b class="font-semibold">{{ unmatchedPlayers.length }} {{ unmatchedPlayers.length === 1 ? 'player needs' : 'players need' }} a trooper.</b>
                            Link or dismiss them in the panel.
                        </p>
                    </div>

                    <div
                        role="status"
                        class="mt-3 flex flex-wrap items-start gap-x-2.5 gap-y-2 rounded-lg border border-border px-3.5 py-2.5 text-[13px]"
                        data-testid="prefill-banner"
                    >
                        <PhClockCounterClockwise class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <p v-if="priorWeekMarked" class="min-w-[14rem] flex-1">
                            Pre-filled from last week: each trooper still to mark shows last week's practice mark as a suggestion.
                        </p>
                        <p v-else class="min-w-[14rem] flex-1 text-muted-foreground">Nothing pre-filled: no practice marks were recorded last week.</p>
                        <AttendanceSourceLegend class="w-full sm:w-auto" />
                        <Button v-if="editable && prefillable.length" variant="outline" size="sm" data-testid="use-last-week" @click="useLastWeek">
                            Use last week's marks ({{ prefillable.length }})
                        </Button>
                    </div>

                    <p v-if="editable" class="mt-2 hidden items-center gap-2 text-xs text-muted-foreground pointer-coarse:flex">
                        <PhHandSwipeRight class="size-4" aria-hidden="true" />Swipe a card right for present, left for missed.
                    </p>
                    <div v-if="editable && unmarked.length" class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
                        <span>Sets the {{ unmarked.length }} troopers not marked yet. Marks you already set stay.</span>
                        <Button variant="outline" size="sm" class="sm:ml-auto" data-testid="mark-all-present" @click="markAllPresent">
                            <PhChecks />Mark all present
                        </Button>
                    </div>

                    <template v-if="practiceView.length">
                        <div v-for="group in practiceView" :key="group.id" role="group" :aria-label="group.title">
                            <div class="flex items-baseline justify-between gap-3 px-1 pt-3.5 pb-1.5 text-[13px] font-semibold">
                                <span>{{ group.title }}</span>
                                <span class="text-xs font-normal text-muted-foreground tabular-nums">{{ group.hint ?? group.troopers.length }}</span>
                            </div>
                            <div class="space-y-2">
                                <TrooperCard
                                    v-for="trooper in group.troopers"
                                    :key="trooper.id"
                                    :trooper="trooper"
                                    kind="practice"
                                    :editable="editable"
                                    :disabled-reason="readOnlyReason"
                                    @set="(value) => set(trooper, 'practice', value)"
                                />
                            </div>
                        </div>
                    </template>
                    <EmptyState v-else :icon="PhCheck" title="Nothing needs a look" class="py-12">Everyone is marked and nobody missed.</EmptyState>

                    <div class="mt-5 flex items-center gap-2 py-3">
                        <Button variant="outline" class="h-11 sm:h-9" @click="go(1)"><PhCaretLeft />Back</Button>
                        <Button class="ml-auto h-11 flex-1 sm:h-9 sm:flex-none" @click="go(3)">Next: review and post<PhCaretRight /></Button>
                    </div>
                </template>

                <!-- Step 3: review and post -->
                <template v-else>
                    <div class="mb-1">
                        <h2 :id="`step-${step}-title`" ref="stepHeading" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">
                            Review and post
                        </h2>
                        <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                            Check the totals, copy the forum post into the rollcall thread on 7cav.us, then mark it posted.
                        </p>
                    </div>
                    <div
                        v-if="posted"
                        role="status"
                        :class="
                            cn(
                                'mt-3 flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-[13px]',
                                editedSincePost ? 'border-warning/40 bg-warning/7' : 'border-success/35 bg-success/7',
                            )
                        "
                        data-testid="posted-state"
                    >
                        <PhWarning v-if="editedSincePost" class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                        <PhCheckCircle v-else class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                        <div class="flex-1">
                            <p class="font-medium">Rollcall posted {{ postedLabel }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <template v-if="editedSincePost">Marks changed since then. Repost the updated BBCode, then mark it posted again.</template>
                                <template v-else>Edits after this show up here as changes to repost.</template>
                            </p>
                        </div>
                    </div>
                    <StepReview :troopers="rows" :at-risk="liveAtRisk" @go="go">
                        <template #changes>
                            <section aria-labelledby="review-changes" class="rounded-lg border border-border bg-card" data-testid="changes">
                                <div class="flex items-baseline justify-between gap-3 px-4 pt-3.5 pb-1">
                                    <h3 id="review-changes" class="text-[13px] font-semibold">Changes this visit</h3>
                                    <span class="text-xs text-muted-foreground tabular-nums">{{ changes.length }}</span>
                                </div>
                                <ul v-if="changes.length" class="p-1.5">
                                    <li v-for="change in changes" :key="change.key" class="p-2.5 text-[13px]">
                                        <span class="font-medium">{{ displayName(change.trooper) }}</span>
                                        <span class="ml-1 text-muted-foreground">{{ change.what }}</span>
                                        <span class="ml-1 inline-flex items-center gap-1.5">
                                            <span class="text-muted-foreground line-through decoration-border">{{ change.from }}</span>
                                            <PhCaretRight class="size-3 text-muted-foreground" aria-label="to" />
                                            <span>{{ change.to }}</span>
                                        </span>
                                    </li>
                                </ul>
                                <p v-else class="px-4 pt-1 pb-4 text-xs text-muted-foreground">Nothing changed on this visit.</p>
                            </section>
                        </template>
                        <ForumPost :designation="section.designation" :week-start="week.start" :troopers="rows" />

                        <div class="mt-5 flex flex-wrap items-center gap-2 py-3">
                            <Button variant="outline" class="hidden h-11 sm:inline-flex sm:h-9" @click="go(2)"><PhCaretLeft />Back</Button>
                            <Button variant="outline" class="h-11 flex-1 sm:ml-auto sm:h-9 sm:flex-none" data-testid="copy-bbcode" @click="copyBbcode">
                                <PhCopy />{{ copied ? 'Copy again' : 'Copy BBCode' }}
                            </Button>
                            <Button
                                class="h-11 flex-1 sm:h-9 sm:flex-none"
                                data-testid="mark-posted"
                                :disabled="!editable || posting"
                                :title="readOnlyReason ?? undefined"
                                @click="markPosted"
                            >
                                <PhCheck />{{ posted ? 'Mark posted again' : 'Mark rollcall posted' }}
                            </Button>
                        </div>
                    </StepReview>
                </template>
            </section>

            <!-- Aside: the step's tallies -->
            <aside :class="cn('space-y-4 lg:sticky lg:top-[8.5rem]', !(step === 2 && showUnmatched) && 'hidden lg:block')" aria-label="Step details">
                <!-- Under the marks list on a phone, beside it on a wide screen (capture-review.html C). -->
                <UnmatchedDrawer
                    v-if="step === 2 && showUnmatched"
                    :section-id="section.id"
                    :week="week.date"
                    :players="unmatchedPlayers"
                    :troopers="rows"
                    :editable="editable"
                    :disabled-reason="readOnlyReason"
                    @linked="onLinked"
                    @dismissed="onDismissed"
                />
                <div class="hidden space-y-4 lg:block">
                    <Card v-if="step === 1" class="gap-0 p-4 text-[13px]">
                        <p class="mb-1 font-semibold">This week's thread</p>
                        <p class="text-xs text-muted-foreground">Signatures close {{ closesLabel }}.</p>
                        <dl class="mt-3 divide-y divide-border">
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Will attend</dt><dd class="font-medium tabular-nums">{{ counts.willAttend }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Cannot attend</dt><dd class="font-medium tabular-nums">{{ counts.cannotAttend }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Unsigned</dt><dd :class="cn('font-medium tabular-nums', counts.unsigned && 'text-warning')">{{ counts.unsigned }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">On LOA</dt><dd class="font-medium text-muted-foreground tabular-nums">{{ counts.loa }}</dd></div>
                        </dl>
                    </Card>
                    <Card v-else-if="step === 2" class="gap-0 p-4 text-[13px]">
                        <p class="mb-1 font-semibold">Section practice</p>
                        <p class="text-xs text-muted-foreground">Week of {{ weekLabel }}.</p>
                        <dl class="mt-3 divide-y divide-border">
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Present</dt><dd class="font-medium tabular-nums">{{ counts.present }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Missed</dt><dd :class="cn('font-medium tabular-nums', counts.missed && 'text-destructive')">{{ counts.missed }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Makeup</dt><dd class="font-medium tabular-nums">{{ counts.makeup }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Attached</dt><dd class="font-medium tabular-nums">{{ counts.attached }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Not marked</dt><dd :class="cn('font-medium tabular-nums', counts.unmarked && 'text-warning')">{{ counts.unmarked }}</dd></div>
                            <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">On LOA</dt><dd class="font-medium text-muted-foreground tabular-nums">{{ counts.loa }}</dd></div>
                        </dl>
                    </Card>
                    <Card v-if="step === 3" class="gap-0 p-4 text-[13px]">
                        <p class="mb-2 font-semibold">To finish</p>
                        <ol class="space-y-2.5">
                            <li
                                v-for="(item, index) in [
                                    { label: 'Copy the BBCode', done: copied },
                                    { label: 'Paste it as a reply in the rollcall thread', done: copied && !!posted && !editedSincePost },
                                    { label: 'Mark the rollcall posted', done: !!posted && !editedSincePost },
                                ]"
                                :key="item.label"
                                class="flex items-center gap-2.5"
                            >
                                <span
                                    :class="
                                        cn(
                                            'grid size-6 place-items-center rounded-full border border-border bg-card text-xs font-semibold text-muted-foreground',
                                            item.done && 'border-success/35 bg-success/8 text-success',
                                        )
                                    "
                                    aria-hidden="true"
                                >
                                    <PhCheck v-if="item.done" /><template v-else>{{ index + 1 }}</template>
                                </span>
                                <span :class="item.done && 'text-muted-foreground'">{{ item.label }}</span>
                            </li>
                        </ol>
                    </Card>
                    <Card v-if="editable && step < 3" class="gap-1.5 p-4 text-xs text-muted-foreground pointer-coarse:hidden">
                        <p class="mb-1 text-[13px] font-semibold text-foreground">Keyboard</p>
                        <p class="flex justify-between"><span>Next or previous trooper</span><span><kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">J</kbd> <kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">K</kbd></span></p>
                        <p class="flex justify-between">
                            <span>Set status on focused card</span><span><kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">1</kbd> to <kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">{{ step === 1 ? 3 : 4 }}</kbd></span>
                        </p>
                    </Card>
                </div>
            </aside>
        </div>

        <Dialog v-model:open="pickerOpen">
            <DialogContent class="gap-3 p-4 sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Switch section</DialogTitle>
                    <DialogDescription>Open another section's rollcall for the same week.</DialogDescription>
                </DialogHeader>
                <SectionPicker :sections="sections" :current-id="section.id" :week="weekQuery" @picked="pickerOpen = false" />
            </DialogContent>
        </Dialog>
    </div>
</template>
