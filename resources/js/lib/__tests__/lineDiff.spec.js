import { describe, expect, it } from 'vitest';
import { diffCounts, diffLines, foldUnchanged } from '../lineDiff';

const marks = (lines) => lines.map((line) => (line.type === 'skip' ? `~${line.count}` : `${{ same: ' ', removed: '-', added: '+' }[line.type]}${line.text}`));

describe('diffLines', () => {
    it('keeps every line of two equal texts', () => {
        expect(diffLines('a\nb\nc', 'a\nb\nc')).toEqual([
            { type: 'same', text: 'a', oldLine: 1, newLine: 1 },
            { type: 'same', text: 'b', oldLine: 2, newLine: 2 },
            { type: 'same', text: 'c', oldLine: 3, newLine: 3 },
        ]);
    });

    it('marks an added and a removed line with their line numbers on their own side', () => {
        expect(diffLines('a\nb\nc', 'a\nc\nd')).toEqual([
            { type: 'same', text: 'a', oldLine: 1, newLine: 1 },
            { type: 'removed', text: 'b', oldLine: 2, newLine: null },
            { type: 'same', text: 'c', oldLine: 3, newLine: 2 },
            { type: 'added', text: 'd', oldLine: null, newLine: 3 },
        ]);
    });

    it('shows a changed line as the old one removed, then the new one added', () => {
        expect(marks(diffLines('[B]{{unit}}[/B]\n{{week}}', '[B]{{unit_name}}[/B]\n{{week}}'))).toEqual(['-[B]{{unit}}[/B]', '+[B]{{unit_name}}[/B]', ' {{week}}']);
    });

    it('keeps the longest run of shared lines in step', () => {
        // The shared lines are a, b, c, d; x and y are only in one text each.
        expect(marks(diffLines('a\nx\nb\nc\nd', 'a\nb\ny\nc\nd'))).toEqual([' a', '-x', ' b', '+y', ' c', ' d']);
    });

    it('reads moved lines as removed in one place and added in the other', () => {
        const lines = diffLines('a\nb\nc', 'c\na\nb');

        expect(diffCounts(lines)).toEqual({ added: 1, removed: 1 });
        expect(marks(lines)).toEqual(['+c', ' a', ' b', '-c']);
    });

    it('treats an empty text as no lines', () => {
        expect(marks(diffLines('', 'a\nb'))).toEqual(['+a', '+b']);
        expect(marks(diffLines('a', ''))).toEqual(['-a']);
        expect(diffLines('', '')).toEqual([]);
    });

    it('ignores Windows line endings, so a pasted copy of the same text has no changes', () => {
        expect(diffCounts(diffLines('a\r\nb\r\n', 'a\nb\n'))).toEqual({ added: 0, removed: 0 });
    });

    it('counts a trailing newline as a line of its own', () => {
        expect(marks(diffLines('a', 'a\n'))).toEqual([' a', '+']);
    });

    it('keeps whitespace, so an indent change shows', () => {
        expect(marks(diffLines('[TR]', '  [TR]'))).toEqual(['-[TR]', '+  [TR]']);
    });

    it('falls back to the whole changed middle removed then added when the texts are too large to compare line by line', () => {
        const lines = diffLines('same\na\nb\nend', 'same\nb\na\nend', { maxCells: 3 });

        expect(marks(lines)).toEqual([' same', '-a', '-b', '+b', '+a', ' end']);
    });

    it('compares two long templates quickly', () => {
        const before = Array.from({ length: 1500 }, (_, i) => `line ${i}`).join('\n');
        const after = Array.from({ length: 1500 }, (_, i) => (i % 3 === 0 ? `changed ${i}` : `line ${i}`)).join('\n');
        const started = performance.now();

        expect(diffCounts(diffLines(before, after))).toEqual({ added: 500, removed: 500 });
        expect(performance.now() - started).toBeLessThan(2000);
    });
});

describe('foldUnchanged', () => {
    const text = (count, prefix = 'l') => Array.from({ length: count }, (_, i) => `${prefix}${i + 1}`).join('\n');

    it('folds a long run of kept lines between two changes, keeping the context on both sides', () => {
        const before = `${text(10)}`;
        const after = before.replace('l1\n', 'L1\n').replace('l10', 'L10');

        expect(marks(foldUnchanged(diffLines(before, after), 2))).toEqual(['-l1', '+L1', ' l2', ' l3', '~4', ' l8', ' l9', '-l10', '+L10']);
    });

    it('folds the kept lines before the first change and after the last down to the context', () => {
        const before = text(12);
        const after = before.replace('l6', 'L6');

        expect(marks(foldUnchanged(diffLines(before, after), 1))).toEqual(['~4', ' l5', '-l6', '+L6', ' l7', '~5']);
    });

    it('does not fold a run no longer than the context it would keep', () => {
        const before = 'a\nb\nc\nd\ne';
        const after = 'A\nb\nc\nd\nE';

        expect(marks(foldUnchanged(diffLines(before, after), 2))).toEqual(['-a', '+A', ' b', ' c', ' d', '-e', '+E']);
    });

    it('folds two equal texts into one skip', () => {
        expect(foldUnchanged(diffLines(text(5), text(5)))).toEqual([{ type: 'skip', count: 5 }]);
    });

    it('returns nothing for nothing', () => {
        expect(foldUnchanged([])).toEqual([]);
    });
});
