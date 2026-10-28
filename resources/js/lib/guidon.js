// A unit's guidon (#446): the swallowtail flag split on the diagonal, drawn as SVG from the saved design. This is the
// one place the flag is drawn: the small flag next to a unit, the generator's preview, and both downloads (the SVG
// itself, and the PNG a canvas makes from it) all come from guidonSvg(). It only ever draws a design that passes the
// same rules the server checks (two `#rrggbb` colors, two short texts of letters, digits, `/`, `-` and spaces), and
// escapes the text anyway, so nothing typed can become markup.

// A US Army guidon is 20 by 27¾ inches with a 10 inch swallowtail cut; the drawing keeps those proportions.
export const WIDTH = 300;
export const HEIGHT = 216;
const NOTCH = 108;

// Sized for the wiki's unit pages.
export const DOWNLOAD_WIDTH = 300;

export const MAX_TEXT = 6;
const HEX = /^#[0-9a-f]{6}$/i;
const TEXT = /^[A-Za-z0-9/\- ]*$/;
// Any character the server would refuse, for stripping from what a leader types.
const NOT_TEXT = /[^A-Za-z0-9/\- ]/g;

// Below this contrast ratio the two halves are hard to tell apart (WCAG ratio, 1 is identical, 21 black on white).
export const MIN_PAIR_CONTRAST = 1.5;

// The set palette (the wiki's guidon colors and the Army's branch colors); any other color can be picked as well.
export const PALETTE = [
    { name: 'Navy', hex: '#1f2a44' },
    { name: 'White', hex: '#ffffff' },
    { name: 'Black', hex: '#000000' },
    { name: 'Red', hex: '#b22234' },
    { name: 'Maroon', hex: '#800000' },
    { name: 'Cavalry yellow', hex: '#ffc72c' },
    { name: 'Orange', hex: '#e87722' },
    { name: 'Green', hex: '#2e5e3e' },
    { name: 'Blue', hex: '#0033a0' },
    { name: 'Gray', hex: '#6b7280' },
];

export function isColor(value) {
    return typeof value === 'string' && HEX.test(value);
}

export function isText(value) {
    return typeof value === 'string' && value.length <= MAX_TEXT && TEXT.test(value);
}

export function isDesign(design) {
    return Boolean(design) && isColor(design.primary) && isColor(design.secondary) && isText(design.topLeft) && isText(design.bottomRight);
}

// What a leader typed, kept to what the server accepts: allowed characters only, at most MAX_TEXT of them.
export function cleanText(value) {
    return String(value ?? '')
        .replace(NOT_TEXT, '')
        .slice(0, MAX_TEXT);
}

// WCAG 2 relative luminance of a `#rrggbb` color.
export function luminance(hex) {
    const channel = (offset) => {
        const c = parseInt(hex.slice(offset, offset + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

// WCAG 2 contrast ratio between two colors, from 1 to 21.
export function contrastRatio(a, b) {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

// Black or white text, whichever reads better on the color.
export function textColorOn(hex) {
    return contrastRatio(hex, '#ffffff') >= contrastRatio(hex, '#000000') ? '#ffffff' : '#000000';
}

// Whether the two halves are too alike to tell apart.
export function tooClose(a, b) {
    return contrastRatio(a, b) < MIN_PAIR_CONTRAST;
}

// The text a new guidon starts with, from the unit's designation: the battalion and the 7 on the top left ("2/7"),
// the company letter on the bottom right ("A"). A battalion gets only its number; the regiment gets "7". A designation
// that is not a number (ACD) is used as it is, kept to what a guidon may carry.
export function defaultText(unit) {
    const callsign = String(unit?.callsign ?? '');
    const battalion = (designation) => {
        const numbered = /^(\d+)-7$/.exec(designation);

        return numbered ? `${numbered[1]}/7` : cleanText(designation);
    };

    if (unit?.type === 'company') {
        const [letter = '', bn = ''] = callsign.split('/');

        return { topLeft: battalion(bn), bottomRight: cleanText(letter) };
    }
    if (unit?.type === 'battalion') return { topLeft: battalion(callsign), bottomRight: '' };

    return { topLeft: '7', bottomRight: '' };
}

export function defaultDesign(unit) {
    return { primary: '#1f2a44', secondary: '#ffffff', ...defaultText(unit) };
}

function escapeXml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]);
}

// Shrinks longer text so it stays inside its half.
function fontSize(text, base, maxWidth) {
    return Math.round(Math.min(base, maxWidth / (Math.max(text.length, 1) * 0.62)));
}

function label(text, x, y, base, maxWidth, fill) {
    if (!text.trim()) return '';

    return `<text x="${x}" y="${y}" fill="${fill}" font-size="${fontSize(text, base, maxWidth)}" font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle" dominant-baseline="central">${escapeXml(text)}</text>`;
}

// The flag as an SVG document, `width` pixels wide. Null for a design that breaks the rules: nothing is drawn from it.
export function guidonSvg(design, { width = WIDTH } = {}) {
    if (!isDesign(design)) return null;

    const height = Math.round((width * HEIGHT) / WIDTH);
    const primary = design.primary.toLowerCase();
    const secondary = design.secondary.toLowerCase();
    // The diagonal runs from the bottom left corner to the top right one; the bottom right half carries the tails.
    const topLeft = `0,0 ${WIDTH},0 0,${HEIGHT}`;
    const bottomRight = `0,${HEIGHT} ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT}`;
    const outline = `0,0 ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT} 0,${HEIGHT}`;

    return [
        `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${WIDTH} ${HEIGHT}">`,
        `<polygon points="${topLeft}" fill="${primary}"/>`,
        `<polygon points="${bottomRight}" fill="${secondary}"/>`,
        label(design.topLeft, 80, 72, 64, 150, textColorOn(primary)),
        label(design.bottomRight, 172, 160, 80, 110, textColorOn(secondary)),
        // A faint edge, so a white half still shows against a white page.
        `<polygon points="${outline}" fill="none" stroke="#000000" stroke-opacity="0.25" stroke-width="2" stroke-linejoin="round"/>`,
        '</svg>',
    ].join('');
}

// The flag as a `data:` URL for an <img>: the browser draws it as an image, never as part of the page.
export function guidonDataUrl(design, options) {
    const svg = guidonSvg(design, options);

    return svg === null ? null : `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

// The file name a download gets: `guidon-A-1-7.svg`.
export function guidonFileName(unit, extension) {
    const name = String(unit?.callsign ?? unit?.name ?? 'unit').replace(/[^A-Za-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');

    return `guidon-${name || 'unit'}.${extension}`;
}

// The flag as a PNG `data:` URL, `width` pixels wide, drawn on a canvas from the same SVG.
export function guidonPngDataUrl(design, width = DOWNLOAD_WIDTH) {
    const src = guidonDataUrl(design, { width });
    if (src === null) return Promise.reject(new Error('Not a guidon design.'));

    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = Math.round((width * HEIGHT) / WIDTH);
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/png'));
        };
        image.onerror = () => reject(new Error('The guidon could not be drawn.'));
        image.src = src;
    });
}
