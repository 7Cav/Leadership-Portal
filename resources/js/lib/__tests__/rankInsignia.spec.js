import { describe, expect, it } from 'vitest';
import { INSIGNIA_BOX, insigniaPlacement } from '../rankInsignia';

const url = (n, query = '') => `https://7cav.us/data/roster_ranks/0/${n}.jpg${query}`;
const RANKS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 26, 27, 28, 29, 30];

// Measurements repeated from rankInsignia.js for the ranks the owner checked by eye, so these tests check the drawn
// result rather than echo the table: file width, insignia box (x, y, w, h), centre of mass (cx, cy), ink.
const SAMPLE = {
    1: { file: 75, box: [1, 1, 74, 68], mass: [37.7, 38.0], ink: 1189 }, // General of the Army
    3: { file: 75, box: [1, 27, 74, 22], mass: [37.8, 38.9], ink: 516 }, // Lieutenant General
    10: { file: 10, box: [0, 0, 10, 30], mass: [5.0, 15.0], ink: 289 }, // First Lieutenant
    12: { file: 30, box: [1, 1, 28, 52], mass: [15.0, 31.3], ink: 1081 }, // Command Sergeant Major
    18: { file: 30, box: [1, 1, 28, 37], mass: [15.0, 22.3], ink: 583 }, // Sergeant
    22: { file: 30, box: [1, 2, 28, 26], mass: [15.0, 16.8], ink: 265 }, // Private
    29: { file: 9, box: [0, 0, 9, 30], mass: [4.5, 15.0], ink: 270 }, // Chief Warrant Officer 2
};

const drawn = (n) => {
    const p = insigniaPlacement(url(n));
    const { file, box: [x, y, w, h], mass: [cx, cy], ink } = SAMPLE[n];
    const scale = p.width / file;

    return {
        left: p.left + x * scale,
        top: p.top + y * scale,
        right: p.left + (x + w) * scale,
        bottom: p.top + (y + h) * scale,
        massX: p.left + cx * scale,
        massY: p.top + cy * scale,
        ink: ink * scale * scale,
    };
};

describe('insigniaPlacement', () => {
    it('places every known rank, with or without the forum cache-busting query', () => {
        for (const n of RANKS) {
            expect(insigniaPlacement(url(n))).not.toBeNull();
            expect(insigniaPlacement(url(n, '?1741376831'))).toEqual(insigniaPlacement(url(n)));
        }
    });

    it('leaves anything that is not a known rank insignia alone', () => {
        expect(insigniaPlacement(url(99))).toBeNull();
        expect(insigniaPlacement('https://7cav.us/styles/7cav/xenforo/cav_logo.png')).toBeNull();
        expect(insigniaPlacement('https://example.com/data/roster_ranks/0/18.jpg')).toBeNull();
        expect(insigniaPlacement(url(18, '?x=1'))).toBeNull();
    });

    it('keeps every insignia inside the square', () => {
        for (const n of Object.keys(SAMPLE)) {
            const d = drawn(n);
            expect(d.left).toBeGreaterThanOrEqual(-0.01);
            expect(d.top).toBeGreaterThanOrEqual(-0.01);
            expect(d.right).toBeLessThanOrEqual(INSIGNIA_BOX + 0.01);
            expect(d.bottom).toBeLessThanOrEqual(INSIGNIA_BOX + 0.01);
        }
    });

    it('centres a sergeant, a command sergeant major and a general of the army on their weight, not their outline', () => {
        for (const n of [1, 12, 18]) {
            const d = drawn(n);
            expect(d.massX).toBeCloseTo(INSIGNIA_BOX / 2, 0);
            expect(d.massY).toBeCloseTo(INSIGNIA_BOX / 2, 0);
            // Their outline's centre sits above the middle, because their weight sits low in it.
            expect((d.top + d.bottom) / 2).toBeLessThan(INSIGNIA_BOX / 2);
        }
    });

    it('gives a solid lieutenant or warrant officer bar the same weight as a set of chevrons', () => {
        const sgt = drawn(18).ink;
        for (const n of [10, 22, 29, 12]) {
            expect(drawn(n).ink / sgt).toBeGreaterThan(0.95);
            expect(drawn(n).ink / sgt).toBeLessThan(1.05);
        }
    });

    it('shrinks a wide row of stars to fit rather than letting it spill out', () => {
        const ltg = drawn(3);
        expect(ltg.right - ltg.left).toBeCloseTo(INSIGNIA_BOX, 0);
        expect(ltg.ink).toBeLessThan(drawn(18).ink);
    });
});
