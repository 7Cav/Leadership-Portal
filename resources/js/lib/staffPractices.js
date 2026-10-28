// The section practices a staff member attended (#448), as the milpac and the roster Sheet word each entry. The server
// shapes them (`Milpac\StaffPracticeHistory`): `entered` by the staff member themselves, their leader or an Admin, or
// `capture`, a practice whose game-server capture saw them.

/** Where an entry came from, in a few words: "Self-reported by Mercer.J", "Entered by Hale.S", "Seen on the game server". */
export function staffPracticeSource(entry) {
    if (entry.source === 'capture') return 'Seen on the game server';
    if (entry.selfReported) return `Self-reported by ${entry.enteredBy ?? 'them'}`;

    return `Entered by ${entry.enteredBy ?? 'a user no longer in the portal'}`;
}

/** A stable key for one entry: one per host practice. */
export function staffPracticeKey(entry) {
    return `${entry.week}-${entry.sectionId}`;
}
