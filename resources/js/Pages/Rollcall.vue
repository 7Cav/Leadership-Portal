<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhArrowsClockwise,
    PhBroadcast,
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
    PhLockSimple,
    PhClock,
    PhCheckCircle,
    PhClockCounterClockwise,
    PhCopy,
    PhEye,
    PhUsersThree,
    PhUserCircleDashed,
    PhWarning,
    PhX,
} from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import EmptyState from '@/components/EmptyState.vue';
import FireteamPanel from '@/components/rollcall/FireteamPanel.vue';
import AttendanceSourceLegend from '@/components/attendance/AttendanceSourceLegend.vue';
import SectionPicker from '@/components/rollcall/SectionPicker.vue';
import ReportDeadlineBanner from '@/components/reports/ReportDeadlineBanner.vue';
import TelemetryBanner from '@/components/rollcall/TelemetryBanner.vue';
import TimeAgo from '@/components/TimeAgo.vue';
import StepReview from '@/components/rollcall/StepReview.vue';
import TrooperCard from '@/components/rollcall/TrooperCard.vue';
import UnmatchedDrawer from '@/components/rollcall/UnmatchedDrawer.vue';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import ForumPost from '@/components/rollcall/ForumPost.vue';
import { copyText } from '@/lib/clipboard';
import { messageFor, unreachable, useRollcallAutosave } from '@/composables/useRollcallAutosave';
import { orderTroopers } from '@/lib/fireteams';
import { postJson } from '@/lib/postJson';
import { rollcallPost } from '@/lib/rollcallBbcode';
import { displayKeys, isShortcut, keyLabel, shortcutIndex, SHORTCUTS } from '@/lib/shortcuts';
import {
    CAPTURE_SOURCES,
    countsOf,
    displayName,
    feedLabel,
    isLocked,
    landingStep,
    optionsFor,
    practiceGroups,
    practiceLabel,
    signatureGroups,
    signatureLabel,
    stepsOf,
    unlinkedLabel,
    weeksNeedingWork,
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
    // `{ start, date, closes, isCurrent, previous, next, current, beforeFloor }` (AD-11).
    week: { type: Object, required: true },
    // `[{ id, cavId, rank, name, rollcall, status, source, priorStatus, loa, joined, history, role, fireteamLeaderId }]`. `loa` is the
    // trooper's leave for the week (`LoaCover::toArray()`, covered or needs review), else null (3.4, 4.4). `history` is the last four
    // completed weeks' SP marks for the card's attendance strip: `[{ week, status, held, loa }]`, oldest first. `role` is
    // `sl`, `asl`, `ftl` or null.
    troopers: { type: Array, default: () => [] },
    // Where the leave data comes from and when it last synced (`LoaSyncState::toArray()`, 4.3, AD-20).
    loaSync: { type: Object, default: null },
    priorWeekMarked: { type: Boolean, default: false },
    // `[{ date, unmarked, posted }]`: the last few past weeks, for flagging unfinished ones on the
    // week navigation.
    weekStatus: { type: Array, default: () => [] },
    // `[{ trooperId, missed, windowWeeks }]`, from the shared KPI service (AD-21).
    atRisk: { type: Array, default: () => [] },
    // `{ source, syncedAt }` (AD-20).
    roster: { type: Object, default: null },
    practice: { type: Object, default: null },
    // `{ source, capturedAt }` once a capture run stamped this practice, else null (7.7). Drives the
    // capture banner and the confirm action (7.8).
    capture: { type: Object, default: null },
    // Why there is no capture, when the server could not be read or is not set up (7.9):
    // `{ state: 'failed' | 'not_configured', platformLabel, feeds, lastAttemptAt, canRetry }`, else null. `feeds` are the
    // game's sources a capture tries (`crcon`, ...), so the banner names the feed that is down (#176).
    telemetry: { type: Object, default: null },
    // `[{ platform, platformLabel, playerName, platformId }]`: players the capture saw that no
    // trooper is linked to (7.7).
    unmatched: { type: Array, default: () => [] },
    // The same shape: players this practice's capture saw that a leader dismissed, for the drawer's
    // "Dismissed" list and its Undo (#176).
    dismissed: { type: Array, default: () => [] },
    // The company's active rollcall template, resolved on this request (3.6, 3.11):
    // `{ state, companyId, versionId, version, body }`. Null for the picker.
    template: { type: Object, default: null },
    // Whether the viewer may write here: false for a section they only see through a staff grant,
    // which reads but never marks, confirms or posts (the server refuses those writes).
    canWrite: { type: Boolean, default: false },
    // The section's weekly report for this week has been sent (AD-12): the rollcall is read-only.
    locked: { type: Boolean, default: false },
    // The section's report for this week: `{ due, overdueUntil, sent, href }`, for the deadline banner. Null for the picker.
    report: { type: Object, default: null },
    // How the post shows fireteams and who may change it (`SectionFireteams::options()` on the server). Null for the picker.
    fireteams: { type: Object, default: null },
});

const page = usePage();

// The screen's working copy of the marks, and what they were when the page loaded (the review
// step lists the difference as this visit's changes). A reload that answers while one of the
// leader's marks is not saved yet keeps that mark on screen: it saves after the server read the page.
const rows = ref([]);
const baseline = ref(new Map());
watch(
    () => props.troopers,
    (troopers) => {
        const shown = new Map(rows.value.map((trooper) => [trooper.id, trooper]));
        rows.value = troopers.map((trooper) => {
            const row = { ...trooper };
            const mine = shown.get(trooper.id);
            if (mine && autosave?.isPending(trooper.id, 'status')) Object.assign(row, { status: mine.status, source: mine.source });
            if (mine && autosave?.isPending(trooper.id, 'rollcall')) row.rollcall = mine.rollcall;

            return row;
        });
        baseline.value = new Map(troopers.map((trooper) => [trooper.id, { rollcall: trooper.rollcall, status: trooper.status, source: trooper.source }]));
    },
    { immediate: true },
);

// The section in the order its post lists it (the owner's order, 2026-10-16): SL, ASL, fireteam leaders when shown,
// then everyone by rank. Every list on the screen follows it.
const fireteams = ref(props.fireteams);
watch(() => props.fireteams, (value) => (fireteams.value = value));
const orderedRows = computed(() => orderTroopers(rows.value, fireteams.value ?? {}));

// A fireteam change was saved: take the roles and options the server now holds. A post already on the forum lists
// the old order, so it needs reposting when the BBCode changed.
function onFireteams({ fireteams: options, troopers }) {
    const before = bbcode.value;
    const byId = new Map(troopers.map((trooper) => [trooper.id, trooper]));
    for (const row of rows.value) {
        const saved = byId.get(row.id);
        if (saved) Object.assign(row, { role: saved.role, leadsFireteam: saved.leadsFireteam, fireteamLeaderId: saved.fireteamLeaderId });
    }
    fireteams.value = options;
    if (posted.value && bbcode.value !== before) editedSincePost.value = true;
}

// The players still waiting for a trooper: leaves the list as the leader links or dismisses them.
const unmatchedPlayers = ref(props.unmatched);
watch(() => props.unmatched, (unmatched) => (unmatchedPlayers.value = unmatched));
const showUnmatched = computed(() => Boolean(props.capture) && unmatchedPlayers.value.length > 0);
// How many are left, for the stepper and the review step (#176): a leader on any step sees that someone who was
// online is not accounted for yet. It follows the drawer, so it drops as each player is linked or dismissed.
const unlinkedCount = computed(() => (showUnmatched.value ? unmatchedPlayers.value.length : 0));
// The players dismissed for this practice (#176): the drawer stays while any are, so a dismissal can be undone
// after the toast is gone, even once nobody is left to link.
const dismissedPlayers = ref(props.dismissed);
watch(() => props.dismissed, (dismissed) => (dismissedPlayers.value = dismissed));
const showDrawer = computed(() => Boolean(props.capture) && (unmatchedPlayers.value.length > 0 || dismissedPlayers.value.length > 0));

const liveAtRisk = ref(props.atRisk);
watch(() => props.atRisk, (atRisk) => (liveAtRisk.value = atRisk));

// A view-as preview (Story 1.13) and a staff grant are read-only: the server refuses every write
// then, so the screen says so up front instead of letting saves fail. So is a week before the week floor.
const viewAs = computed(() => page.props.viewAs ?? null);
// Why nothing here may be written at all, posting included: a preview, a staff grant, or a week
// before the week floor. A sent report's lock is separate below, since it leaves posting open.
const previewReason = computed(() => {
    if (viewAs.value) return `Read-only while viewing as ${viewAs.value.label}`;
    if (!props.canWrite) return 'Read-only: a staff grant lets you view this section, not mark it';
    return props.week.beforeFloor ? 'Read-only, this week is too far back to change' : null;
});

const LOCKED_MESSAGE = 'The report for this week has been sent, so its rollcall is locked.';

const autosave = props.section
    ? useRollcallAutosave({
          url: `/rollcall/${props.section.id}`,
          week: props.week.date,
          onSaved: (data) => {
              liveAtRisk.value = data.atRisk ?? liveAtRisk.value;
              // A hand edit of a mark clears an earlier confirmation on the server (7.8).
              if ('confirmation' in data) confirmation.value = data.confirmation;
          },
          // A leave synced after the page loaded: the server refused their practice mark (3.4). The row
          // takes back what it held and locks, as it would have on load; its signature still saves.
          onLeave: (ids) => {
              for (const id of ids) {
                  const row = rows.value.find((t) => t.id === id);
                  const was = baseline.value.get(id);
                  if (!row) continue;
                  if (was) Object.assign(row, { status: was.status, source: was.source });
                  row.loa ??= { state: 'covered', fromSave: true };
              }
          },
          // Moved out of the section since the page loaded: their marks were not saved, so the row takes back what it
          // held, and the counts and the forum post do not show a mark the server never recorded.
          onRejected: (ids) => {
              for (const id of ids) {
                  const row = rows.value.find((t) => t.id === id);
                  const was = baseline.value.get(id);
                  if (row && was) Object.assign(row, was);
              }
          },
          // The report was sent while this screen was open: reload the frozen marks, and `locked`.
          onLocked: () => router.reload(),
          nameOf: (id) => displayName(rows.value.find((t) => t.id === id) ?? { cavId: id }),
      })
    : null;

// A week whose report has been sent is locked (AD-12), read-only the same way as a preview: the
// server refuses every save for it. Known from the page load, or from a save refused because the
// report was sent since. The forum post stays usable: copying it and marking it posted are fine.
const weekLocked = computed(() => props.locked || Boolean(autosave?.locked.value));
const lockedReason = computed(() => (weekLocked.value ? (autosave?.lockedMessage.value ?? LOCKED_MESSAGE) : null));
const editable = computed(() => !previewReason.value && !weekLocked.value);
const readOnlyReason = computed(() => previewReason.value ?? lockedReason.value);

function set(trooper, kind, value, options = {}) {
    if (!editable.value || isLocked(trooper)) return;
    const field = kind === 'signature' ? 'rollcall' : 'status';
    if (trooper[field] === value) return;

    trooper[field] = value;
    edits++;
    if (field === 'status') {
        trooper.source = 'manual';
        practiceEdits.set(trooper.id, (practiceEdits.get(trooper.id) ?? 0) + 1);
    }
    if (posted.value) editedSincePost.value = true;
    autosave.queue(trooper.id, field, value, options);
}

// "Mark all present", "Mark the rest missed" and "use last week's marks" set many rows at once. Their saves are held for
// the undo window, because a saved practice mark cannot be cleared again (3.3).
const UNDO_MS = 6000;

// Undo toasts still open; marking posted saves their held changes early, so it closes them.
const undoToasts = new Set();

// Save the held changes now and close their Undo toasts: once a change is saved, Undo could only
// answer "already saved". Marking posted and confirming need the marks on the server first, and
// moving to another step commits them too: an Undo is for the step it was made on, and a hold must
// not outlive it (the step change keeps this screen, but browser Back to a step rebuilds it).
function commitHeld() {
    for (const id of undoToasts) toast.dismiss(id);
    undoToasts.clear();

    return autosave.flush({ includeHeld: true });
}

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

// Practice marks set on this screen, counted per trooper, and the count when each link was sent:
// a link answer must not overwrite a mark the leader set while it was in flight. `edits` counts
// every mark, for the same check on marking the rollcall posted.
let edits = 0;
const practiceEdits = new Map();
const editsAtLink = new Map();

// Before a link is sent, the marks waiting in the autosave go first, so the server's manual-wins
// pre-mark sees a mark the leader just set instead of racing it. Held marks (an open Undo) stay held.
function beforeLink(trooperId) {
    editsAtLink.set(trooperId, practiceEdits.get(trooperId) ?? 0);

    return autosave.flush();
}

// The server linked the player and pre-marked the trooper (a manual mark is kept, so the row takes
// what the server says it now holds), unless the leader's own mark is newer than that answer: still
// queued or held, or set while the link was in flight. That mark saves as manual and wins.
function onLinked({ player, marked, trooper, atRisk }) {
    unmatchedPlayers.value = unmatchedPlayers.value.filter((p) => !sameUnmatched(p, player));
    const row = rows.value.find((t) => t.id === trooper.id);
    const newerHere = autosave.isPending(trooper.id, 'status') || (practiceEdits.get(trooper.id) ?? 0) !== (editsAtLink.get(trooper.id) ?? 0);
    editsAtLink.delete(trooper.id);
    if (row && trooper.status && !newerHere) {
        Object.assign(row, { status: trooper.status, source: trooper.source });
        // A mark written after the forum post makes that post stale, as a hand edit does.
        if (marked && posted.value) editedSincePost.value = true;
    }
    liveAtRisk.value = atRisk ?? liveAtRisk.value;
    const name = row ? displayName(row) : `trooper ${trooper.id}`;
    if (newerHere) toast.success(`Linked ${player.playerName} to ${name}`, { description: 'The mark you just set stays.' });
    else if (marked) toast.success(`Linked ${player.playerName} to ${name}`, { description: 'Marked present for this practice.' });
    else if (confirmation.value) toast.success(`Linked ${player.playerName} to ${name}`, { description: 'The practice is confirmed, so its marks were not changed.' });
    else toast.success(`Linked ${player.playerName} to ${name}`, { description: 'Their existing mark was kept.' });
}

// Both lists are kept in the server's order, by name, so a player moved between them lands where a reload puts them.
const byName = (a, b) => a.playerName.localeCompare(b.playerName, undefined, { sensitivity: 'base' });
const moveTo = (list, player) => [...list.filter((p) => !sameUnmatched(p, player)), player].sort(byName);

function onDismissed(player) {
    unmatchedPlayers.value = unmatchedPlayers.value.filter((p) => !sameUnmatched(p, player));
    dismissedPlayers.value = moveTo(dismissedPlayers.value, player);
    toast.success(`Dismissed ${player.playerName}`, {
        description: 'They stay off the unmatched lists until linked to a trooper.',
        action: { label: 'Undo', onClick: () => restoreDismissed(player) },
    });
}

// Undo a dismissal (#176), from the toast or the drawer's "Dismissed" list: one audited save on the server, then the
// player is back on this practice's list. Resolves whether it worked; a refusal is said in a toast.
async function restoreDismissed(player) {
    const result = await postJson(`/rollcall/${props.section.id}/unmatched/restore`, {
        week: props.week.date,
        platform: player.platform,
        platform_id: player.platformId,
    }).catch(unreachable);
    if (!result.ok) {
        toast.error(`Could not undo dismissing ${player.playerName}`, { description: messageFor(result) });

        return false;
    }
    dismissedPlayers.value = dismissedPlayers.value.filter((p) => !sameUnmatched(p, player));
    unmatchedPlayers.value = moveTo(unmatchedPlayers.value, player);
    toast.success(`${player.playerName} is listed again`, { description: 'Link them to a trooper, or dismiss them again.' });

    return true;
}

const unmarked = computed(() => rows.value.filter((t) => !isLocked(t) && t.status === null));
const prefillable = computed(() => unmarked.value.filter((t) => t.priorStatus && t.priorStatus !== 'loa'));

function markAllPresent() {
    const entries = unmarked.value.map((trooper) => ({ trooper, value: 'present' }));
    applyMany(entries, `Marked ${entries.length} as present`);
}

// Everyone still unmarked missed the practice: the same rows as "Mark all present" (LOA rows are
// locked and skipped), held for the same Undo window.
function markRestMissed() {
    if (!editable.value) return;
    const entries = unmarked.value.map((trooper) => ({ trooper, value: 'missed' }));
    applyMany(entries, `Marked ${entries.length} as missed`);
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

    const kind = step.value === 1 ? 'signature' : 'practice';
    const mark = shortcutIndex(event, `rollcall.${kind}`);

    // The keys come from lib/shortcuts.js, which also feeds the `?` list and the keyboard card below.
    if (isShortcut(event, 'rollcall.next')) {
        event.preventDefault();
        faces[index + 1]?.focus();
    } else if (isShortcut(event, 'rollcall.previous')) {
        event.preventDefault();
        faces[index - 1]?.focus();
    } else if (mark >= 0) {
        const id = Number(face.closest('[data-trooper]').dataset.trooper);
        const option = optionsFor(kind)[mark];
        const trooper = rows.value.find((t) => t.id === id);
        if (!option || !trooper) return;
        event.preventDefault();
        set(trooper, kind, option.value);
        // The card may have moved to another group; keep the focus on the same trooper.
        nextTick(() => document.querySelector(`[data-trooper="${id}"] [data-card-face]`)?.focus());
    }
}

// The number keys the keyboard card shows for this step: "1 to 3" for signatures, "1 to 4" for marks.
const markKeys = computed(() => displayKeys(SHORTCUTS[step.value === 1 ? 'rollcall.signature' : 'rollcall.practice']).map(keyLabel));

// What changed on this visit, for the review step.
const changes = computed(() =>
    orderedRows.value.flatMap((trooper) => {
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

// The forum post (3.10, 3.11): the company's active template filled with the current marks, generated
// client-side and copied by the leader. The portal makes no outbound call on copy or on marking posted (AD-20).
const post = computed(() =>
    props.section ? rollcallPost(props.template, { section: props.section, week: props.week, troopers: rows.value, fireteams: fireteams.value ?? {} }) : null,
);
const bbcode = computed(() => (post.value?.state === 'ready' ? post.value.text : ''));
const copied = ref(false);
const posting = ref(false);

async function copyBbcode() {
    if (post.value?.state !== 'ready') return;

    const ok = await copyText(bbcode.value, 'BBCode copied', {
        description: `Paste it as a reply in the ${props.section.designation} rollcall thread.`,
        failed: 'Could not copy the BBCode',
        fallback: 'Open the BBCode tab and copy it by hand.',
    });
    if (ok) copied.value = true;
}

async function markPosted() {
    posting.value = true;
    try {
        // The post on the forum was made from the marks on screen, so they must be saved first.
        if (!(await commitHeld())) {
            toast.error('Changes are not saved yet', { description: autosave.error.value ?? autosave.lockedMessage.value });

            return;
        }
        // A mark changed while this request is in flight saves after the post was stamped: stale.
        const editsBefore = edits;
        const result = await postJson(`/rollcall/${props.section.id}/posted`, { week: props.week.date }).catch(unreachable);
        if (result.ok && result.data?.posted) {
            posted.value = result.data.posted;
            editedSincePost.value = edits !== editsBefore;
            toast.success('Rollcall marked posted', { description: `${props.section.designation}, week of ${weekLabel.value}` });
        } else {
            toast.error('Could not mark the rollcall posted', { description: messageFor(result) });
        }
    } finally {
        posting.value = false;
    }
}

// Capture review (7.8): where the marks came from and when (AD-20), and the leader's confirmation
// of them. Confirming is its own audited save, so held and pending marks are saved first.
// A capture with no source recorded reads as "the game server", never as "null".
const captureLabel = computed(() => (props.capture ? (CAPTURE_SOURCES[props.capture.source] ?? props.capture.source ?? 'the game server') : null));
const capturedLabel = computed(() => (props.capture ? `${forumDateTime(props.capture.capturedAt)}Z` : null));
const confirmation = ref(props.practice?.confirmation ?? null);
const confirming = ref(false);
const confirmationLabel = computed(() =>
    confirmation.value ? `Confirmed ${forumDateTime(confirmation.value.at)}Z${confirmation.value.by ? ` by ${confirmation.value.by}` : ''}` : null,
);

async function confirmPractice() {
    confirming.value = true;
    try {
        if (!(await commitHeld())) {
            toast.error('Changes are not saved yet', { description: autosave.error.value ?? autosave.lockedMessage.value });

            return;
        }
        // The confirmation on screen goes along, so a leader who confirms just after another one
        // gets theirs back instead of a second stamp (7.8).
        const result = await postJson(`/rollcall/${props.section.id}/confirm`, {
            week: props.week.date,
            confirmedAt: confirmation.value?.at ?? null,
        }).catch(unreachable);
        if (result.ok && result.data?.confirmation) {
            confirmation.value = result.data.confirmation;
            toast.success('Practice confirmed', { description: `${props.section.designation}, week of ${weekLabel.value}` });
        } else {
            toast.error('Could not confirm the practice', { description: messageFor(result) });
            // The report was sent since the page loaded: show the week as the server now has it.
            if (result.status === 409 && result.data?.locked) router.reload();
        }
    } finally {
        confirming.value = false;
    }
}

// "Try again" on the telemetry banner (7.9): queue a fresh capture, then poll the practice's state
// until the run reports back. The run is asynchronous, so a failure is said out loud when it arrives
// and a success reloads the page to show the pre-filled marks; the leader can mark by hand throughout.
const RETRY_POLL_MS = 2500;
const RETRY_POLLS = 24;
const retrying = ref(false);

// The poll belongs to this screen: leaving it (another week, another page) ends it at once, so it
// never reloads or toasts over whatever is shown next.
let left = false;
let leave;
const leaving = new Promise((resolve) => (leave = resolve));
onBeforeUnmount(() => {
    left = true;
    leave();
    // The autosave sends the held marks as the screen goes, so an Undo toast left open on the next
    // page could not take them back.
    for (const id of undoToasts) toast.dismiss(id);
    undoToasts.clear();
});

const pause = (ms) =>
    new Promise((resolve) => {
        const timer = setTimeout(resolve, ms);
        leaving.then(() => {
            clearTimeout(timer);
            resolve();
        });
    });
// A partial reload of this page: the autosave's leave guard lets it through (it is not leaving).
const refreshTelemetry = () =>
    Promise.race([
        new Promise((resolve) => router.reload({ only: ['telemetry', 'capture'], preserveState: true, preserveScroll: true, onFinish: resolve })),
        leaving,
    ]);

async function retryCapture() {
    retrying.value = true;
    try {
        const before = props.telemetry?.lastAttemptAt ?? null;
        const result = await postJson(`/rollcall/${props.section.id}/capture`, { week: props.week.date }).catch(unreachable);
        if (left) return;
        if (!result.ok) {
            toast.error('Could not try again', { description: messageFor(result) });
            if (result.status === 409 && result.data?.locked) router.reload();

            return;
        }

        for (let poll = 0; poll < RETRY_POLLS; poll++) {
            await pause(RETRY_POLL_MS);
            if (left) return;
            await refreshTelemetry();
            if (left) return;

            if (props.capture) {
                // Marks were pre-filled on the server: save what is pending, then show them.
                const saved = await autosave.flush({ includeHeld: true });
                if (left) return;
                if (!saved) {
                    toast.error('Changes are not saved yet', { description: autosave.error.value ?? autosave.lockedMessage.value });

                    return;
                }
                toast.success(`Read ${captureLabel.value}`, { description: 'Marks were filled in. Check them, then confirm.' });
                router.reload({ preserveScroll: true });

                return;
            }
            if ((props.telemetry?.lastAttemptAt ?? null) !== before) {
                toast.error(`${feedLabel(props.telemetry)} still could not be read`, { description: 'Nothing was pre-filled. Mark attendance by hand.' });

                return;
            }
        }
        toast.warning('Still waiting for the game server', { description: 'The check is queued. Reload in a moment to see the result.' });
    } finally {
        retrying.value = false;
    }
}

const postedLabel = computed(() => (posted.value ? `${forumDateTime(posted.value.at)}Z` : null));

// `22:55Z` of the last save, while that is what the save state reads.
const savedClock = computed(() => {
    if (!autosave || ['saving', 'error'].includes(autosave.state.value) || !autosave.savedAt.value) return null;

    return `${forumDateTime(autosave.savedAt.value).split(' ')[1]}Z`;
});

const saveLabel = computed(() => {
    if (!autosave) return null;
    if (autosave.state.value === 'saving') return 'Saving...';
    if (autosave.state.value === 'error') return 'Not saved';
    if (savedClock.value) return `Saved ${savedClock.value}`;
    if (autosave.pendingCount.value) return 'Unsaved changes';

    return null;
});

// Rollcall posted to the forum (3.10): when and by whom, and whether marks changed since — then
// the post on the forum is stale and needs reposting.
const markedSince = (practice) =>
    Boolean(practice?.posted && practice.lastMarkedAt && new Date(practice.lastMarkedAt) > new Date(practice.posted.at));
const posted = ref(props.practice?.posted ?? null);
const editedSincePost = ref(markedSince(props.practice));

// A reload that keeps this screen (the capture retry's, or any preserve-state visit) brings a fresh
// `practice`: posted and confirmed are re-read from it, not kept from the first load. An edit seen
// here since the same post stays flagged even before its save reaches the server.
watch(
    () => props.practice,
    (practice) => {
        const samePost = Boolean(posted.value && practice?.posted && practice.posted.at === posted.value.at);
        editedSincePost.value = markedSince(practice) || (samePost && editedSincePost.value);
        posted.value = practice?.posted ?? null;
        confirmation.value = practice?.confirmation ?? null;
    },
);

const counts = computed(() => countsOf(rows.value));
const steps = computed(() => stepsOf(rows.value, posted.value && !editedSincePost.value ? posted.value.at : null, unlinkedCount.value));

// The step is part of the URL (`?step=2`), so a step can be linked to and Back walks the steps.
// Without one the rollcall opens on the first step with work left. The rollcall thread closes on
// the week's last minute, so only the current week's signatures are still open. Worked out once on
// load: marking the last trooper of a step does not move the screen.
const landing = props.section ? landingStep(props.troopers, props.week.isCurrent) : 1;
const query = computed(() => new URL(page.url, 'http://portal.local').searchParams);
const step = computed(() => {
    const requested = Number(query.value.get('step'));

    if ([1, 2, 3].includes(requested)) return requested;

    // A milpac jump (`?focus=`) needs the trooper's card on screen, and the review step has none: a week with no work
    // left opens on the practice step instead.
    return query.value.has('focus') ? Math.min(landing, 2) : landing;
});
const stepHeading = ref(null);

// A trooper's milpac links here with `?focus=<trooper id>` ("Jump to rollcall"): the page opens on that trooper's card,
// scrolled into view, focused and outlined. It applies once, on arrival; moving to another step drops it. A trooper
// who is not on this section's roster this week has no card, and the page simply opens at the top.
const spotlight = ref(null);
function focusTrooper(id) {
    const card = document.querySelector(`[data-trooper="${id}"]`);
    if (!card) return;
    spotlight.value = id;
    card.scrollIntoView?.({ block: 'center' });
    card.querySelector('[data-card-face]')?.focus({ preventScroll: true });
}
onMounted(() => {
    const id = Number(query.value.get('focus'));
    if (Number.isInteger(id) && id > 0) nextTick(() => focusTrooper(id));
});

// Moving to another step commits the marks held for Undo (see `commitHeld`): the save goes now, while
// this screen is still here to show whether it worked.
function go(n) {
    if (autosave && n !== step.value) commitHeld();
    const params = new URLSearchParams(query.value);
    params.set('step', String(n));
    params.delete('focus');
    spotlight.value = null;
    router.push({ url: `${new URL(page.url, 'http://portal.local').pathname}?${params}`, preserveState: true, preserveScroll: true });
    nextTick(() => stepHeading.value?.focus());
}

function weekHref(date) {
    const params = date && date !== props.week.current ? `?week=${date}` : '';

    return `/rollcall/${props.section.id}${params}`;
}

const weekQuery = computed(() => (props.week.isCurrent ? null : props.week.date));

// The picker itself, even for a leader the server would land on a section (`?pick`).
const pickerHref = computed(() => `/rollcall?pick=1${weekQuery.value ? `&week=${weekQuery.value}` : ''}`);
const weekLabel = computed(() => forumDate(props.week.start));
const closesLabel = computed(() => {
    const at = new Date(props.week.closes);

    return `${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][at.getUTCDay()]} ${forumDateTime(at)}Z`;
});
const syncedLabel = computed(() => (props.roster?.syncedAt ? `${forumDateTime(props.roster.syncedAt)}Z` : null));

// Past weeks left unfinished are flagged on the arrow that leads to them, so none is forgotten.
const unfinished = computed(() => weeksNeedingWork(props.weekStatus, props.week.date));
function unfinishedLabel(weeks, direction) {
    if (!weeks.length) return null;
    const list = weeks.map((week) => `${forumDate(week.date)} (${week.what})`).join(', ');

    return `${weeks.length} ${direction} ${weeks.length === 1 ? 'week needs' : 'weeks need'} work: ${list}`;
}
const earlierLabel = computed(() => unfinishedLabel(unfinished.value.earlier, 'earlier'));
const laterLabel = computed(() => unfinishedLabel(unfinished.value.later, 'later'));

const pickerOpen = ref(false);
const practiceFilter = ref('all');

const signatureView = computed(() => signatureGroups(orderedRows.value));
const practiceView = computed(() => practiceGroups(orderedRows.value, practiceFilter.value === 'open'));
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
                <p class="flex flex-wrap gap-x-1.5 text-xs text-muted-foreground">
                    <span>{{ section.designation }} weekly cycle</span>
                    <template v-if="sections.length > 1">
                        <span aria-hidden="true">·</span>
                        <Link :href="pickerHref" class="underline-offset-2 hover:text-foreground hover:underline" data-testid="choose-section">Choose a section</Link>
                    </template>
                </p>
                <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]">Rollcall, week of {{ weekLabel }}</h1>
            </div>
            <div class="flex items-center gap-1 text-muted-foreground">
                <Button variant="ghost" size="icon-sm" class="relative" as-child>
                    <Link
                        :href="weekHref(week.previous)"
                        :aria-label="earlierLabel ? `Previous week. ${earlierLabel}` : 'Previous week'"
                        :title="earlierLabel ?? undefined"
                        data-testid="previous-week"
                        preserve-scroll
                    >
                        <PhCaretLeft />
                        <span
                            v-if="earlierLabel"
                            class="absolute top-0.5 right-0.5 size-2 rounded-full bg-warning ring-2 ring-background"
                            aria-hidden="true"
                            data-testid="earlier-weeks-flag"
                        />
                    </Link>
                </Button>
                <Button v-if="!week.isCurrent" variant="ghost" size="sm" as-child>
                    <Link :href="weekHref(week.current)">This week</Link>
                </Button>
                <Button v-else variant="ghost" size="sm" disabled>This week</Button>
                <Button v-if="week.next" variant="ghost" size="icon-sm" class="relative" as-child>
                    <Link
                        :href="weekHref(week.next)"
                        :aria-label="laterLabel ? `Next week. ${laterLabel}` : 'Next week'"
                        :title="laterLabel ?? undefined"
                        data-testid="next-week"
                        preserve-scroll
                    >
                        <PhCaretRight />
                        <span
                            v-if="laterLabel"
                            class="absolute top-0.5 right-0.5 size-2 rounded-full bg-warning ring-2 ring-background"
                            aria-hidden="true"
                            data-testid="later-weeks-flag"
                        />
                    </Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next week"><PhCaretRight /></Button>
                <Button v-if="sections.length > 1" variant="outline" size="sm" class="ml-1" @click="pickerOpen = true">
                    {{ section.designation }}<PhCaretUpDown class="text-muted-foreground" />
                    <span class="sr-only">Switch section</span>
                </Button>
            </div>
            <div v-if="capture || telemetry" class="flex items-center gap-2 sm:ml-auto" data-testid="confirm-cluster">
                <Badge
                    :variant="confirmation ? 'secondary' : 'outline'"
                    :class="cn('font-normal', confirmation && 'border-success/35 bg-success/8 text-success')"
                    data-testid="confirm-badge"
                >
                    <PhCheckCircle v-if="confirmation" aria-hidden="true" />{{ confirmation ? 'Confirmed' : 'Not confirmed' }}
                </Badge>
                <Button
                    size="sm"
                    :variant="confirmation ? 'outline' : 'default'"
                    :disabled="!editable || confirming"
                    :title="readOnlyReason ?? confirmationLabel ?? undefined"
                    data-testid="confirm-practice"
                    @click="confirmPractice"
                >
                    <PhCheck />{{ confirmation ? 'Confirm again' : 'Confirm practice' }}
                </Button>
            </div>
            <div :class="cn('flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground sm:w-auto', capture || telemetry ? 'sm:ml-0' : 'sm:ml-auto')">
                <span class="inline-flex items-center gap-1.5" data-testid="roster-provenance">
                    <PhArrowsClockwise class="size-4" aria-hidden="true" />
                    <template v-if="syncedLabel">Roster from {{ roster.source }}, synced <TimeAgo :at="roster.syncedAt">{{ syncedLabel }}</TimeAgo></template>
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
                        <span :class="autosave.state.value === 'saving' && 'text-muted-foreground'">
                            <template v-if="savedClock">Saved <TimeAgo :at="autosave.savedAt.value" quiet>{{ savedClock }}</TimeAgo></template>
                            <template v-else>{{ saveLabel }}</template>
                        </span>
                    </template>
                </span>
            </div>
        </div>

        <ReportDeadlineBanner v-if="report" class="mt-3" :due="report.due" :overdue-until="report.overdueUntil" :sent="report.sent" :href="report.href" :read-only="!editable" />

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
            v-if="autosave?.onLeaveMessage.value"
            role="status"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-border px-3.5 py-2.5 text-[13px]"
            data-testid="save-on-leave"
        >
            <PhLockSimple class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <p class="min-w-0 flex-1">{{ autosave.onLeaveMessage.value }} Reload to see their leave dates.</p>
        </div>

        <div
            v-if="previewReason"
            role="status"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-info/35 bg-info/7 px-3.5 py-2.5 text-[13px]"
            data-testid="read-only-notice"
        >
            <PhEye class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
            <p>{{ previewReason }}.<template v-if="viewAs"> Exit view as to mark attendance.</template></p>
        </div>

        <div
            v-if="lockedReason"
            role="status"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-info/35 bg-info/7 px-3.5 py-2.5 text-[13px]"
            data-testid="locked-notice"
        >
            <PhLockSimple class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
            <p>{{ lockedReason }} You can still copy the forum post and mark it posted.</p>
        </div>

        <div
            v-if="!week.isCurrent && !weekLocked"
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
            class="sticky top-[var(--shell-top,3rem)] z-20 -mx-4 mt-3 border-b border-border bg-background/95 px-4 pt-3 pb-2 backdrop-blur sm:-mx-6 sm:px-6"
        >
            <nav aria-label="Rollcall steps">
                <ol class="grid grid-cols-3 gap-1.5">
                    <li v-for="s in steps" :key="s.n">
                        <button
                            type="button"
                            :aria-current="step === s.n ? 'step' : undefined"
                            :aria-label="`Step ${s.n}, ${s.title}, ${s.sub}${s.unlinked ? `, ${unlinkedLabel(s.unlinked)}` : ''}`"
                            :class="
                                cn(
                                    'relative grid h-full min-h-[3.75rem] w-full grid-cols-1 items-center justify-items-center gap-x-2.5 gap-y-0.5 rounded-md px-1 pt-1.5 pb-3 text-center hover:bg-accent/70 sm:min-h-14 sm:grid-cols-[auto_minmax(0,1fr)] sm:justify-items-start sm:px-2.5 sm:pt-2 sm:text-left',
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
                            <!-- Shown on every step and every width (#176): the step leads to the drawer that resolves them. -->
                            <Badge
                                v-if="s.unlinked"
                                variant="outline"
                                class="max-w-full truncate border-warning/40 bg-warning/8 px-1.5 text-[11px] font-normal text-warning tabular-nums sm:col-start-2"
                                data-testid="unlinked-count"
                            >
                                {{ unlinkedLabel(s.unlinked) }}
                            </Badge>
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
                                :loa-sync="loaSync"
                                kind="signature"
                                :editable="editable"
                                :disabled-reason="readOnlyReason"
                                :spotlight="spotlight === trooper.id"
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
                        v-if="capture"
                        role="status"
                        class="mt-3 flex flex-wrap items-start gap-x-2.5 gap-y-2 rounded-lg border border-info/35 bg-info/7 px-3.5 py-2.5 text-[13px]"
                        data-testid="capture-banner"
                    >
                        <PhBroadcast class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                        <p class="min-w-[14rem] flex-1">
                            Marks filled from {{ captureLabel }}, read <TimeAgo :at="capture.capturedAt" quiet>{{ capturedLabel }}</TimeAgo>.
                            <template v-if="showUnmatched">
                                <b class="font-semibold" data-testid="unmatched-banner">{{ unmatchedPlayers.length }} {{ unmatchedPlayers.length === 1 ? 'player needs' : 'players need' }} a trooper.</b>
                                Link or dismiss them in the panel.
                            </template>
                            <template v-else>Check them, fix any that are wrong, then confirm.</template>
                        </p>
                        <AttendanceSourceLegend class="w-full sm:w-auto" />
                    </div>

                    <TelemetryBanner v-if="telemetry" :telemetry="telemetry" :retrying="retrying" :editable="editable" @retry="retryCapture" />

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
                        <AttendanceSourceLegend v-if="!capture && !telemetry" class="w-full sm:w-auto" />
                        <Button v-if="editable && prefillable.length" variant="outline" size="sm" data-testid="use-last-week" @click="useLastWeek">
                            Use last week's marks ({{ prefillable.length }})
                        </Button>
                    </div>

                    <p v-if="editable" class="mt-2 hidden items-center gap-2 text-xs text-muted-foreground pointer-coarse:flex">
                        <PhHandSwipeRight class="size-4" aria-hidden="true" />Swipe a card right for present, left for missed.
                    </p>
                    <div v-if="editable && unmarked.length" class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
                        <span>Sets the {{ unmarked.length }} troopers not marked yet. Marks you already set stay.</span>
                        <div class="flex flex-wrap gap-2 sm:ml-auto">
                            <Button variant="outline" size="sm" data-testid="mark-all-present" @click="markAllPresent">
                                <PhChecks />Mark all present
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                data-testid="mark-rest-missed"
                                :disabled="!editable || !unmarked.length"
                                @click="markRestMissed"
                            >
                                <PhX />Mark the rest missed
                            </Button>
                        </div>
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
                                    :loa-sync="loaSync"
                                    kind="practice"
                                    :editable="editable"
                                    :disabled-reason="readOnlyReason"
                                    :spotlight="spotlight === trooper.id"
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
                    <!-- The rollcall can still be posted, but not without seeing who the server saw and nobody accounted for (#176). -->
                    <div
                        v-if="unlinkedCount"
                        role="status"
                        class="mt-3 flex flex-wrap items-center gap-x-2.5 gap-y-2 rounded-lg border border-warning/40 bg-warning/7 px-3.5 py-2.5 text-[13px]"
                        data-testid="unlinked-review"
                    >
                        <PhUserCircleDashed class="size-4 shrink-0 text-warning" aria-hidden="true" />
                        <p class="min-w-[14rem] flex-1">
                            <b class="font-semibold">{{ unlinkedLabel(unlinkedCount) }}</b> seen on the server {{ unlinkedCount === 1 ? 'is' : 'are' }} not
                            accounted for. Link or dismiss them on the practice step.
                        </p>
                        <Button variant="outline" size="sm" data-testid="unlinked-go" @click="go(2)">Go to section practice</Button>
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
                            <p class="font-medium">
                                Rollcall posted <TimeAgo :at="posted.at" quiet>{{ postedLabel }}</TimeAgo><template v-if="posted.by"> by {{ posted.by }}</template>
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <template v-if="editedSincePost">Marks changed since then. Repost the updated BBCode, then mark it posted again.</template>
                                <template v-else>Edits after this show up here as changes to repost.</template>
                            </p>
                        </div>
                    </div>
                    <StepReview :troopers="orderedRows" :at-risk="liveAtRisk" @go="go">
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
                        <FireteamPanel
                            v-if="fireteams"
                            :section-id="section.id"
                            :troopers="orderedRows"
                            :fireteams="fireteams"
                            :editable="editable"
                            :disabled-reason="readOnlyReason"
                            @updated="onFireteams"
                        />
                        <ForumPost :post="post" />

                        <div class="mt-5 flex flex-wrap items-center gap-2 py-3">
                            <Button variant="outline" class="hidden h-11 sm:inline-flex sm:h-9" @click="go(2)"><PhCaretLeft />Back</Button>
                            <Button variant="outline" class="h-11 flex-1 sm:ml-auto sm:h-9 sm:flex-none" data-testid="copy-bbcode" :disabled="post.state !== 'ready'" @click="copyBbcode">
                                <PhCopy />{{ copied ? 'Copy again' : 'Copy BBCode' }}
                            </Button>
                            <Button
                                class="h-11 flex-1 sm:h-9 sm:flex-none"
                                data-testid="mark-posted"
                                :disabled="Boolean(previewReason) || posting"
                                :title="previewReason ?? undefined"
                                @click="markPosted"
                            >
                                <PhCheck />{{ posted ? 'Mark posted again' : 'Mark rollcall posted' }}
                            </Button>
                        </div>
                    </StepReview>
                </template>
            </section>

            <!-- Aside: the step's tallies -->
            <aside :class="cn('space-y-4 lg:sticky lg:top-[calc(var(--shell-top,3rem)+5.5rem)]', !(step === 2 && showDrawer) && 'hidden lg:block')" aria-label="Step details">
                <!-- Under the marks list on a phone, beside it on a wide screen (capture-review.html C). -->
                <UnmatchedDrawer
                    v-if="step === 2 && showDrawer"
                    :section-id="section.id"
                    :week="week.date"
                    :players="unmatchedPlayers"
                    :dismissed="dismissedPlayers"
                    :restore="restoreDismissed"
                    :troopers="rows"
                    :editable="editable"
                    :disabled-reason="readOnlyReason"
                    :before-link="beforeLink"
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
                        <p class="flex justify-between" data-testid="keyboard-move">
                            <span>Next or previous trooper</span>
                            <span><kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">{{ keyLabel(SHORTCUTS['rollcall.next'].keys[0]) }}</kbd> <kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">{{ keyLabel(SHORTCUTS['rollcall.previous'].keys[0]) }}</kbd></span>
                        </p>
                        <p class="flex justify-between" data-testid="keyboard-mark">
                            <span>Set status on focused card</span><span><kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">{{ markKeys[0] }}</kbd> to <kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">{{ markKeys[1] }}</kbd></span>
                        </p>
                        <p class="flex justify-between">
                            <span>All keyboard shortcuts</span><span><kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">{{ keyLabel(SHORTCUTS.help.keys[0]) }}</kbd></span>
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
