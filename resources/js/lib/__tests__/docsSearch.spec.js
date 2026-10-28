import { describe, expect, it } from 'vitest';
import { matches, searchGuides, withinOneEdit } from '../docsSearch';

const guide = (title, { summary = '', headings = [], text = '' } = {}) => ({ title, summary, url: `/docs/x/${title}`, section: 'S', headings, text });

const INDEX = [
    guide('Run this week\'s rollcall', { summary: 'Mark attendance, confirm, post.', headings: ['Check unmatched players'], text: 'A guest is anyone from another section.' }),
    guide('Add a guest to practice', { summary: 'Mark a trooper from another section as present.' }),
    guide('Write and send your report', { summary: 'Compose, save a draft, send.', text: 'The rollcall feeds the report.' }),
    guide('Glossary', { text: 'LOA: leave of absence. Rollcall: the weekly attendance list.' }),
];

const titles = (query, index = INDEX) => searchGuides(index, query).map((g) => g.title);

describe('searchGuides', () => {
    it('ranks a title match above a summary, a heading and the body', () => {
        expect(titles('guest')).toEqual(['Add a guest to practice', 'Run this week\'s rollcall']);
        expect(titles('rollcall')).toEqual(['Run this week\'s rollcall', 'Write and send your report', 'Glossary']);
        expect(titles('unmatched')).toEqual(['Run this week\'s rollcall']);
    });

    it('needs every word typed, in any field', () => {
        expect(titles('guest section')).toEqual(['Add a guest to practice', 'Run this week\'s rollcall']);
        expect(titles('guest report')).toEqual([]);
    });

    it('finds a word by its start, and forgives one wrong character', () => {
        expect(titles('rep')).toEqual(['Write and send your report']);
        expect(titles('gust')).toContain('Add a guest to practice');
        expect(titles('rolcall')).toContain('Run this week\'s rollcall');
        expect(titles('LOA')).toEqual(['Glossary']);
    });

    it('finds nothing for an empty search or an unknown word', () => {
        expect(titles('   ')).toEqual([]);
        expect(titles('zzzz')).toEqual([]);
    });

    it('searches only the index it is given, which the server already filtered to the reader', () => {
        expect(titles('report', [INDEX[0]])).toEqual([]);
    });
});

describe('matching', () => {
    it('allows one edit, never two', () => {
        expect(withinOneEdit('guest', 'gust')).toBe(true);
        expect(withinOneEdit('guest', 'guests')).toBe(true);
        expect(withinOneEdit('guest', 'quest')).toBe(true);
        expect(withinOneEdit('guest', 'gusts')).toBe(false);
    });

    it('allows no typo in a word under three letters', () => {
        expect(matches('lo', 'loa')).toBe(true);
        expect(matches('lo', 'to')).toBe(false);
    });
});
