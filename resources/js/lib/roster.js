// Display helpers for the roster screen (Stories 2.8, 4.6, 4.7, 4.9). Pure functions over the
// props RosterController sends; no KPI is derived here — RC 30d, SP 30d and at-risk arrive
// computed by the shared KPI service (AD-21). What lives here is presentation: how a week's raw
// marks render as a cell, how rows sort, and how a search matches.

import { forumDate } from './forumDate';

// Junior to senior; anything unknown sorts below the known ranks.
export const RANK_ORDER = [
    'RCT', 'PVT', 'PFC', 'SPC', 'CPL', 'SGT', 'SSG', 'SFC', 'MSG', '1SG', 'SGM', 'CSM',
    'WO1', 'CW2', 'CW3', 'CW4', 'CW5', '2LT', '1LT', 'CPT', 'MAJ', 'LTC', 'COL', 'BG', 'MG', 'LTG', 'GEN',
];

export const RANK_NAMES = {
    RCT: 'Recruit', PVT: 'Private', PFC: 'Private first class', SPC: 'Specialist', CPL: 'Corporal',
    SGT: 'Sergeant', SSG: 'Staff sergeant', SFC: 'Sergeant first class', MSG: 'Master sergeant',
    '1SG': 'First sergeant', SGM: 'Sergeant major', CSM: 'Command sergeant major',
    WO1: 'Warrant officer 1', CW2: 'Chief warrant officer 2', CW3: 'Chief warrant officer 3',
    CW4: 'Chief warrant officer 4', CW5: 'Chief warrant officer 5', '2LT': 'Second lieutenant',
    '1LT': 'First lieutenant', CPT: 'Captain', MAJ: 'Major', LTC: 'Lieutenant colonel', COL: 'Colonel',
    BG: 'Brigadier general', MG: 'Major general', LTG: 'Lieutenant general', GEN: 'General',
};

export function rankIndex(rank) {
    const index = RANK_ORDER.indexOf(rank);

    return index === -1 ? -1 : index;
}

/** `officer`, `nco` or `enlisted` — the rank badge's tone. */
export function rankTier(rank) {
    const index = rankIndex(rank);
    if (index >= RANK_ORDER.indexOf('WO1')) return 'officer';
    if (index >= RANK_ORDER.indexOf('CPL')) return 'nco';

    return 'enlisted';
}

const ROLE_ORDER = { SL: 0, ASL: 1 };

/** Billet order: SL, then ASL, then by rank (senior first), then name. */
export function compareBillet(a, b) {
    return (
        (ROLE_ORDER[a.role] ?? 2) - (ROLE_ORDER[b.role] ?? 2) ||
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
    open: 'Not signed yet',
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
