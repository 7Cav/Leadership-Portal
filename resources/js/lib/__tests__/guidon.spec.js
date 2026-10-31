import { describe, expect, it } from 'vitest';
import {
    HEIGHT,
    TEXTLESS_BELOW,
    WIDTH,
    cleanText,
    contrastRatio,
    defaultDesign,
    defaultText,
    guidonDataUrl,
    guidonFileName,
    guidonSvg,
    cleanLarge,
    isDesign,
    pairTooClose,
    textColorOn,
    tooClose,
} from '../guidon';

const design = { primary: '#1f2a44', secondary: '#ffffff', topLeft: '2/7', bottomRight: 'A' };

function parse(svg) {
    return new DOMParser().parseFromString(svg, 'image/svg+xml').documentElement;
}

// The texts, drawn as paths: each is a group carrying its text and cap height. `large` picks the large character's
// copies (clipped to a half) or the other texts.
function texts(svg, large = false) {
    return [...svg.querySelectorAll('[data-text]')].filter((g) => g.parentNode.hasAttribute('clip-path') === large);
}

// Where a text's group starts: its left edge and baseline.
function origin(g) {
    const [, x, y] = /translate\(([\d.-]+) ([\d.-]+)\)/.exec(g.getAttribute('transform'));

    return { x: Number(x), y: Number(y) };
}

describe('guidonSvg', () => {
    it('draws the swallowtail split on the diagonal, the first color top left and the second bottom right', () => {
        const svg = parse(guidonSvg(design));
        const [topLeft, bottomRight, outline] = svg.querySelectorAll('polygon');

        expect(svg.getAttribute('viewBox')).toBe(`0 0 ${WIDTH} ${HEIGHT}`);
        expect(topLeft.getAttribute('points')).toBe('0,0 300,0 0,216');
        expect(topLeft.getAttribute('fill')).toBe('#1f2a44');
        // The bottom right half runs from the diagonal to the tails, with the swallowtail cut in the fly.
        expect(bottomRight.getAttribute('points')).toBe('0,216 300,0 192,108 300,216');
        expect(bottomRight.getAttribute('fill')).toBe('#ffffff');
        expect(outline.getAttribute('points')).toBe('0,0 300,0 192,108 300,216 0,216');
    });

    it('puts each text on its half in the color that reads on it', () => {
        const [topLeft, bottomRight] = texts(parse(guidonSvg(design)));

        expect(topLeft.getAttribute('data-text')).toBe('2/7');
        expect(topLeft.getAttribute('fill')).toBe('#ffffff');
        expect(bottomRight.getAttribute('data-text')).toBe('A');
        expect(bottomRight.getAttribute('fill')).toBe('#000000');
    });

    it('draws the text as paths in a condensed face, so no font is needed wherever the file goes', () => {
        const svg = parse(guidonSvg(design));
        const [topLeft] = texts(svg);

        expect(svg.querySelectorAll('text')).toHaveLength(0);
        expect(guidonSvg(design)).not.toContain('font-family');
        // One path per glyph: 2, / and 7.
        expect(topLeft.querySelectorAll('path')).toHaveLength(3);
        expect(Number(topLeft.getAttribute('data-cap'))).toBe(70);
    });

    it('draws a plain guidon on request: the colors and split only, no texts and no large character', () => {
        const svg = parse(guidonSvg({ ...design, large: '7' }, { plain: true }));

        expect(svg.querySelectorAll('[data-text], clipPath')).toHaveLength(0);
        expect(svg.querySelectorAll('polygon')).toHaveLength(3);
        expect(TEXTLESS_BELOW).toBe(20);
    });

    it('leaves out an empty text and shrinks a long one', () => {
        const short = parse(guidonSvg({ ...design, topLeft: '7', bottomRight: '' }));
        const long = parse(guidonSvg({ ...design, topLeft: 'ACD 12' }));

        expect(texts(short)).toHaveLength(1);
        expect(Number(texts(long)[0].getAttribute('data-cap'))).toBeLessThan(Number(texts(short)[0].getAttribute('data-cap')));
    });

    it('scales to the width asked for, keeping the proportions', () => {
        const svg = parse(guidonSvg(design, { width: 600 }));

        expect(svg.getAttribute('width')).toBe('600');
        expect(svg.getAttribute('height')).toBe('432');
    });

    it('draws nothing from a design that breaks the rules', () => {
        expect(guidonSvg(null)).toBeNull();
        expect(guidonSvg({ ...design, primary: 'red' })).toBeNull();
        expect(guidonSvg({ ...design, primary: '#fff" onload="x' })).toBeNull();
        expect(guidonSvg({ ...design, topLeft: '<script>' })).toBeNull();
        expect(guidonSvg({ ...design, bottomRight: 'TOOLONG' })).toBeNull();
        expect(guidonDataUrl({ ...design, secondary: '#12345' })).toBeNull();
        expect(isDesign(design)).toBe(true);
    });

    it('is a data URL an <img> can show', () => {
        expect(guidonDataUrl(design)).toMatch(/^data:image\/svg\+xml;charset=utf-8,%3Csvg/);
        expect(decodeURIComponent(guidonDataUrl(design).split(',')[1])).toBe(guidonSvg(design));
    });
});

describe('contrast', () => {
    it('follows the WCAG ratio', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrastRatio('#777777', '#777777')).toBe(1);
        expect(contrastRatio('#ffffff', '#000000')).toBe(contrastRatio('#000000', '#ffffff'));
    });

    it('picks black or white text, whichever reads better', () => {
        expect(textColorOn('#1f2a44')).toBe('#ffffff');
        expect(textColorOn('#000000')).toBe('#ffffff');
        expect(textColorOn('#ffffff')).toBe('#000000');
        expect(textColorOn('#ffc72c')).toBe('#000000');
        expect(textColorOn('#b22234')).toBe('#ffffff');
    });

    it('warns when the two colors are too close, not for the wiki pairs', () => {
        expect(tooClose('#1f2a44', '#ffffff')).toBe(false);
        expect(tooClose('#000000', '#b22234')).toBe(false);
        expect(tooClose('#1f2a44', '#000000')).toBe(true);
        expect(tooClose('#ffffff', '#f0f0f0')).toBe(true);
    });
});

describe('default text', () => {
    it('puts the battalion and the 7 top left and the company letter bottom right', () => {
        expect(defaultText({ type: 'company', callsign: 'A/2-7' })).toEqual({ topLeft: '2/7', bottomRight: 'A' });
        expect(defaultText({ type: 'company', callsign: 'B/ACD' })).toEqual({ topLeft: 'ACD', bottomRight: 'B' });
    });

    it('gives a battalion its number and the regiment its 7', () => {
        expect(defaultText({ type: 'battalion', callsign: '1-7' })).toEqual({ topLeft: '1/7', bottomRight: '' });
        expect(defaultText({ type: 'battalion', callsign: 'DEVCOM' })).toEqual({ topLeft: 'DEVCOM', bottomRight: '' });
        expect(defaultText({ type: 'regiment', callsign: '7CAV' })).toEqual({ topLeft: '7', bottomRight: '' });
    });

    it('starts from a design that passes the rules', () => {
        expect(isDesign(defaultDesign({ type: 'company', callsign: 'A/2-7' }))).toBe(true);
        expect(isDesign(defaultDesign({ type: 'battalion', callsign: 'Very-Long Name' }))).toBe(true);
    });
});

describe('helpers', () => {
    it('keeps typed text to what the server accepts', () => {
        expect(cleanText('<b>2/7</b>')).toBe('b2/7/b');
        expect(cleanText('A"&B')).toBe('AB');
        expect(cleanText('ABCDEFGH')).toBe('ABCDEF');
    });

    it('names a download after the unit', () => {
        expect(guidonFileName({ callsign: 'A/2-7' }, 'png')).toBe('guidon-A-2-7.png');
        expect(guidonFileName({ name: '7th Cavalry Regiment' }, 'svg')).toBe('guidon-7th-Cavalry-Regiment.svg');
    });
});

describe('formats', () => {
    const acd = { format: 'diagonal', primary: '#f47a20', secondary: '#ffffff', topLeft: 'ACD', bottomRight: 'B', topAlign: 'left', bottomAlign: 'left', large: '7' };

    it('draws a design saved before formats the same, with or without the new fields at their defaults', () => {
        const legacy = guidonSvg(design);

        expect(legacy).toContain('<polygon points="0,0 300,0 0,216" fill="#1f2a44"/><polygon points="0,216 300,0 192,108 300,216" fill="#ffffff"/>');
        expect(texts(parse(legacy)).map((g) => g.getAttribute('data-text'))).toEqual(['2/7', 'A']);
        expect(guidonSvg({ ...design, format: 'diagonal', topAlign: 'left', bottomAlign: 'left', large: '' })).toBe(legacy);
        expect(guidonSvg({ ...design, topAlign: 'center' })).toBe(legacy);
    });

    it('splits the top and bottom format across the middle, the first color on top', () => {
        const svg = parse(guidonSvg({ ...design, format: 'horizontal' }));
        const [top, bottom] = svg.querySelectorAll('polygon');

        expect(top.getAttribute('points')).toBe('0,0 300,0 192,108 0,108');
        expect(top.getAttribute('fill')).toBe('#1f2a44');
        expect(bottom.getAttribute('points')).toBe('0,108 192,108 300,216 0,216');
        expect(bottom.getAttribute('fill')).toBe('#ffffff');
    });

    it('places each text left or centered on its half, in the color that reads on that half', () => {
        const rhq = { ...design, format: 'horizontal', primary: '#b22234', topLeft: 'U.S.', bottomRight: '7', topAlign: 'center', bottomAlign: 'left' };
        const [top, bottom] = texts(parse(guidonSvg(rhq)));

        expect(top.getAttribute('data-text')).toBe('U.S.');
        // Centered on the half clear of the cut, its capitals centered on the half's height.
        expect(origin(top).x).toBeGreaterThan(24);
        expect(origin(top).y).toBe(54 + Number(top.getAttribute('data-cap')) / 2);
        expect(top.getAttribute('fill')).toBe('#ffffff');
        expect(origin(bottom).x).toBe(24);
        expect(origin(bottom).y).toBe(162 + Number(bottom.getAttribute('data-cap')) / 2);
        expect(bottom.getAttribute('fill')).toBe('#000000');
    });

    it('draws a solid guidon in one color with no second half', () => {
        const svg = parse(guidonSvg({ ...design, format: 'solid', primary: '#ffc72c' }));
        const [flag] = svg.querySelectorAll('polygon');

        expect(svg.querySelectorAll('polygon')).toHaveLength(2);
        expect(flag.getAttribute('fill')).toBe('#ffc72c');
        expect(texts(svg).map((g) => g.getAttribute('fill'))).toEqual(['#000000', '#000000']);
    });

    it('draws the ACD guidon: a large 7 and both texts in the opposite color of each half, the texts right of the 7', () => {
        const svg = parse(guidonSvg(acd));
        const clips = svg.querySelectorAll('clipPath');
        const sevens = texts(svg, true);
        const [top, bottom] = texts(svg);

        expect(clips).toHaveLength(2);
        expect(clips[0].querySelector('polygon').getAttribute('points')).toBe('0,0 300,0 0,216');
        expect(sevens.map((g) => [g.getAttribute('data-text'), g.getAttribute('fill')])).toEqual([
            ['7', '#ffffff'],
            ['7', '#f47a20'],
        ]);
        // About 72% of the flag's height.
        expect(Number(sevens[0].getAttribute('data-cap'))).toBe(156);
        expect(top.getAttribute('data-text')).toBe('ACD');
        expect(top.getAttribute('fill')).toBe('#ffffff');
        expect(origin(top).x).toBeGreaterThan(100);
        // The top text's lowest point stays well inside the top left half: the diagonal is at x = 211 there.
        expect(origin(top).y).toBeLessThanOrEqual(66);
        expect(origin(top).x + 2 * (150 - origin(top).x)).toBeLessThanOrEqual(205);
        expect(bottom.getAttribute('data-text')).toBe('B');
        expect(bottom.getAttribute('fill')).toBe('#f47a20');
        expect(origin(bottom).x).toBeGreaterThan(110);
    });

    it('moves the top and bottom texts right of a large character', () => {
        const [top] = texts(parse(guidonSvg({ ...acd, format: 'horizontal' })));

        expect(origin(top).x).toBeGreaterThanOrEqual(148);
    });

    it("draws the regiment's 2/7 A guidon on the band format: a rectangle, a slanted navy band, a large 2", () => {
        const regiment = { format: 'band', primary: '#1f2a44', secondary: '#ffffff', topLeft: '7', bottomRight: 'A', topAlign: 'left', bottomAlign: 'left', large: '2' };
        const svg = parse(guidonSvg(regiment));
        const [bandShape, rest, edge] = [...svg.querySelectorAll(':scope > polygon')];
        const twos = texts(svg, true);
        const [seven, letter] = texts(svg);

        // A plain rectangle, no swallowtail; the band runs along the top edge to the fly and slants back to two
        // thirds of the width at the middle.
        expect(edge.getAttribute('points')).toBe('0,0 300,0 300,216 0,216');
        expect(bandShape.getAttribute('points')).toBe('0,0 300,0 200,108 0,108');
        expect(bandShape.getAttribute('fill')).toBe('#1f2a44');
        expect(rest.getAttribute('fill')).toBe('#ffffff');
        // The 2 is about 72% of the height, white on the band and navy below it.
        expect(twos.map((g) => [g.getAttribute('data-text'), g.getAttribute('fill'), g.getAttribute('data-cap')])).toEqual([
            ['2', '#ffffff', '156'],
            ['2', '#1f2a44', '156'],
        ]);
        // The 7 on the band (about 35% of the height), the A below it in navy (about 30%), both right of the 2.
        expect([seven.getAttribute('data-text'), seven.getAttribute('fill'), seven.getAttribute('data-cap')]).toEqual(['7', '#ffffff', '76']);
        expect([letter.getAttribute('data-text'), letter.getAttribute('fill'), letter.getAttribute('data-cap')]).toEqual(['A', '#1f2a44', '65']);
        expect(origin(seven).y).toBeLessThanOrEqual(92);
        expect(origin(letter).y).toBeGreaterThan(108);
        expect(origin(seven).x).toBeGreaterThan(100);
        expect(origin(letter).x).toBeGreaterThan(100);
    });

    it('refuses an unknown format, placement or large character', () => {
        expect(guidonSvg({ ...design, format: 'quartered' })).toBeNull();
        expect(guidonSvg({ ...design, topAlign: 'right' })).toBeNull();
        expect(guidonSvg({ ...design, large: '777' })).toBeNull();
        expect(guidonSvg({ ...design, large: '<' })).toBeNull();
        expect(isDesign(acd)).toBe(true);
    });

    it('warns about the pair only in a format that shows both colors', () => {
        const pair = { ...design, primary: '#777777', secondary: '#787878' };

        expect(pairTooClose(pair)).toBe(true);
        expect(pairTooClose({ ...pair, format: 'horizontal' })).toBe(true);
        expect(pairTooClose({ ...pair, format: 'band' })).toBe(true);
        expect(pairTooClose({ ...pair, format: 'solid' })).toBe(false);
    });

    it('starts a new guidon diagonal, left aligned, with no large character', () => {
        expect(defaultDesign({ type: 'company', callsign: 'A/2-7' })).toMatchObject({ format: 'diagonal', topAlign: 'left', bottomAlign: 'left', large: '' });
        expect(cleanLarge('7!x9')).toBe('7x');
    });
});
