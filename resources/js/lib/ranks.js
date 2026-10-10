// Rank order for screens that list troopers, senior first (the rollcall, Story 3.7). Junior to senior; a rank not named
// here sorts below every known one.
export const RANK_ORDER = [
    'RCT', 'PVT', 'PFC', 'SPC', 'CPL', 'SGT', 'SSG', 'SFC', 'MSG', '1SG', 'SGM', 'CSM',
    'WO1', 'CW2', 'CW3', 'CW4', 'CW5', '2LT', '1LT', 'CPT', 'MAJ', 'LTC', 'COL', 'BG', 'MG', 'LTG', 'GEN',
];

/** The rank's place in {@link RANK_ORDER}, or -1 when unknown (or missing), so it sorts below every known rank. */
export function rankIndex(rank) {
    return RANK_ORDER.indexOf(rank);
}

/**
 * Where a billet puts a trooper in a unit's list: 0 for the leader, 1 for the assistant leader, 2 for everyone else. It
 * reads the billet's own words ("Section Leader", "Asst. Section Leader"), the same split the roster screen makes.
 */
export function billetOrder(billet) {
    const text = (billet ?? '').toLowerCase();

    if (/\b(asst|assistant)\b.*\bleader\b/.test(text)) return 1;
    if (/\bleader\b/.test(text)) return 0;

    return 2;
}

/**
 * The order every trooper list on the rollcall uses, as the S6 mockups draw it: the leader, then the assistant leader, then
 * everyone else by rank (senior first), then by name.
 */
export function compareByBillet(a, b) {
    return billetOrder(a.billet) - billetOrder(b.billet) || rankIndex(b.rankShort) - rankIndex(a.rankShort) || a.displayName.localeCompare(b.displayName);
}
