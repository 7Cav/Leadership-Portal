// Mirrors `CavDesignation::childOf()` (app/Commands/Units/CavDesignation.php) so the Add unit dialog can show what the
// server will create. The server derives the name and callsign itself and refuses a taken ordinal; this only offers
// the choices and previews the result.

export const MAX_ORDINAL = 9;

const NATO = {
    A: 'Alpha', B: 'Bravo', C: 'Charlie', D: 'Delta', E: 'Echo', F: 'Foxtrot', G: 'Golf', H: 'Hotel', I: 'India',
    J: 'Juliett', K: 'Kilo', L: 'Lima', M: 'Mike', N: 'November', O: 'Oscar', P: 'Papa', Q: 'Quebec', R: 'Romeo',
    S: 'Sierra', T: 'Tango', U: 'Uniform', V: 'Victor', W: 'Whiskey', X: 'X-ray', Y: 'Yankee', Z: 'Zulu',
};

// Companies whose name doesn't follow the NATO letter, by callsign, as `CavDesignation::NAMED_COMPANIES`.
const NAMED_COMPANIES = { 'B/ACD': 'Baker Company' };

const COMPANY = /^([A-Z])\/([A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)$/;

export function ordinal(number) {
    const suffix = [11, 12, 13].includes(number % 100) ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[number % 10] ?? 'th');
    return `${number}${suffix}`;
}

/** The `{ name, callsign }` the server derives for a `type` unit picked as `pick` under a parent with `parentCallsign`, or null. */
export function childDesignation(type, parentCallsign, pick) {
    const value = String(pick ?? '').trim().toUpperCase();
    const number = /^[1-9]$/.test(value) ? Number(value) : null;
    const letter = /^[A-Z]$/.test(value) ? value : null;
    const parent = String(parentCallsign ?? '').trim();

    if (type === 'battalion') {
        return number === null ? null : { name: `${ordinal(number)} Battalion`, callsign: `${number}-7` };
    }
    if (type === 'company') {
        if (letter === null || parent === '' || parent.includes('/')) return null;
        const callsign = `${letter}/${parent}`;
        return { name: NAMED_COMPANIES[callsign] ?? `${NATO[letter]} Company`, callsign };
    }
    if (type === 'platoon') {
        return number !== null && COMPANY.test(parent) ? { name: `${ordinal(number)} Platoon`, callsign: `${number}/${parent}` } : null;
    }
    if (type === 'section') {
        const [platoon, ...rest] = parent.split('/');
        return number !== null && /^[1-9]\d*$/.test(platoon) && COMPANY.test(rest.join('/'))
            ? { name: `${ordinal(number)} Section`, callsign: `${number}/${parent}` }
            : null;
    }
    return null;
}

/**
 * Every ordinal a new `type` unit under `parent` may take, as `{ value, name, callsign }`, leaving out the ones a
 * sibling already uses (a gap, such as a missing 2nd Section, stays on offer). Empty when the parent has no callsign
 * to build on.
 */
export function ordinalOptions(type, parent, units) {
    if (!parent) return [];
    const picks = type === 'company' ? Object.keys(NATO) : Array.from({ length: MAX_ORDINAL }, (_, i) => String(i + 1));
    const taken = new Set(
        units.filter((unit) => unit.parent_id === parent.id && unit.callsign).map((unit) => unit.callsign.toUpperCase()),
    );

    return picks
        .map((value) => ({ value, ...childDesignation(type, parent.callsign, value) }))
        .filter((option) => option.callsign && !taken.has(option.callsign.toUpperCase()));
}
