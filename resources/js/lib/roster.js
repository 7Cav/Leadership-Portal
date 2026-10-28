// Display helpers for the roster screen (Stories 2.8, 4.6, 4.7, 4.9). Pure functions over the
// props RosterController sends; no KPI is derived here — RC 30d, SP 30d and at-risk arrive
// computed by the shared KPI service (AD-21). What lives here is presentation: how a week's raw
// marks render as a cell, how rows sort, and how a search matches.

import { forumDate } from './forumDate';

// Junior to senior; anything unknown sorts below the known ranks.
export const RANK_ORDER = [
    'RCT', 'PVT', 'PFC', 'SPC', 'CPL', 'SGT', 'SSG', 'SFC', 'MSG', '1SG', 'SGM', 'CSM',
    'WO1', 'CW2', 'CW3', 'CW4', 'CW5', '2LT', '1LT', 'CPT', 'MAJ', 'LTC', 'COL', 'BG', 'MG', 'LTG', 'GEN', 'GOA',
];

export const RANK_NAMES = {
    RCT: 'Recruit', PVT: 'Private', PFC: 'Private First Class', SPC: 'Specialist', CPL: 'Corporal',
    SGT: 'Sergeant', SSG: 'Staff Sergeant', SFC: 'Sergeant First Class', MSG: 'Master Sergeant',
    '1SG': 'First Sergeant', SGM: 'Sergeant Major', CSM: 'Command Sergeant Major',
    WO1: 'Warrant Officer 1', CW2: 'Chief Warrant Officer 2', CW3: 'Chief Warrant Officer 3',
    CW4: 'Chief Warrant Officer 4', CW5: 'Chief Warrant Officer 5', '2LT': 'Second Lieutenant',
    '1LT': 'First Lieutenant', CPT: 'Captain', MAJ: 'Major', LTC: 'Lieutenant Colonel', COL: 'Colonel',
    BG: 'Brigadier General', MG: 'Major General', LTG: 'Lieutenant General', GEN: 'General',
    GOA: 'General of the Army',
};

export function rankIndex(rank) {
    const index = RANK_ORDER.indexOf(rank);

    return index === -1 ? -1 : index;
}

/** `officer`, `warrant`, `nco` or `enlisted` — the rank badge's tone. */
export function rankTier(rank) {
    const index = rankIndex(rank);
    if (index >= RANK_ORDER.indexOf('2LT')) return 'officer';
    if (index >= RANK_ORDER.indexOf('WO1')) return 'warrant';
    if (index >= RANK_ORDER.indexOf('CPL')) return 'nco';

    return 'enlisted';
}

// The command team in chain-of-command order (#444): the commanding billet, then the second in command, then the
// senior NCO. A unit lists only its own team (a company its CO, XO and 1SG; a platoon its PL and PSG; a section its SL
// and ASL), so the echelons can share the three places. CommandRole::seniority() is the same order on the server, where
// act as reads it: keep the two in step.
const ROLE_ORDER = { CO: 0, PL: 0, SL: 0, XO: 1, PSG: 1, ASL: 1, '1SG': 2, SGM: 2, CSM: 2 };

// A trooper's place by their billet tag (#443): an acting lead stands where the billet they act for does, and everyone
// without a tag comes after the command team.
function roleRank(role) {
    if (!role) return 3;

    return ROLE_ORDER[role.replace(/^Acting /, '')] ?? 3;
}

/**
 * Billet order, the roster's default: the command team (CO, XO, then 1SG, SGM or CSM; PL then PSG; SL then ASL), then
 * everyone by rank (senior first), then name. A column sort replaces it and only falls back to it for ties.
 */
export function compareBillet(a, b) {
    return (
        roleRank(a.role) - roleRank(b.role) ||
        rankIndex(b.rank) - rankIndex(a.rank) ||
        (a.name ?? '').localeCompare(b.name ?? '')
    );
}

/** A KPI ratio `[numerator, denominator]` as a whole percentage, or null when nothing was eligible. */
export function percent(ratio) {
    if (!ratio || !ratio[1]) return null;

    return Math.round((ratio[0] / ratio[1]) * 100);
}

export function percentLabel(ratio) {
    const value = percent(ratio);

    return value === null ? 'n/a' : `${value}%`;
}

/** Tone for a percentage: below 60 bad, below 75 warning (mockup thresholds). */
export function percentTone(ratio) {
    const value = percent(ratio);
    if (value === null) return 'muted';
    if (value < 60) return 'bad';
    if (value < 75) return 'warn';

    return 'ok';
}

export const RC_STATES = {
    ok: 'Will attend',
    cn: 'Cannot attend',
    bad: 'Unsigned',
    open: 'Unsigned (rollcall open)',
    nil: 'No rollcall posted',
    loa: 'On LOA',
    out: 'Not in the unit yet',
};

export const SP_STATES = {
    ok: 'Present',
    mk: 'Makeup',
    at: 'Attached',
    bad: 'Missed',
    open: 'Not marked yet',
    pending: 'Not held yet',
    nil: 'No SP held',
    loa: 'On LOA',
    out: 'Not in the unit yet',
};

const SP_BY_STATUS = { present: 'ok', makeup: 'mk', attached: 'at', missed: 'bad', loa: 'loa' };

/**
 * How one trooper-week renders: the rollcall half and the SP half, each a state key into
 * RC_STATES / SP_STATES. `mark` is the controller's week entry (null when the section had no
 * practice row that week); `joined` the trooper's Cav join date.
 */
export function weekCell(mark, weekStart, { current = false, joined = null } = {}) {
    if (joined && endOfWeek(weekStart) < joined) return { rc: 'out', sp: 'out' };
    if (!mark) return { rc: current ? 'open' : 'nil', sp: current ? 'pending' : 'nil' };
    if (mark.status === 'loa') return { rc: 'loa', sp: 'loa' };

    const rc = { will_attend: 'ok', cannot_attend: 'cn' }[mark.rollcall] ?? (current ? 'open' : 'bad');
    let sp = SP_BY_STATUS[mark.status];
    if (!sp) sp = mark.held ? 'open' : current ? 'pending' : 'nil';

    return { rc, sp };
}

function endOfWeek(weekStart) {
    const end = new Date(`${weekStart}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 6);

    return end.toISOString().slice(0, 10);
}

/**
 * The name half of a row whose rank sits in its own badge: the username, or the milpac id when Cav
 * links no forum account. Anywhere rank and name read as one, use the server's `displayName`
 * (`Trooper::display_name`, 2.10) instead.
 */
export function displayName(trooper) {
    return trooper?.name || (trooper?.cavId ? `#${trooper.cavId}` : 'Unknown');
}

/** Where a week's SP mark came from, for tooltips and the Sheet. */
export function sourceLabel(mark) {
    if (!mark) return null;
    if (mark.status === 'loa') return 'LOA';
    if (mark.source === 'auto') return 'Auto, game server capture';
    if (mark.source === 'manual') return 'Manual, section leader';

    return null;
}

/** `03Oct26`, the forum's date convention. */
export function weekLabel(weekStart) {
    return forumDate(`${weekStart}T00:00:00Z`);
}

/**
 * One move from the placement history (`Milpac\PlacementHistory`, 2026-10-27), as the milpac and the Sheet word it:
 * "Moved from 2/1/A/1-7 to 1/2/A/1-7, 12Oct26". `from` is null for a first placement, `to` when they were left
 * unplaced.
 */
export function transferLabel(move) {
    const date = forumDate(move.at);
    if (move.from && move.to) return `Moved from ${move.from} to ${move.to}, ${date}`;
    if (move.to) return `Placed in ${move.to}, ${date}`;

    return `Left ${move.from}, ${date}`;
}

/**
 * How one milpac attendance week (`Kpi\AttendanceHistory`) renders, as the same RC and SP states as a roster week. A
 * week in no section (before they joined one, on HQ staff or in a group) is `out`; a week leave covers is `loa`
 * whatever the marks say.
 */
export function historyCell(week, { current = false } = {}) {
    if (week.loa) return { rc: 'loa', sp: 'loa' };
    if (!week.section) return { rc: 'out', sp: 'out' };

    // No practice row at that section that week: nothing was posted or held.
    return weekCell(week.rollcall === null ? null : week, week.week, { current });
}

/** The trooper joined within the last 8 Cav weeks (the order-of-battle strip's window). */
export function isNewRecruit(trooper, currentWeek) {
    if (!trooper.joined) return false;
    const since = new Date(`${currentWeek}T00:00:00Z`);
    since.setUTCDate(since.getUTCDate() - 7 * 7);

    return new Date(`${trooper.joined}T00:00:00Z`) >= since;
}

export const FILTERS = {
    all: (t) => !t.departed,
    risk: (t) => !!t.kpis?.atRisk,
    loa: (t) => !!t.kpis?.onLoa,
    new: (t, currentWeek) => !t.departed && isNewRecruit(t, currentWeek),
    departed: (t) => t.departed,
};

/** Case-insensitive match on rank, rank title, name (or milpac id) and billet. */
export function matchesQuery(trooper, query) {
    const q = query.trim().toLowerCase();
    if (!q) return true;

    return [trooper.rank, RANK_NAMES[trooper.rank], trooper.rankFull, displayName(trooper), trooper.billet]
        .filter(Boolean)
        .some((field) => field.toLowerCase().includes(q));
}

/** Splits `text` around case-insensitive hits of `query`, for <mark> highlighting. */
export function highlightParts(text, query) {
    const value = text ?? '';
    const q = query.trim().toLowerCase();
    if (!q) return [{ text: value, hit: false }];

    const parts = [];
    const lower = value.toLowerCase();
    let from = 0;
    let at;
    while ((at = lower.indexOf(q, from)) !== -1) {
        if (at > from) parts.push({ text: value.slice(from, at), hit: false });
        parts.push({ text: value.slice(at, at + q.length), hit: true });
        from = at + q.length;
    }
    if (from < value.length) parts.push({ text: value.slice(from), hit: false });

    return parts;
}

export const SORTS = {
    billet: { label: 'Billet order', compare: compareBillet },
    risk: {
        label: 'At-risk first',
        compare: (a, b) =>
            Number(!!b.kpis?.atRisk) - Number(!!a.kpis?.atRisk) ||
            (b.kpis?.missed ?? 0) - (a.kpis?.missed ?? 0) ||
            compareBillet(a, b),
    },
    rc: { label: 'RC 30d, low to high', compare: (a, b) => (percent(a.kpis?.rc) ?? 101) - (percent(b.kpis?.rc) ?? 101) || compareBillet(a, b) },
    sp: { label: 'SP 30d, low to high', compare: (a, b) => (percent(a.kpis?.sp) ?? 101) - (percent(b.kpis?.sp) ?? 101) || compareBillet(a, b) },
    name: { label: 'Name, A to Z', compare: (a, b) => (a.name ?? '').localeCompare(b.name ?? '') },
};

// The order of battle's sortable columns. `value` reads what the column sorts by (null when unknown, which sorts last
// whichever way the column runs), `first` is the direction a first click picks: the most senior, A to Z, the longest
// served and the weakest attendance on top. Equal values keep the default billet order, so a sort never shuffles ties.
const longAgo = (date) => (date ? -Date.parse(`${date}T00:00:00Z`) : null);

export const ORBAT_SORTS = {
    rank: { label: 'Rank', first: 'desc', value: (t) => (rankIndex(t.rank) === -1 ? null : rankIndex(t.rank)) },
    name: { label: 'Name', first: 'asc', value: (t) => displayName(t).toLowerCase() },
    // Time in service and in grade, as "how long ago": a departed trooper has stopped counting either.
    tis: { label: 'Time in service', first: 'desc', value: (t) => (t.departed ? null : longAgo(t.joined)) },
    tig: { label: 'Time in grade', first: 'desc', value: (t) => (t.departed ? null : longAgo(t.tig)) },
    rc: { label: 'RC 30d', first: 'asc', value: (t) => percent(t.kpis?.rc) },
    sp: { label: 'SP 30d', first: 'asc', value: (t) => percent(t.kpis?.sp) },
};

/** The comparator for an order-of-battle sort `{ key, dir }`, or the default billet order for none. */
export function compareOrbat(sort) {
    const column = sort && Object.hasOwn(ORBAT_SORTS, sort.key) ? ORBAT_SORTS[sort.key] : null;
    if (!column) return compareBillet;
    const sign = sort.dir === 'desc' ? -1 : 1;

    return (a, b) => {
        const x = column.value(a);
        const y = column.value(b);
        if (x === null || y === null) return (x === null) - (y === null) || compareBillet(a, b);

        return sign * (typeof x === 'string' ? x.localeCompare(y) : x - y) || compareBillet(a, b);
    };
}

/** The sort a header click leads to: a new column starts its natural way, the same one reverses, then back to none. */
export function nextOrbatSort(current, key) {
    const first = ORBAT_SORTS[key].first;
    if (current?.key !== key) return { key, dir: first };
    if (current.dir === first) return { key, dir: first === 'asc' ? 'desc' : 'asc' };

    return null;
}

export const ORBAT_SORT_STORAGE_KEY = 'roster.orbat.sort';

/** The sort this browser last chose, or null (the default order) when none is stored or storage is unavailable. */
export function readOrbatSort(storage = globalThis.localStorage) {
    try {
        const stored = JSON.parse(storage?.getItem(ORBAT_SORT_STORAGE_KEY) ?? 'null');

        // `hasOwn`, so a stored key like `constructor` is not taken for a column.
        return stored && Object.hasOwn(ORBAT_SORTS, stored.key) && ['asc', 'desc'].includes(stored.dir) ? { key: stored.key, dir: stored.dir } : null;
    } catch {
        return null;
    }
}

/** Remembers the sort for this browser; the default order clears it. Storage failing only means it is not remembered. */
export function writeOrbatSort(sort, storage = globalThis.localStorage) {
    try {
        if (sort) storage?.setItem(ORBAT_SORT_STORAGE_KEY, JSON.stringify({ key: sort.key, dir: sort.dir }));
        else storage?.removeItem(ORBAT_SORT_STORAGE_KEY);
    } catch {
        // Private mode or blocked storage: the sort still applies, it just is not remembered.
    }
}

/** Whole months from `date` to `today` (both `YYYY-MM-DD`), or null when unknown or in the future. */
export function monthsSince(date, today) {
    if (!date || !today || date > today) return null;
    const [y1, m1, d1] = date.split('-').map(Number);
    const [y2, m2, d2] = today.split('-').map(Number);

    return (y2 - y1) * 12 + (m2 - m1) - (d2 < d1 ? 1 : 0);
}

/** "2y 3m", "7m", "<1m" or "—": a time in service or grade, short enough for a table column. */
export function shortDuration(months) {
    if (months === null || months === undefined) return '—';
    if (months < 1) return '<1m';
    const years = Math.floor(months / 12);
    const rest = months % 12;

    return [years ? `${years}y` : null, rest ? `${rest}m` : null].filter(Boolean).join(' ');
}

/**
 * A section's week, summed over its members' raw marks for the matrix's aggregate row: signed
 * rollcalls of those due, attended practices of those eligible — every member of a held practice,
 * marked or not, as the KPI service counts them. Counts and a heat level for one cell only; the
 * RC/SP percentages beside it come from the KPI service.
 */
export function aggregateWeek(members, index, weekStart, current) {
    let signed = 0;
    let due = 0;
    let attended = 0;
    let marked = 0;
    let loa = 0;
    let anyMark = false;
    let pending = false;

    for (const member of members) {
        const mark = member.weeks?.[index];
        const cell = weekCell(mark, weekStart, { current, joined: member.joined });
        if (cell.rc === 'out') continue;
        if (mark) anyMark = true;
        if (cell.rc === 'loa') loa++;
        if (cell.rc === 'ok' || cell.rc === 'cn') {
            signed++;
            due++;
        } else if (cell.rc === 'bad') {
            due++;
        }
        if (['ok', 'mk', 'at'].includes(cell.sp)) {
            attended++;
            marked++;
        } else if (cell.sp === 'bad') {
            marked++;
        } else if (cell.sp === 'open') {
            // Held, but this member is not marked yet: eligible, not attended.
            marked++;
            pending = true;
        } else if (cell.sp === 'pending') {
            pending = true;
        }
    }

    const level = (num, den) => {
        if (!den) return null;
        const pct = (num / den) * 100;

        return pct >= 85 ? 'g4' : pct >= 70 ? 'g3' : pct >= 55 ? 'g2' : 'g1';
    };

    return {
        signed,
        due,
        attended,
        marked,
        loa,
        rc: level(signed, due) ?? (anyMark ? (current ? 'open' : loa ? 'loa' : 'nil') : current ? 'open' : 'nil'),
        sp: level(attended, marked) ?? (pending ? 'open' : loa && anyMark ? 'loa' : 'nil'),
    };
}

// How a trooper's roster status reads everywhere a person is shown (the order of battle, the milpac): one label
// and one badge variant per status. Leaving the unit is not an error, so a departed trooper is outlined, not red.
export const TROOPER_STATUS = {
    combat: { label: 'Active', variant: 'success' },
    reserve: { label: 'Reserve', variant: 'info' },
    eloa: { label: 'ELOA', variant: 'warning' },
    inactive: { label: 'Departed', variant: 'outline' },
};

export function trooperStatusOf(status) {
    return TROOPER_STATUS[status] ?? { label: status, variant: 'outline' };
}
