// Suggestions for the unmatched-players drawer (Story 7.7, AD-19): which troopers an in-game name
// is closest to. A suggestion only orders the picker — nothing here ever chooses a trooper.

const MIN_SCORE = 0.6;
const MAX_SUGGESTIONS = 3;

// "[7CAV] Dubes_01" -> "dubes01": lower-case, clan tags and brackets dropped, letters and digits only.
export function normalizeName(name) {
    return String(name ?? '')
        .toLowerCase()
        .replace(/\[[^\]]*\]|\([^)]*\)/g, ' ')
        .replace(/[^a-z0-9]/g, '');
}

function distance(a, b) {
    let previous = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
        const row = [i];
        for (let j = 1; j <= b.length; j++) {
            row[j] = Math.min(previous[j] + 1, row[j - 1] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
        previous = row;
    }

    return previous[b.length];
}

// 0..1: one name containing the other (at least three letters) is a strong match, otherwise the
// edit distance relative to the longer name.
export function similarity(playerName, candidate) {
    const a = normalizeName(playerName);
    const b = normalizeName(candidate);
    if (!a || !b) return 0;
    if (Math.min(a.length, b.length) >= 3 && (a.includes(b) || b.includes(a))) return 0.9;

    return 1 - distance(a, b) / Math.max(a.length, b.length);
}

// A trooper's forum username is "Surname.I": match the surname part and the whole name.
function candidatesOf(trooper) {
    const username = trooper.name ?? '';

    return [username.split('.')[0], username, trooper.displayName].filter(Boolean);
}

// The troopers closest to `playerName`, best first (at most three, none below the threshold).
export function suggestTroopers(playerName, troopers) {
    return troopers
        .map((trooper) => ({ trooper, score: Math.max(0, ...candidatesOf(trooper).map((c) => similarity(playerName, c))) }))
        .filter(({ score }) => score >= MIN_SCORE)
        .sort((x, y) => y.score - x.score || String(x.trooper.name).localeCompare(String(y.trooper.name)))
        .slice(0, MAX_SUGGESTIONS)
        .map(({ trooper }) => trooper);
}
