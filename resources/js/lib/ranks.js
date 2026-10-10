// Rank order for screens that list troopers senior first (the rollcall, Story 3.7). Junior to senior; a rank not named
// here sorts below every known one.
export const RANK_ORDER = [
    'RCT', 'PVT', 'PFC', 'SPC', 'CPL', 'SGT', 'SSG', 'SFC', 'MSG', '1SG', 'SGM', 'CSM',
    'WO1', 'CW2', 'CW3', 'CW4', 'CW5', '2LT', '1LT', 'CPT', 'MAJ', 'LTC', 'COL', 'BG', 'MG', 'LTG', 'GEN',
];

/** The rank's place in {@link RANK_ORDER}, or -1 when unknown (or missing), so it sorts below every known rank. */
export function rankIndex(rank) {
    return RANK_ORDER.indexOf(rank);
}

/** Senior first, then by display name: the order every trooper list in the portal uses. */
export function compareSeniorFirst(a, b) {
    return rankIndex(b.rankShort) - rankIndex(a.rankShort) || a.displayName.localeCompare(b.displayName);
}
