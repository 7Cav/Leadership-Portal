// The docs search (#413): a small scorer over the page's index of the guides this reader may read (about 25), so the
// search works once the page has loaded, with no request per keystroke and no search library.
//
// Every word typed must appear somewhere in a guide. A word counts when a word of the guide starts with it, or is it
// with one character added, dropped or changed ("gust" finds "guest"). Where it appears sets the score: the title
// above the summary above a heading above the body.

const WEIGHTS = { title: 100, summary: 40, headings: 20, text: 5 };

const words = (text) =>
    (text ?? '')
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter(Boolean);

// Whether `a` and `b` are at most one edit apart (one character added, dropped or changed).
export function withinOneEdit(a, b) {
    if (Math.abs(a.length - b.length) > 1) return false;
    let i = 0;
    let j = 0;
    let edits = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            i++;
            j++;
            continue;
        }
        if (++edits > 1) return false;
        if (a.length > b.length) i++;
        else if (a.length < b.length) j++;
        else {
            i++;
            j++;
        }
    }

    return edits + (a.length - i) + (b.length - j) <= 1;
}

// Whether a typed word matches a word of the guide. One edit is allowed from three letters on, so "lo" never finds
// "to", and a long word may also match the start of a longer one with a typo in it ("rolcall" in "rollcalls").
export function matches(term, word) {
    if (word.startsWith(term)) return true;
    if (term.length < 3) return false;

    return withinOneEdit(term, word) || (word.length > term.length && withinOneEdit(term, word.slice(0, term.length)));
}

function fieldScore(term, fields) {
    let best = 0;
    for (const [field, weight] of Object.entries(WEIGHTS)) {
        if (weight > best && fields[field].some((word) => matches(term, word))) best = weight;
    }

    return best;
}

/**
 * The guides matching `query`, best first; ties keep contents order.
 *
 * @param {Array<{title: string, summary: string, url: string, section: string, headings: string[], text: string}>} index
 */
export function searchGuides(index, query) {
    const terms = words(query);
    if (!terms.length) return [];

    return index
        .map((guide, position) => {
            const fields = {
                title: words(guide.title),
                summary: words(guide.summary),
                headings: words((guide.headings ?? []).join(' ')),
                text: words(guide.text),
            };
            const scores = terms.map((term) => fieldScore(term, fields));

            return { guide, position, score: scores.includes(0) ? 0 : scores.reduce((sum, s) => sum + s, 0) };
        })
        .filter((hit) => hit.score > 0)
        .sort((a, b) => b.score - a.score || a.position - b.position)
        .map((hit) => hit.guide);
}
