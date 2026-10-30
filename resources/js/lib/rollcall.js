import {
    PhArrowUUpLeft,
    PhCalendarCheck,
    PhCheck,
    PhClockCounterClockwise,
    PhMinus,
    PhX,
} from '@phosphor-icons/vue';
import { initialsOf } from './initials';

// The vocabulary of the rollcall screen (Stories 3.7–3.10, mockups/v2/rollcall-r3.html; statuses of 2026-10-30).
// Values are the `attendance.rollcall` / `attendance.status` enums; the order is the order of the buttons on a trooper
// card, so the number keys follow it. Excused shares the warning tone with LOA: the week counts for nothing.

// A trooper's signature on the week's roll call thread. Will attend, will not attend and make-up requested each count
// as signed; excused takes the week out of RC.
export const SIGNATURES = [
    { value: 'will_attend', label: 'Will attend', tone: 'ok', icon: PhCheck },
    { value: 'cannot_attend', label: 'Will not attend', tone: 'bad', icon: PhX },
    { value: 'makeup_requested', label: 'Make-up requested', tone: 'info', icon: PhClockCounterClockwise },
    { value: 'excused', label: 'Excused', tone: 'warn', icon: PhCalendarCheck },
    { value: 'unsigned', label: 'Unsigned', tone: 'neutral', icon: PhMinus },
];

// A Section Practice mark a leader may set by hand. `loa` is derived from LOA data, never chosen. Make-up complete
// covers a practice made up anywhere, the old "Attached" (another section's practice) included.
export const PRACTICE = [
    { value: 'present', label: 'Attended', tone: 'ok', icon: PhCheck },
    { value: 'missed', label: 'Not attended', tone: 'bad', icon: PhX },
    { value: 'excused', label: 'Excused', tone: 'warn', icon: PhCalendarCheck },
    { value: 'makeup', label: 'Make-up complete', tone: 'info', icon: PhArrowUUpLeft },
];

export function optionsFor(kind) {
    return kind === 'signature' ? SIGNATURES : PRACTICE;
}

export function signatureLabel(value) {
    return SIGNATURES.find((option) => option.value === value)?.label ?? 'Unsigned';
}

// Why a week is excused automatically (`trooper.excused` from the server, `Kpi\AutoExcused`): the first week after
// joining the Cav, or after being placed into a section.
const EXCUSED_REASONS = {
    recruit: 'First week after joining the Cav, excused automatically.',
    transfer: 'First week in this section after a transfer, excused automatically.',
};

export function excusedReason(reason) {
    return EXCUSED_REASONS[reason] ?? null;
}

// The automatic Excused covers a fact only while no one recorded it: an unsigned signature, and a practice mark that is
// empty or an automatic one that is not attended. Whatever a leader sets wins. The server reads the same rule
// (`AutoExcused::excusesSignature()` / `excusesPractice()`).
export function autoExcusesSignature(trooper) {
    return Boolean(trooper.excused) && (!trooper.rollcall || trooper.rollcall === 'unsigned');
}

export function autoExcusesPractice(trooper) {
    if (!trooper.excused) return false;
    if (trooper.status === null || trooper.status === undefined) return true;

    return trooper.source === 'auto' && !['present', 'makeup', 'loa'].includes(trooper.status);
}

// The signature and the practice mark as they count: what was recorded, else the automatic Excused.
export function signatureOf(trooper) {
    return autoExcusesSignature(trooper) ? 'excused' : trooper.rollcall;
}

export function practiceOf(trooper) {
    if (isLocked(trooper)) return 'loa';

    return autoExcusesPractice(trooper) ? 'excused' : (trooper.status ?? null);
}

// A make-up asked for on the roll call and not recorded yet (`KpiService::owesMakeup()`): made up, attended after all,
// excused or on leave settles it. Read from what was recorded, as the server reads it.
export function owesMakeup(trooper) {
    return trooper.rollcall === 'makeup_requested' && !isLocked(trooper) && !['makeup', 'present', 'excused', 'loa'].includes(trooper.status);
}

// The roll call page's "Owes a make-up" list: the server's earlier window weeks (`makeupOwed`, refreshed on each save),
// with this week read live from the cards. Each entry is `{ trooper, weeks }`, weeks oldest first.
export function makeupOwedList(troopers, serverList, week) {
    const earlier = new Map((serverList ?? []).map((row) => [row.trooperId, row.weeks.filter((w) => w !== week)]));

    return troopers
        .map((trooper) => ({ trooper, weeks: [...(earlier.get(trooper.id) ?? []), ...(owesMakeup(trooper) ? [week] : [])] }))
        .filter((row) => row.weeks.length);
}

// A row on a week that has ended whose trooper the placement history keeps on it (`RollcallRoster::placementNote`):
// the badge and its title, or null.
const PLACEMENT_NOTES = {
    moved_out: { label: 'Moved out', title: 'In another unit now, and still on this roll call: they were in this section when the week ended.' },
    joined: { label: 'Joined', title: 'Moved into this section partway through the week.' },
    departed: { label: 'Departed', title: 'No longer on any Cav roster, and still on this roll call: they served this week.' },
};

export function placementNote(value) {
    return PLACEMENT_NOTES[value] ?? null;
}

export function practiceLabel(value) {
    if (value === 'loa') return 'LOA';

    return PRACTICE.find((option) => option.value === value)?.label ?? 'Not marked';
}

// "Will attend", "Attended": a value as it reads inside a sentence, a shared word kept as one ("LOA").
export function inSentence(label) {
    return label === 'LOA' ? label : label.charAt(0).toLowerCase() + label.slice(1);
}

// An LOA-derived row is locked: nothing on it may be set by hand (3.4 / 3.8). The leave comes from the server's
// one coverage definition (`trooper.loa`, `LoaCover::toArray()`); nothing stores an `loa` mark any more, but an
// older stored one still locks its row. A leave that needs review is not coverage, so that row stays markable.
export function isLocked(trooper) {
    return trooper.loa?.state === 'covered' || trooper.status === 'loa';
}

// A trooper's forum username, synced from the roster (2.10); until it syncs, their milpac id —
// never an invented name. Matches the server's `Trooper::display_name` fallback.
export function displayName(trooper) {
    return trooper.name ?? `#${trooper.cavId}`;
}

// The rank insignia Cav serves (`rankIcon`, synced from the roster), only as an https URL on 7cav.us with a plain path,
// since the template writes it into an [IMG] tag; anything else, or none, is empty. The server keeps the same rule
// (`CavRosterEntry::RANK_IMAGE`), so this only guards against a value that slipped past it.
const RANK_ICON = /^https:\/\/7cav\.us\/[A-Za-z0-9._~%/-]{1,200}(\?[0-9]{1,20})?$/;

export function rankIconOf(trooper) {
    return typeof trooper.rankIcon === 'string' && RANK_ICON.test(trooper.rankIcon) ? trooper.rankIcon : '';
}

// The short rank the forum uses ("SGT"), falling back to the full one.
export function rankOf(trooper) {
    return trooper.rankShort ?? trooper.rank ?? null;
}

// "SGT Vasquez.R" — the server's `display_name` when it sent one.
export function fullName(trooper) {
    return trooper.displayName ?? [rankOf(trooper), displayName(trooper)].filter(Boolean).join(' ');
}

export function initials(trooper) {
    return initialsOf(trooper.name) || '#';
}

// Totals over the section, LOA rows counted on their own (they are neither expected to sign nor
// to attend). Each counts the value as it counts: an automatic Excused is excused, not unsigned or unmarked.
export function countsOf(troopers) {
    const active = troopers.filter((trooper) => !isLocked(trooper));
    const count = (predicate) => active.filter(predicate).length;
    const sig = (value) => count((t) => signatureOf(t) === value);
    const sp = (value) => count((t) => practiceOf(t) === value);

    return {
        total: active.length,
        loa: troopers.length - active.length,
        willAttend: sig('will_attend'),
        cannotAttend: sig('cannot_attend'),
        makeupRequested: sig('makeup_requested'),
        excusedSignature: sig('excused'),
        unsigned: sig('unsigned'),
        present: sp('present'),
        missed: sp('missed'),
        excused: sp('excused'),
        makeup: sp('makeup'),
        unmarked: sp(null),
        makeupOwed: count(owesMakeup),
    };
}

// Where a section's rollcall stands for the week, for the section picker: `{ state, text }`, where
// `state` is `done` (marked posted), `todo` (work left) or `none` (no troopers). The words follow
// the home step matrix; the counts are the server's (`progress` from `weekProgress`), with
// troopers on LOA neither unsigned nor unmarked, as on this screen.
export function sectionStatus(progress) {
    if (!progress) return null;
    if (progress.posted) return { state: 'done', text: 'Posted' };
    if (progress.strength === 0) return { state: 'none', text: 'No troopers' };
    if (!progress.started && progress.strength > progress.onLoa) return { state: 'todo', text: 'Not started' };

    const left = [progress.unsigned && `${progress.unsigned} unsigned`, progress.unmarked && `${progress.unmarked} not marked`].filter(Boolean);

    return { state: 'todo', text: left.length ? left.join(', ') : 'Not posted' };
}

// "3 unlinked players": the count the rollcall header and review step show (#176).
export function unlinkedLabel(count) {
    return `${count} unmatched ${count === 1 ? 'player' : 'players'}`;
}

// What a game's server feeds are called on screen, by `capture_source` value (7.8, 7.9).
export const CAPTURE_SOURCES = { crcon: 'CRCON', battlemetrics: 'BattleMetrics', nightwatch: 'Nightwatch' };

// Where a capture's marks came from (7.8): every feed that answered, since a game read from several training servers
// stores them comma-joined (#389). With none recorded it is "the game server", never "null".
export function captureSourceLabel(source) {
    if (!source) return 'the game server';

    return source
        .split(',')
        .map((feed) => CAPTURE_SOURCES[feed] ?? feed)
        .join(' + ');
}

// Which feed could not be read, for the telemetry banner and its retry (7.9, #176): "Hell Let Loose server (CRCON)",
// so a down feed never reads as an empty server. With no game known for the section it is the plain "Game server".
export function feedLabel(telemetry) {
    if (!telemetry?.platformLabel) return 'Game server';
    const feeds = (telemetry.feeds ?? []).map((feed) => CAPTURE_SOURCES[feed] ?? feed);

    return `${telemetry.platformLabel} server${feeds.length ? ` (${feeds.join(', ')})` : ''}`;
}

// The three steps of the guided week, with whether each is done. `unlinked` is how many players the capture saw that
// no trooper is linked to yet (7.7, #176): the practice step carries the count and stays a warning while any are left,
// so a leader on any step sees that someone who was online is not accounted for, even with every trooper marked.
export function stepsOf(troopers, postedAt = null, unlinked = 0) {
    const c = countsOf(troopers);

    return [
        {
            n: 1,
            title: 'Signatures',
            sub: c.unsigned ? `${c.total - c.unsigned} of ${c.total} signed` : `All ${c.total} signed`,
            state: c.unsigned ? 'warn' : 'done',
        },
        {
            n: 2,
            title: 'Section practice',
            sub: c.unmarked ? `${c.unmarked} left to mark` : 'All marked',
            state: c.unmarked || unlinked ? 'warn' : 'done',
            unlinked,
        },
        {
            n: 3,
            title: 'Review and post',
            sub: postedAt ? 'Posted' : 'Not posted yet',
            state: postedAt ? 'done' : 'todo',
        },
    ];
}

// The step a rollcall opens on when the URL names none: the first one with work left. Signatures
// count only while the thread is still open; once it has closed, the practice marks come first.
// An empty roster has nothing to do and opens on step 1, as before.
export function landingStep(troopers, signaturesOpen) {
    if (!troopers.length) return 1;
    const c = countsOf(troopers);
    if (signaturesOpen && c.unsigned) return 1;

    return c.unmarked ? 2 : 3;
}

// The past weeks around `date` that still need work (`weekStatus` from the server: troopers left
// unmarked, or the rollcall not posted), split into the ones before and after it, newest first.
export function weeksNeedingWork(weekStatus, date) {
    const open = weekStatus
        .filter((week) => week.unmarked > 0 || !week.posted)
        .map((week) => ({ ...week, what: [week.unmarked && `${week.unmarked} not marked`, !week.posted && 'not posted'].filter(Boolean).join(', ') }))
        .sort((a, b) => b.date.localeCompare(a.date));

    return {
        earlier: open.filter((week) => week.date < date),
        later: open.filter((week) => week.date > date),
    };
}

// Step 1 groups by signature, the ones to chase first.
export function signatureGroups(troopers) {
    const by = (value) => troopers.filter((t) => !isLocked(t) && signatureOf(t) === value);

    return [
        { id: 'unsigned', title: 'Unsigned', troopers: by('unsigned') },
        { id: 'cannot', title: 'Will not attend', troopers: by('cannot_attend') },
        { id: 'makeup', title: 'Make-up requested', troopers: by('makeup_requested') },
        { id: 'will', title: 'Will attend', troopers: by('will_attend') },
        { id: 'excused', title: 'Excused', troopers: by('excused') },
        { id: 'loa', title: 'On LOA', troopers: troopers.filter(isLocked) },
    ].filter((group) => group.troopers.length);
}

// Step 2 groups by what the trooper said, "will not attend" and "make-up requested" first: those are the marks most
// likely to need a decision. `onlyOpen` narrows to the rows that still need a look.
export function practiceGroups(troopers, onlyOpen = false) {
    const open = (t) => !onlyOpen || practiceOf(t) === null || practiceOf(t) === 'missed' || owesMakeup(t);
    const by = (value) => troopers.filter((t) => !isLocked(t) && signatureOf(t) === value && open(t));

    return [
        { id: 'cannot', title: 'Said they will not attend', hint: 'Check these first', troopers: by('cannot_attend') },
        { id: 'makeup', title: 'Asked for a make-up', hint: 'Mark Make-up complete once it is done', troopers: by('makeup_requested') },
        { id: 'unsigned', title: 'Did not sign', troopers: by('unsigned') },
        { id: 'will', title: 'Said they will attend', troopers: by('will_attend') },
        { id: 'excused', title: 'Excused from the roll call', troopers: by('excused') },
        { id: 'loa', title: 'On LOA', troopers: onlyOpen ? [] : troopers.filter(isLocked) },
    ].filter((group) => group.troopers.length);
}
