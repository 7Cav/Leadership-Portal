// A game's calendar tags (#751) are typed on Settings > Games as one line, separated by commas: the text in a 7Cav
// calendar event's title that marks it as the game's.

/** The calendar tags typed in the form, as the list the server keeps: split at commas, trimmed, blanks dropped. */
export function splitTags(text) {
    return String(text ?? '')
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '');
}
