// A changelog entry's text is plain text with inline emphasis only: `**bold**`, `*italic*` or `_italic_`, used to name
// a feature ("Fixed a check where *Act as* meets the milpac page"). Everything else stays text: the input is escaped
// first, so HTML, links and block Markdown never reach the page.

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const escapeHtml = (text) => text.replace(/[&<>"']/g, (char) => ESCAPES[char]);

const BOLD = /\*\*(?=\S)(.+?)(?<=\S)\*\*/g;
const STAR = /\*(?=[^\s*])(.+?)(?<=[^\s*])\*/g;
const UNDERSCORE = /(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/gu;

/** The text as safe HTML with its emphasis as `<strong>` and `<em>`. */
export function emphasisHtml(text) {
    return escapeHtml(text ?? '')
        .replace(BOLD, '<strong>$1</strong>')
        .replace(STAR, '<em>$1</em>')
        .replace(UNDERSCORE, '<em>$1</em>');
}

/** The text without its emphasis markers, for places that show plain text. */
export function plainText(text) {
    return (text ?? '').replace(BOLD, '$1').replace(STAR, '$1').replace(UNDERSCORE, '$1');
}
