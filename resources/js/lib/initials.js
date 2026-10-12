// The letters in an avatar monogram: the first letter of the first two name parts, split on dots,
// spaces, underscores and hyphens ("Hale.S" is HS, "Perry.JW" is PJ). One shared rule, so the shell's
// user menu, the rollcall cards and the milpac draw a person the same way. Empty when there is no name.
export function initialsOf(name) {
    return String(name ?? '')
        .split(/[.\s_-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}
