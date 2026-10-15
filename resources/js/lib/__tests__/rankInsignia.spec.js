import { describe, expect, it } from 'vitest';
import { INSIGNIA_BOX, insigniaPlacement } from '../rankInsignia';

const url = (n, query = '') => `https://7cav.us/data/roster_ranks/0/${n}.jpg${query}`;
const RANKS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 26, 27, 28, 29, 30];

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

    it('keeps every insignia inside the square, centred on the insignia itself', () => {
        // The insignia boxes from rankInsignia.js, repeated so the test checks the drawn result, not the table.
        const insignia = { 3: [1, 27, 73, 22], 10: [0, 0, 10, 30], 18: [1, 1, 28, 37], 22: [1, 2, 28, 26] };
        for (const [n, [x, y, w, h]] of Object.entries(insignia)) {
            const p = insigniaPlacement(url(n));
            const scale = p.width / { 3: 75, 10: 10, 18: 30, 22: 30 }[n];
            const left = p.left + x * scale;
            const top = p.top + y * scale;

            expect(left).toBeGreaterThanOrEqual(-0.01);
            expect(top).toBeGreaterThanOrEqual(-0.01);
            expect(left + w * scale).toBeLessThanOrEqual(INSIGNIA_BOX + 0.01);
            expect(top + h * scale).toBeLessThanOrEqual(INSIGNIA_BOX + 0.01);
            expect(left + (w * scale) / 2).toBeCloseTo(INSIGNIA_BOX / 2, 1);
            expect(top + (h * scale) / 2).toBeCloseTo(INSIGNIA_BOX / 2, 1);
        }
    });

    it('draws a thin lieutenant bar no taller than the square and smaller than its file, and a wide row of stars no wider', () => {
        const bar = insigniaPlacement(url(10));
        expect(bar.height).toBeLessThanOrEqual(INSIGNIA_BOX);
        expect(bar.height).toBeLessThan(30);

        const stars = insigniaPlacement(url(3));
        expect((stars.width * 73) / 75).toBeLessThanOrEqual(INSIGNIA_BOX + 0.01);
    });

    it('gives chevrons and a general of the army a similar size, though their files differ fourfold', () => {
        const sgt = insigniaPlacement(url(18));
        const ga = insigniaPlacement(url(1));
        const drawn = (p, file, ins) => (p.width / file[0]) * ins;

        const sgtArea = drawn(sgt, [30], 28) * drawn(sgt, [30], 37);
        const gaArea = drawn(ga, [75], 73) * drawn(ga, [75], 68);
        expect(sgtArea / gaArea).toBeGreaterThan(0.8);
        expect(sgtArea / gaArea).toBeLessThan(1.25);
    });
});
