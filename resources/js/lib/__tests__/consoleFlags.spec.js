import { describe, expect, it, vi } from 'vitest';
import { ART, PAINT, PALETTE, flagMessage, printConsoleFlags } from '../consoleFlags';

describe('console flags', () => {
    it('gives every drawn character a colour from the palette, cell for cell', () => {
        expect(PAINT).toHaveLength(ART.length);
        ART.forEach((line, r) => {
            expect(PAINT[r]).toHaveLength(line.length);
            [...line].forEach((char, c) => {
                if (char !== ' ') expect(PALETTE).toHaveProperty(PAINT[r][c]);
            });
        });
    });

    it('draws both flags: Belgium black, yellow and red, the Netherlands red, white and blue', () => {
        const used = new Set(PAINT.join(''));

        for (const key of ['k', 'y', 'r', 'w', 'b']) expect(used.has(key)).toBe(true);
    });

    it('logs one message, with a style for every %c in it and the percent signs escaped', () => {
        const [format, ...styles] = flagMessage();

        expect(format.match(/%c/g)).toHaveLength(styles.length);
        expect(format.replace(/%c|%%/g, '')).not.toContain('%');
        expect(styles.every((style) => style.includes('monospace'))).toBe(true);

        const log = vi.fn();
        printConsoleFlags({ log });
        expect(log).toHaveBeenCalledOnce();
        expect(log.mock.calls[0][0]).toBe(format);
    });
});
