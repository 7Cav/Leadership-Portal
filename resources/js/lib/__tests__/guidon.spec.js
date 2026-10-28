import { describe, expect, it } from 'vitest';
import {
    HEIGHT,
    WIDTH,
    cleanText,
    contrastRatio,
    defaultDesign,
    defaultText,
    guidonDataUrl,
    guidonFileName,
    guidonSvg,
    isDesign,
    textColorOn,
    tooClose,
} from '../guidon';

const design = { primary: '#1f2a44', secondary: '#ffffff', topLeft: '2/7', bottomRight: 'A' };

function parse(svg) {
    return new DOMParser().parseFromString(svg, 'image/svg+xml').documentElement;
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
        const [topLeft, bottomRight] = parse(guidonSvg(design)).querySelectorAll('text');

        expect(topLeft.textContent).toBe('2/7');
        expect(topLeft.getAttribute('fill')).toBe('#ffffff');
        expect(bottomRight.textContent).toBe('A');
        expect(bottomRight.getAttribute('fill')).toBe('#000000');
    });

    it('leaves out an empty text and shrinks a long one', () => {
        const short = parse(guidonSvg({ ...design, topLeft: '7', bottomRight: '' }));
        const long = parse(guidonSvg({ ...design, topLeft: 'ACD 12' }));

        expect(short.querySelectorAll('text')).toHaveLength(1);
        expect(Number(long.querySelector('text').getAttribute('font-size'))).toBeLessThan(Number(short.querySelector('text').getAttribute('font-size')));
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
