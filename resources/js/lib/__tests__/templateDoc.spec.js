import { describe, expect, it } from 'vitest';
import cases from '../../../../tests/Fixtures/bbcode-templates.json';
import { buildDoc, contextAt, serialize, tokenize } from '../templateDoc';
import { STARTER } from './templateStarter';

// The visual editor's model (#704). The one promise it must keep is that a template goes in and comes out byte for byte,
// whatever it holds: the visual mode is only safe if opening a template and saving it unchanged changes nothing.

const EVERY = [...cases.valid.map((c) => [c.name, c.template]), ...cases.invalid.map((c) => [c.name, c.template]), ['the starter', STARTER]];

// Shapes no fixture holds but a hand-written template can: BBCode and sections that cross, tags never closed, tables
// holding loose text, mixed case, Windows line ends, an option that is not safe, and plain nonsense.
const AWKWARD = [
    ['a section and a tag that cross', '{{#unit}}[B]x{{/unit}} y[/B]'],
    ['a tag that is never closed', '[B]bold to the end'],
    ['a close with nothing open', 'x[/I] y'],
    ['a table holding loose text', '[TABLE]hello[TR][TD]a[/TD][/TR][/TABLE]'],
    ['a cell outside a table', '[TD]a[/TD]'],
    ['a row with text between its cells', '[TABLE][TR]x[TD]a[/TD][/TR][/TABLE]'],
    ['a loop of rows inside a loop of rows', '[TABLE]{{#troopers}}{{#is_sl}}[TR][TD]x[/TD][/TR]{{/is_sl}}{{/troopers}}[/TABLE]'],
    ['mixed case and an option', '[b]x[/B] [Color=red]y[/color] [SIZE=4]z[/SIZE]'],
    ['an unsafe colour', '[COLOR=javascript:alert(1)]x[/COLOR]'],
    ['Windows line ends', '[CENTER]\r\n[B]x[/B]\r\n[/CENTER]\r\n'],
    ['an unknown tag', '[FOO]x[/FOO] [*] [HR]'],
    ['an image with no end', '[IMG]https://7cav.us/a.png'],
    ['an image holding a tag', '[IMG][B]x[/B][/IMG]'],
    ['a list', '[LIST]\n[*]one\n[*]two\n[/LIST]'],
    ['a quote with a name', '[QUOTE=Jay]hi[/QUOTE]'],
    ['brackets and braces as text', '[ ] [/] [[B]] {{ }} {{# }} {{/}}'],
    ['an empty template', ''],
    ['only line ends', '\n\n\n'],
    ['nested cells', '[TABLE][TR][TD][TABLE][TR][TD]x[/TD][/TR][/TABLE][/TD][/TR][/TABLE]'],
    ['an emoji and non-Latin text', '[B]✓ ✗ — 第一 😀[/B]'],
];

describe('the template model', () => {
    it.each(EVERY)('gives back exactly what it read: %s', (_name, template) => {
        expect(serialize(buildDoc(template))).toBe(template);
    });

    it.each(AWKWARD)('gives back exactly what it read, however awkward: %s', (_name, template) => {
        expect(serialize(buildDoc(template))).toBe(template);
    });

    it('cuts the source into tokens that cover every character once, in order', () => {
        for (const [, template] of [...EVERY, ...AWKWARD]) {
            const tokens = tokenize(template);
            expect(tokens.map((token) => token.raw).join('')).toBe(template);
            tokens.forEach((token, index) => expect(token.start).toBe(index === 0 ? 0 : tokens[index - 1].end));
        }
    });

    it('reads a template code as one piece, however it is spaced, and leaves an unrecognised one as text', () => {
        expect(tokenize('{{ unit }}')).toMatchObject([{ kind: 'code', mark: '', name: 'unit' }]);
        expect(tokenize('{{# troopers }}')).toMatchObject([{ kind: 'code', mark: '#', name: 'troopers' }]);
        expect(tokenize('{{week|upper}}')).toMatchObject([{ kind: 'code', name: 'week', filter: 'upper' }]);
        expect(tokenize('{{ UNIT }}')).toMatchObject([{ kind: 'text', raw: '{{ UNIT }}' }]);
    });

    it('keeps a code inside a tag option as part of that tag', () => {
        expect(tokenize('[IMG width="{{rank_icon_width}}" height="auto"]')).toMatchObject([{ kind: 'bb', name: 'img', raw: '[IMG width="{{rank_icon_width}}" height="auto"]' }]);
        expect(tokenize('[URL={{name}}]')).toMatchObject([{ kind: 'bb', name: 'url', option: '{{name}}' }]);
    });

    it('pairs tags and sections into a tree, each with its own open and close as written', () => {
        const [center] = buildDoc('[CENTER][b]{{unit}}[/B][/CENTER]');

        expect(center).toMatchObject({ type: 'tag', name: 'center', open: '[CENTER]', close: '[/CENTER]' });
        expect(center.children[0]).toMatchObject({ type: 'tag', name: 'b', open: '[b]', close: '[/B]' });
        expect(center.children[0].children[0]).toMatchObject({ type: 'value', name: 'unit', raw: '{{unit}}' });
    });

    it('draws a loop of rows inside a table as one row block, and the rows as cells', () => {
        const [table] = buildDoc(cases.valid.find((c) => c.name === 'BBCode with square brackets and a table').template);

        expect(table).toMatchObject({ type: 'tag', name: 'table', structured: true });
        const [header, loop] = table.children;
        expect(header).toMatchObject({ type: 'tag', name: 'tr' });
        expect(header.children[0]).toMatchObject({ type: 'tag', name: 'th' });
        expect(loop).toMatchObject({ type: 'section', name: 'troopers', inverted: false, context: 'table' });
        expect(loop.children[0].children[0].children[0]).toMatchObject({ type: 'value', name: 'n' });
    });

    it('draws the starter as the leader sees it: a centred title, then one table with its loop and its empty-section row', () => {
        const doc = buildDoc(STARTER);
        const table = doc.find((node) => node.type === 'tag' && node.name === 'table');

        expect(doc[0]).toMatchObject({ type: 'tag', name: 'center' });
        expect(table.structured).toBe(true);
        expect(table.children.filter((node) => node.type === 'section').map((node) => [node.name, node.inverted])).toEqual([
            ['troopers', false],
            ['troopers', true],
        ]);
        expect(doc.some((node) => node.type === 'raw')).toBe(false);
    });

    it('hides the line ends between rows and cells, which the forum does not show either', () => {
        const [table] = buildDoc('[TABLE]\n[TR][TD]a[/TD][/TR]\n[/TABLE]');

        expect(table.children[0]).toMatchObject({ type: 'text', value: '\n', hidden: true });
        expect(table.children[2]).toMatchObject({ type: 'text', value: '\n', hidden: true });
    });

    it('keeps a condition on cells inside a row as a group of cells', () => {
        const [table] = buildDoc('[TABLE][TR][TD]a[/TD]{{#fireteams_shown}}[TD]b[/TD]{{/fireteams_shown}}[/TR][/TABLE]');

        expect(table.children[0].children[1]).toMatchObject({ type: 'section', name: 'fireteams_shown', context: 'row' });
    });

    it('turns an image whose address is text and codes into one piece', () => {
        expect(buildDoc('[IMG]{{rc_image}}[/IMG]')).toMatchObject([{ type: 'image', raw: '[IMG]{{rc_image}}[/IMG]' }]);
    });

    it('shows what does not nest cleanly as pieces of raw code rather than guess what was meant', () => {
        const doc = buildDoc('{{#unit}}[B]x{{/unit}} y[/B]');

        expect(doc[0]).toMatchObject({ type: 'section', name: 'unit' });
        expect(doc[0].children[0]).toMatchObject({ type: 'raw', raw: '[B]' });
        expect(doc.at(-1)).toMatchObject({ type: 'raw', raw: '[/B]' });
    });

    it('does not draw a table that holds more than rows as a table', () => {
        const doc = buildDoc('[TABLE]hello[TR][TD]a[/TD][/TR][/TABLE]');

        expect(doc[0]).toMatchObject({ type: 'raw', raw: '[TABLE]' });
        expect(doc.some((node) => node.type === 'tag' && node.name === 'table')).toBe(false);
    });

    it('shows a tag with an option the forum would refuse as raw code', () => {
        expect(buildDoc('[COLOR=javascript:alert(1)]x[/COLOR]')[0]).toMatchObject({ type: 'raw' });
        expect(buildDoc('[COLOR=red]x[/COLOR]')[0]).toMatchObject({ type: 'tag', name: 'color', option: 'red' });
    });

    it('keeps where each piece is in the source, so an edit can be made there', () => {
        const [, bold] = buildDoc('ab[B]cd[/B]');

        expect(bold).toMatchObject({ start: 2, openEnd: 5, closeStart: 7, end: 11 });
    });

    it('is not slow on a long template full of tags', () => {
        const big = '[B]{{unit}}[/B]{{#troopers}}[TR][TD]{{name}}[/TD][/TR]{{/troopers}}'.repeat(400);
        const started = performance.now();

        expect(serialize(buildDoc(big))).toBe(big);
        expect(performance.now() - started).toBeLessThan(1000);
    });
});

describe('what may go at the cursor', () => {
    it('knows the cursor is inside the trooper loop, and only there', () => {
        const source = 'a {{#troopers}}b {{name}} c{{/troopers}} d';

        expect(contextAt(source, 0)).toEqual({ inRow: false });
        expect(contextAt(source, source.indexOf('b '))).toEqual({ inRow: true });
        expect(contextAt(source, source.indexOf(' c'))).toEqual({ inRow: true });
        expect(contextAt(source, source.indexOf(' d'))).toEqual({ inRow: false });
    });

    it('reads a cursor in the middle of a code as just before it', () => {
        const source = '{{#troopers}}x{{/troopers}}';

        expect(contextAt(source, 3)).toEqual({ inRow: false });
        expect(contextAt(source, source.indexOf('{{/') + 3)).toEqual({ inRow: true });
    });

    it('still answers for a template that is not valid yet', () => {
        expect(contextAt('{{#troopers}}{{nme}} x', 20)).toEqual({ inRow: true });
        expect(contextAt('x{{/troopers}} y', 15)).toEqual({ inRow: false });
    });
});
