import {
    PhArrowSquareIn,
    PhArrowUUpLeft,
    PhCheck,
    PhMinus,
    PhX,
} from '@phosphor-icons/vue';

// The vocabulary of the rollcall screen (Stories 3.7–3.10, mockups/v2/rollcall-r3.html). Values are
// the `attendance.rollcall` / `attendance.status` enums; the order is the order of the buttons on a
// trooper card, so the 1–4 keyboard shortcuts follow it.

// A trooper's signature on the week's rollcall thread.
export const SIGNATURES = [
    { value: 'will_attend', label: 'Will attend', tone: 'ok', icon: PhCheck },
    { value: 'cannot_attend', label: 'Cannot attend', tone: 'bad', icon: PhX },
    { value: 'unsigned', label: 'Unsigned', tone: 'neutral', icon: PhMinus },
];

// A Section Practice mark a leader may set by hand. `loa` is derived from LOA data, never chosen.
export const PRACTICE = [
    { value: 'present', label: 'Present', tone: 'ok', icon: PhCheck },
    { value: 'missed', label: 'Missed', tone: 'bad', icon: PhX },
    { value: 'makeup', label: 'Makeup', tone: 'info', icon: PhArrowUUpLeft },
    { value: 'attached', label: 'Attached', tone: 'info', icon: PhArrowSquareIn },
];

export function optionsFor(kind) {
    return kind === 'signature' ? SIGNATURES : PRACTICE;
}

export function signatureLabel(value) {
    return SIGNATURES.find((option) => option.value === value)?.label ?? 'Unsigned';
}

export function practiceLabel(value) {
    if (value === 'loa') return 'LOA';

    return PRACTICE.find((option) => option.value === value)?.label ?? 'Not marked';
}

// An LOA-derived row is locked: nothing on it may be set by hand (3.4 / 3.8).
export function isLocked(trooper) {
    return trooper.status === 'loa';
}

// A trooper's forum username, synced from the roster (2.10); until it syncs, their milpac id —
// never an invented name. Matches the server's `Trooper::display_name` fallback.
export function displayName(trooper) {
    return trooper.name ?? `#${trooper.cavId}`;
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
    if (!trooper.name) return '#';

    return trooper.name
        .split(/[.\s_-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}

// Totals over the section, LOA rows counted on their own (they are neither expected to sign nor
// to attend).
export function countsOf(troopers) {
    const active = troopers.filter((trooper) => !isLocked(trooper));
    const count = (predicate) => active.filter(predicate).length;

    return {
        total: active.length,
        loa: troopers.length - active.length,
        willAttend: count((t) => t.rollcall === 'will_attend'),
        cannotAttend: count((t) => t.rollcall === 'cannot_attend'),
        unsigned: count((t) => t.rollcall === 'unsigned'),
        present: count((t) => t.status === 'present'),
        missed: count((t) => t.status === 'missed'),
        makeup: count((t) => t.status === 'makeup'),
        attached: count((t) => t.status === 'attached'),
        unmarked: count((t) => t.status === null),
    };
}

// The three steps of the guided week, with whether each is done.
export function stepsOf(troopers, postedAt = null) {
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
            state: c.unmarked ? 'warn' : 'done',
        },
        {
            n: 3,
            title: 'Review and post',
            sub: postedAt ? 'Posted' : 'Not posted yet',
            state: postedAt ? 'done' : 'todo',
        },
    ];
}

// Step 1 groups by signature, the ones to chase first.
export function signatureGroups(troopers) {
    const by = (value) => troopers.filter((t) => !isLocked(t) && t.rollcall === value);

    return [
        { id: 'unsigned', title: 'Unsigned', troopers: by('unsigned') },
        { id: 'cannot', title: 'Cannot attend', troopers: by('cannot_attend') },
        { id: 'will', title: 'Will attend', troopers: by('will_attend') },
        { id: 'loa', title: 'On LOA', troopers: troopers.filter(isLocked) },
    ].filter((group) => group.troopers.length);
}

// Step 2 groups by what the trooper said, "cannot attend" first: those are the marks most likely
// to need a decision. `onlyOpen` narrows to the rows that still need a look.
export function practiceGroups(troopers, onlyOpen = false) {
    const open = (t) => !onlyOpen || t.status === null || t.status === 'missed';
    const by = (value) => troopers.filter((t) => !isLocked(t) && t.rollcall === value && open(t));

    return [
        { id: 'cannot', title: 'Said they cannot attend', hint: 'Check these first', troopers: by('cannot_attend') },
        { id: 'unsigned', title: 'Did not sign', troopers: by('unsigned') },
        { id: 'will', title: 'Said they will attend', troopers: by('will_attend') },
        { id: 'loa', title: 'On LOA', troopers: onlyOpen ? [] : troopers.filter(isLocked) },
    ].filter((group) => group.troopers.length);
}
