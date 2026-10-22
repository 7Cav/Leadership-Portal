import { rankOf } from './rollcall';
import { rankIndex } from './roster';

// The order a section is listed in, on the rollcall screen and in its forum post (the owner's order, 2026-10-16): the
// Section Leader first, then the Assistant Section Leader, then fireteam leaders (FTLs) above everyone else, each group
// by rank seniority (roster.js `RANK_ORDER`), then last name. FTLs are unofficial: the section's leaders mark them, and
// choose whether the post shows them at all (`show`, off until they turn it on) and where (`order`): `leaders_first`
// puts every FTL above the rest, `by_fireteam` puts each fireteam's leader directly above its members, with troopers in
// no fireteam last. Where the company allows it, the SL and ASL may lead a fireteam too. The company may enforce these
// choices; the server sends the values that apply.
//
// A trooper's `role` is `sl`, `asl`, `ftl` or null, `leadsFireteam` whether they lead a fireteam (an FTL, or an SL or
// ASL the company lets lead one), and `fireteamLeaderId` the leader of the fireteam they are in, all from the server
// (`App\Rollcall\SectionFireteams`). With FTLs not shown, an FTL is listed with everyone else and no fireteam is named.

export const ROLE_LABELS = { sl: 'SL', asl: 'ASL', ftl: 'FTL' };

const COMMAND_TITLES = { sl: 'Section Leader', asl: 'Assistant Section Leader' };

// Fireteams are named by letter in the order their leaders appear in the post. B/ACD (Baker Company, a WW2 unit) uses
// the WW2 alphabet; the server says which (`fireteams.alphabet`).
export const ALPHABETS = {
    nato: ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliett', 'Kilo', 'Lima', 'Mike', 'November', 'Oscar', 'Papa', 'Quebec', 'Romeo', 'Sierra', 'Tango', 'Uniform', 'Victor', 'Whiskey', 'X-ray', 'Yankee', 'Zulu'],
    ww2: ['Able', 'Baker', 'Charlie', 'Dog', 'Easy', 'Fox', 'George', 'How', 'Item', 'Jig', 'King', 'Love', 'Mike', 'Nan', 'Oboe', 'Peter', 'Queen', 'Roger', 'Sugar', 'Tare', 'Uncle', 'Victor', 'William', 'X-ray', 'Yoke', 'Zebra'],
};

const GROUP = { sl: 0, asl: 1, ftl: 2 };

/** Whether the trooper leads a fireteam, shown or not. */
export function leadsFireteam(trooper) {
    return trooper.leadsFireteam ?? trooper.role === 'ftl';
}

/** The role a trooper is listed by: the SL and ASL always, an FTL only while FTLs are shown. */
export function shownRole(trooper, show) {
    if (trooper.role === 'sl' || trooper.role === 'asl') return trooper.role;

    return show && trooper.role === 'ftl' ? 'ftl' : null;
}

// A forum username is "Lastname.I": the last name is what comes before the last dot, the initial what comes after
// ("Van.Rooyen.J" is Van.Rooyen, J). The portal stores no real names. A trooper with no username has no name to sort by.
function nameKey(trooper) {
    if (!trooper.name) return null;
    const name = trooper.name.toLowerCase();
    const dot = name.lastIndexOf('.');

    return dot > 0 ? [name.slice(0, dot), name.slice(dot + 1)] : [name, ''];
}

const compareText = (x, y) => (x < y ? -1 : x > y ? 1 : 0);

function byName(a, b) {
    const [x, y] = [nameKey(a), nameKey(b)];
    if (!x || !y) return Number(!x) - Number(!y) || (a.cavId ?? 0) - (b.cavId ?? 0);

    return compareText(x[0], y[0]) || compareText(x[1], y[1]);
}

/**
 * Senior rank first (a rank not in the list sorts below every known one), then last name A to Z, then initial, then id
 * so the order is fixed. A trooper with no username comes after the named ones of their rank.
 */
export function bySeniority(a, b) {
    return rankIndex(rankOf(b)) - rankIndex(rankOf(a)) || byName(a, b) || a.id - b.id;
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

    // Each leader in turn (SL, ASL, then the FTLs), the members of the fireteam they lead right under them.
    const rest = sorted.filter((trooper) => group(trooper) === 3);
    const leaders = sorted.filter((trooper) => group(trooper) < 3);
    const leading = new Set(leaders.filter(leadsFireteam).map((trooper) => trooper.id));

    return [
        ...leaders.flatMap((leader) => [leader, ...(leading.has(leader.id) ? rest.filter((trooper) => trooper.fireteamLeaderId === leader.id) : [])]),
        ...rest.filter((trooper) => !leading.has(trooper.fireteamLeaderId)),
    ];
}

/**
 * Each shown fireteam's name, keyed by its leader's id: letters of the company's alphabet in the order the leaders
 * appear in `ordered` (so a fireteam the SL leads is the first). Empty while FTLs are hidden.
 *
 * @param {object[]} ordered the troopers as {@link orderTroopers} lists them
 * @param {{show?: boolean, alphabet?: 'nato'|'ww2'}} fireteams
 * @returns {Map<number, string>}
 */
export function fireteamNames(ordered, { show = false, alphabet = 'nato' } = {}) {
    if (!show) return new Map();
    const letters = ALPHABETS[alphabet] ?? ALPHABETS.nato;

    return new Map(ordered.filter(leadsFireteam).map((leader, index) => [leader.id, letters[index] ?? `Fireteam ${index + 1}`]));
}

/**
 * How the post names a trooper's role: "Section Leader", "Assistant Section Leader", "FTL Alpha", or for an SL or ASL
 * who leads a shown fireteam "Section Leader (FTL Alpha)". Empty for everyone else.
 *
 * @param {Map<number, string>} names from {@link fireteamNames}
 */
export function roleLabel(trooper, names) {
    const team = names.get(trooper.id);
    const title = COMMAND_TITLES[trooper.role];
    if (title) return team ? `${title} (FTL ${team})` : title;

    return team ? `FTL ${team}` : '';
}
