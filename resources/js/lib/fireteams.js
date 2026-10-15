import { rankOf } from './rollcall';
import { rankIndex } from './roster';

// The order a section is listed in, on the rollcall screen and in its forum post (the owner's order, 2026-10-16): the
// Section Leader first, then the Assistant Section Leader, then fireteam leaders (FTLs) above everyone else, each group
// by rank seniority (roster.js `RANK_ORDER`), then name. FTLs are unofficial: the section's leaders mark them, and
// choose whether the post shows them at all (`show`, off until they turn it on) and where (`order`): `leaders_first`
// puts every FTL above the rest, `by_fireteam` puts each FTL directly above the members of their fireteam, with
// troopers in no fireteam last. The company may enforce either choice; the server sends the values that apply.
//
// A trooper's `role` is `sl`, `asl`, `ftl` or null and `fireteamLeaderId` the FTL whose fireteam they are in, both from
// the server (`App\Rollcall\SectionFireteams`). With FTLs not shown, an FTL is listed with everyone else.

export const ROLE_LABELS = { sl: 'SL', asl: 'ASL', ftl: 'FTL' };

const GROUP = { sl: 0, asl: 1, ftl: 2 };

/** The role a trooper is listed and named by: the SL and ASL always, an FTL only while FTLs are shown. */
export function shownRole(trooper, show) {
    if (trooper.role === 'sl' || trooper.role === 'asl') return trooper.role;

    return show && trooper.role === 'ftl' ? 'ftl' : null;
}

// The same name the screen shows, so a trooper with no forum account sorts by their milpac id after the named ones,
// as the server's alphabetical order had them.
const nameKey = (trooper) => (trooper.name ?? `~${trooper.cavId}`).toLowerCase();

/** Senior rank first (a rank not in the list sorts below every known one), then name, then id so the order is fixed. */
export function bySeniority(a, b) {
    const [x, y] = [nameKey(a), nameKey(b)];

    return rankIndex(rankOf(b)) - rankIndex(rankOf(a)) || (x < y ? -1 : x > y ? 1 : 0) || a.id - b.id;
}

/**
 * The section's troopers in the order the post lists them. Returns a new array; the troopers are not copied.
 *
 * @param {object[]} troopers the rollcall rows
 * @param {{show?: boolean, order?: 'leaders_first'|'by_fireteam'}} fireteams how the section shows fireteams
 */
export function orderTroopers(troopers, { show = false, order = 'leaders_first' } = {}) {
    const group = (trooper) => GROUP[shownRole(trooper, show)] ?? 3;
    const sorted = [...troopers].sort((a, b) => group(a) - group(b) || bySeniority(a, b));

    if (!show || order !== 'by_fireteam') return sorted;

    const ftls = sorted.filter((trooper) => group(trooper) === 2);
    const ftlIds = new Set(ftls.map((trooper) => trooper.id));
    const rest = sorted.filter((trooper) => group(trooper) === 3);

    return [
        ...sorted.filter((trooper) => group(trooper) < 2),
        ...ftls.flatMap((ftl) => [ftl, ...rest.filter((trooper) => trooper.fireteamLeaderId === ftl.id)]),
        ...rest.filter((trooper) => !ftlIds.has(trooper.fireteamLeaderId)),
    ];
}
