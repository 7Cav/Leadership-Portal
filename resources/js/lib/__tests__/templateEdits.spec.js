import { describe, expect, it } from 'vitest';
import { addColumn, insertCode, insertImage, insertLink, insertTable, removeColumn, setOption, toggleTag } from '../templateEdits';
import { parse } from '../bbcode';
import { STARTER } from './templateStarter';

// The toolbar and the code picker (#704). Each edit works on the template text and a selection in it, so the same edit
// serves the visual mode and the Source tab. An edit that would leave tags crossing each other is refused (null), so a
// toolbar button can never break a template that was fine.

const at = (source, marker) => source.indexOf(marker);

describe('formatting', () => {
    it('wraps the selection in the tag, keeping the selection on the same text', () => {
        expect(toggleTag('a bold b', 2, 6, 'b')).toEqual({ source: 'a [B]bold[/B] b', start: 5, end: 9 });
    });

    it('with nothing selected, puts the tags in with the cursor between them', () => {
        expect(toggleTag('ab', 1, 1, 'i')).toEqual({ source: 'a[I][/I]b', start: 4, end: 4 });
    });

    it('takes the tag off again when the selection is already inside it', () => {
        expect(toggleTag('a [B]bold[/B] b', 6, 8, 'b')).toEqual({ source: 'a bold b', start: 3, end: 5 });
        expect(toggleTag('a [b]bold[/b] b', 5, 5, 'b')).toEqual({ source: 'a bold b', start: 2, end: 2 });
    });

    it('centres a line the same way', () => {
        expect(toggleTag('title', 0, 5, 'center')).toEqual({ source: '[CENTER]title[/CENTER]', start: 8, end: 13 });
    });

    it('refuses a wrap that would cross other tags or codes', () => {
        expect(toggleTag('[I]ab[/I]cd', 4, 10, 'b')).toBeNull();
        expect(toggleTag('{{#troopers}}x{{/troopers}} y', 0, 15, 'b')).toBeNull();
        expect(toggleTag('[TABLE][TR][TD]a[/TD][TD]b[/TD][/TR][/TABLE]', 15, 25, 'b')).toBeNull();
    });

    it('sets a colour or a size on the selection, or changes the one around it', () => {
        expect(setOption('a red b', 2, 5, 'color', 'red')).toEqual({ source: 'a [COLOR=red]red[/COLOR] b', start: 13, end: 16 });
        expect(setOption('a [COLOR=red]red[/COLOR] b', 14, 14, 'color', '#00ff00')).toEqual({ source: 'a [COLOR=#00ff00]red[/COLOR] b', start: 18, end: 18 });
        expect(setOption('x', 0, 1, 'size', '5')).toEqual({ source: '[SIZE=5]x[/SIZE]', start: 8, end: 9 });
    });

    it('refuses a colour or size the forum would not take', () => {
        expect(setOption('x', 0, 1, 'color', 'javascript:alert(1)')).toBeNull();
        expect(setOption('x', 0, 1, 'size', '99')).toBeNull();
    });
});

describe('links and images', () => {
    it('links the selection, or writes the address as the link text when nothing is selected', () => {
        expect(insertLink('see here', 4, 8, 'https://7cav.us/x')).toEqual({ source: 'see [URL=https://7cav.us/x]here[/URL]', start: 37, end: 37 });
        expect(insertLink('see ', 4, 4, 'https://7cav.us')).toEqual({ source: 'see [URL=https://7cav.us]https://7cav.us[/URL]', start: 46, end: 46 });
    });

    it('refuses an address that is not a full http or https one', () => {
        expect(insertLink('x', 0, 1, 'javascript:alert(1)')).toBeNull();
        expect(insertLink('x', 0, 1, '7cav.us')).toBeNull();
    });

    it('puts an image in at the cursor, from an address or a live result image code', () => {
        expect(insertImage('a b', 2, 2, 'https://7cav.us/a.png')).toEqual({ source: 'a [IMG]https://7cav.us/a.png[/IMG]b', start: 34, end: 34 });
        expect(insertImage('', 0, 0, '{{rc_image}}')).toEqual({ source: '[IMG]{{rc_image}}[/IMG]', start: 23, end: 23 });
        expect(insertImage('', 0, 0, 'ftp://x')).toBeNull();
    });
});

describe('the code picker', () => {
    const value = { open: '{{name}}', close: '' };
    const section = { open: '{{#rc_yes}}', close: '{{/rc_yes}}' };

    it('puts a value in at the cursor, in place of a selection', () => {
        expect(insertCode('ab', 1, 1, value)).toEqual({ source: 'a{{name}}b', start: 9, end: 9 });
        expect(insertCode('a xx b', 2, 4, value)).toEqual({ source: 'a {{name}} b', start: 10, end: 10 });
    });

    it('puts a section around the selection, or opens one with the cursor inside', () => {
        expect(insertCode('a ok b', 2, 4, section)).toEqual({ source: 'a {{#rc_yes}}ok{{/rc_yes}} b', start: 26, end: 26 });
        expect(insertCode('ab', 1, 1, section)).toEqual({ source: 'a{{#rc_yes}}{{/rc_yes}}b', start: 12, end: 12 });
    });
});

describe('the trooper table', () => {
    it('puts a whole trooper table in, which follows the grammar', () => {
        const result = insertTable('Roll call\n', 10, 10);

        expect(result.source.startsWith('Roll call\n[TABLE]')).toBe(true);
        expect(result.source).toContain('{{#troopers}}[TR]');
        expect(parse(result.source).ok).toBe(true);
    });

    it('will not put a table inside the trooper loop', () => {
        expect(insertTable('{{#troopers}}x{{/troopers}}', 14, 14)).toBeNull();
    });

    it('adds a column after the cursor\'s, in the header and in the trooper row alike', () => {
        const source = '[TABLE][TR][TH]#[/TH][TH]Name[/TH][/TR]{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{name}}[/TD][/TR]{{/troopers}}[/TABLE]';
        const result = addColumn(source, at(source, 'Name'));

        expect(result.source).toBe('[TABLE][TR][TH]#[/TH][TH]Name[/TH][TH]Column[/TH][/TR]{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{name}}[/TD][TD][/TD][/TR]{{/troopers}}[/TABLE]');
        // The cursor goes into the new header cell, ready to name it.
        expect(result.source.slice(result.start, result.end)).toBe('Column');
    });

    it('adds and removes a column in the starter, its fireteam column and empty-section row kept whole', () => {
        const added = addColumn(STARTER, at(STARTER, 'Signed RC'));
        expect(parse(added.source).ok).toBe(true);
        expect(added.source).toContain('[TH]Signed RC[/TH][TH]Column[/TH][TH]Attended SP[/TH]');
        expect(added.source).toContain('{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD][/TD][TD]{{#sp_present}}');
        expect(added.source).toContain('[TR][TD]No troopers are placed in this section.[/TD][/TR]');

        const removed = removeColumn(STARTER, at(STARTER, 'Role'));
        expect(parse(removed.source).ok).toBe(true);
        expect(removed.source).not.toContain('Role');
        expect(removed.source).not.toContain('{{role}}');
        expect(removed.source).toContain('[TH]Name[/TH][TH]Signed RC[/TH]');
    });

    it('removes the column under the cursor from every row of the same width', () => {
        const source = '[TABLE][TR][TH]#[/TH][TH]Name[/TH][/TR]{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{name}}[/TD][/TR]{{/troopers}}[/TABLE]';

        expect(removeColumn(source, at(source, '{{n}}')).source).toBe('[TABLE][TR][TH]Name[/TH][/TR]{{#troopers}}[TR][TD]{{name}}[/TD][/TR]{{/troopers}}[/TABLE]');
    });

    it('does nothing outside a table, and will not remove a row\'s last column', () => {
        expect(addColumn('plain', 2)).toBeNull();
        expect(removeColumn('[TABLE][TR][TD]a[/TD][/TR][/TABLE]', 16)).toBeNull();
    });
});
