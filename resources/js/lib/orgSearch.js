// Find units on Settings > Org structure by name or callsign. Plain functions over the page's flat
// `units` prop (each with a `path` like "1/4/9/"), so the matching is testable without the tree.

// The ids above a unit, root first, read from its materialized `path` (AD-18).
export function ancestorIds(unit) {
    return String(unit.path ?? '')
        .split('/')
        .filter(Boolean)
        .map(Number)
        .filter((id) => id !== unit.id);
}

// Case-insensitive substring match on the name or the callsign. Results keep chain-of-command
// order (by path), and each one carries the names of the units above it, root left out as on the
// placement picker, so "1st Squad" under two platoons can be told apart.
export function searchUnits(units, query, { limit = 20 } = {}) {
    const needle = String(query ?? '').trim().toLowerCase();
    if (!needle) {
        return { results: [], total: 0 };
    }

    const byId = new Map(units.map((unit) => [unit.id, unit]));
    const matches = units
        .filter((unit) => unit.name.toLowerCase().includes(needle) || (unit.callsign ?? '').toLowerCase().includes(needle))
        .sort((a, b) => String(a.path).localeCompare(String(b.path), undefined, { numeric: true }));

    const results = matches.slice(0, limit).map((unit) => ({
        id: unit.id,
        name: unit.name,
        callsign: unit.callsign,
        type: unit.type,
        trail: ancestorIds(unit)
            .slice(1)
            .map((id) => byId.get(id)?.name)
            .filter(Boolean)
            .join(' / '),
    }));

    return { results, total: matches.length };
}
