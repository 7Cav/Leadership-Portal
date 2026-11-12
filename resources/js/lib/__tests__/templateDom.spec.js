import { describe, expect, it } from 'vitest';
import cases from '../../../../tests/Fixtures/bbcode-templates.json';
import { buildDoc } from '../templateDoc';
import { crossesStructure, drawDoc, hasForeignMarkup, hasLoneEdge, pointAt, readDom, toVisibleEdges } from '../templateDom';
import { STARTER } from './templateStarter';

// The visual mode is a page element the leader types into, drawn from the template and read back from what is on the
// page (#704). Visual → Source → Visual may only be safe if drawing a template and reading it back, untouched, gives the
// same template byte for byte, for every shape a template can take.

function surface(template) {
    const root = document.createElement('div');
    root.setAttribute('contenteditable', 'true');
    root.append(drawDoc(buildDoc(template), document));
    document.body.replaceChildren(root);

    return root;
}

const EVERY = [
    ...cases.valid.map((c) => [c.name, c.template]),
    ...cases.invalid.map((c) => [c.name, c.template]),
    ['the starter', STARTER],
    ['a section and a tag that cross', '{{#unit}}[B]x{{/unit}} y[/B]'],
    ['a table holding loose text', '[TABLE]hello[TR][TD]a[/TD][/TR][/TABLE]'],
    ['an unsafe colour', '[COLOR=javascript:alert(1)]x[/COLOR]'],
    ['Windows line ends', '[CENTER]\r\n[B]x[/B]\r\n[/CENTER]\r\n'],
    ['a list', '[LIST]\n[*]one\n[*]two\n[/LIST]'],
    ['a link and a size', '[URL=https://7cav.us]home[/URL] [SIZE=5]big[/SIZE] [COLOR=#ff0000]red[/COLOR]'],
    ['an empty template', ''],
    ['spaces and tabs', '  a\t b  \n  '],
];

describe('the visual surface', () => {
    it.each(EVERY)('reads back exactly the template it was drawn from: %s', (_name, template) => {
        expect(readDom(surface(template)).source).toBe(template);
    });

    it('draws formatting as formatting, not as code', () => {
        const root = surface('[B]bold[/B] [I]it[/I] [U]u[/U] [COLOR=red]c[/COLOR] [CENTER]mid[/CENTER]');

        expect(root.querySelector('strong').textContent).toBe('bold');
        expect(root.querySelector('em').textContent).toBe('it');
        expect(root.querySelector('u').textContent).toBe('u');
        expect(root.querySelector('span[data-open="[COLOR=red]"]').style.color).toBe('red');
        expect(root.querySelector('div[data-open="[CENTER]"]').style.textAlign).toBe('center');
        expect(root.textContent).not.toContain('[B]');
    });

    it('draws a code as a chip that cannot be edited, named for what it prints, with an accessible name', () => {
        const chip = surface('{{#troopers}}{{name}}{{/troopers}}').querySelector('[data-raw="{{name}}"]');

        expect(chip.getAttribute('contenteditable')).toBe('false');
        expect(chip.textContent).toBe('Trooper name');
        expect(chip.getAttribute('role')).toBe('img');
        expect(chip.getAttribute('aria-label')).toBe('Trooper name, template code {{name}}');
    });

    it('marks a code the language does not have, and names it as written', () => {
        const root = surface('{{#troopers}}{{nme}} {{name}}{{/troopers}}');

        expect(root.querySelector('[data-raw="{{nme}}"]').classList.contains('tpl-unknown')).toBe(true);
        expect(root.querySelector('[data-raw="{{nme}}"]').textContent).toBe('nme');
        expect(root.querySelector('[data-raw="{{name}}"]').classList.contains('tpl-unknown')).toBe(false);
    });

    it('names a filtered code for what it prints', () => {
        expect(surface('{{week|upper}}').querySelector('[data-raw]').textContent).toBe('Week in capitals');
    });

    it('draws the trooper loop in a table as one block of rows, labelled as repeating', () => {
        const root = surface(STARTER);
        const loop = root.querySelector('tbody[data-section="troopers"]');

        expect(loop).not.toBeNull();
        expect(loop.querySelector('[data-edge="open"]').textContent).toContain('Repeats for each trooper');
        expect(loop.querySelectorAll('tr[data-open="[TR]"]')).toHaveLength(1);
        expect(root.querySelector('tbody[data-section="troopers"][data-inverted]').querySelector('[data-edge="open"]').textContent).toContain('When the section has no troopers');
    });

    it('marks cells shown only under a condition with that condition', () => {
        const root = surface(STARTER);

        expect(root.querySelector('th[data-condition]').getAttribute('data-condition')).toBe('Only if Fireteams shown');
    });

    it('draws a section inside text as a chip at each end around what it shows', () => {
        const root = surface('a {{#rank_icon}}x{{/rank_icon}} b');
        const [open, close] = root.querySelectorAll('[data-edge]');

        expect(open.textContent).toBe('If Rank insignia');
        expect(close.textContent).toBe('End');
        expect(close.getAttribute('aria-label')).toBe('End of If Rank insignia, template code {{/rank_icon}}');
    });

    it('draws what does not nest cleanly as code chips', () => {
        const root = surface('{{#unit}}[B]x{{/unit}} y[/B]');

        expect([...root.querySelectorAll('.tpl-raw')].map((chip) => chip.textContent)).toEqual(['[B]', '[/B]']);
    });
});

describe('reading back what the leader changed', () => {
    it('reads typed text into the template, codes and tags around it untouched', () => {
        const root = surface('[B]Roll call[/B] {{unit}}');
        root.querySelector('strong').firstChild.data = 'Roll call accountability';

        expect(readDom(root).source).toBe('[B]Roll call accountability[/B] {{unit}}');
    });

    it('drops a deleted chip whole, so a code is never half there', () => {
        const root = surface('a {{unit}} b');
        root.querySelector('[data-raw]').remove();

        expect(readDom(root).source).toBe('a  b');
    });

    it('drops both ends of a section when either end is deleted, and keeps what it showed', () => {
        const root = surface('a {{#rank_icon}}x{{/rank_icon}} b');
        root.querySelector('[data-edge="open"]').remove();

        expect(readDom(root).source).toBe('a x b');
    });

    it('keeps the text of formatting the browser added and drops its markup', () => {
        const root = surface('ab');
        const bold = document.createElement('b');
        bold.textContent = 'cd';
        root.append(bold);

        expect(readDom(root).source).toBe('abcd');
    });

    it('reads a new line the browser started as a line end', () => {
        const root = surface('ab');
        const line = document.createElement('div');
        line.textContent = 'ef';
        root.append(line);

        expect(readDom(root).source).toBe('ab\nef');
    });

    it('ignores the line break a browser leaves as a placeholder where a line was emptied, as Chromium does', () => {
        const root = surface('[CENTER][/CENTER]');
        root.prepend(document.createElement('br'));
        root.append(document.createElement('br'));

        expect(readDom(root).source).toBe('[CENTER][/CENTER]');
    });

    it('knows a selection that reaches over a condition\'s end or from one cell into another, which typing would break', () => {
        const root = surface('a {{#rank_icon}}x{{/rank_icon}} b [TABLE][TR][TD]one[/TD][TD]two[/TD][/TR][/TABLE] [B]bold[/B] c');
        const range = (startNode, startOffset, endNode, endOffset) => {
            const made = document.createRange();
            made.setStart(startNode, startOffset);
            made.setEnd(endNode, endOffset);

            return made;
        };
        const [one, two] = [...root.querySelectorAll('td')].map((cell) => cell.firstChild);
        const bold = root.querySelector('strong').firstChild;

        expect(crossesStructure(range(root.firstChild, 0, root.querySelector('[data-pair]').childNodes[1], 1))).toBe(true);
        expect(crossesStructure(range(one, 1, two, 1))).toBe(true);
        expect(crossesStructure(range(one, 0, one, 2))).toBe(false);
        expect(crossesStructure(range(bold, 1, root.lastChild, 2))).toBe(false);
    });

    it('reads selecting everything in a template that ends in a table as taking all of it, as a browser selects it', () => {
        const root = surface(STARTER);
        const lastCell = [...root.querySelectorAll('td')].at(-1).lastChild;
        const all = document.createRange();
        all.setStart(root.firstChild.firstChild ?? root.firstChild, 0);
        all.setEnd(lastCell, lastCell.data.length);

        expect(crossesStructure(all, root)).toBe(false);
        expect(readDom(root, [toVisibleEdges(all, root)].flatMap((range) => [{ node: range.startContainer, offset: range.startOffset }, { node: range.endContainer, offset: range.endOffset }])).offsets).toEqual([0, STARTER.length]);

        const caret = document.createRange();
        caret.setStart(lastCell, lastCell.data.length);
        expect(toVisibleEdges(caret, root).collapsed).toBe(true);
    });

    it('lets a selection take a whole condition, a whole loop or everything', () => {
        const root = surface(`a {{#rank_icon}}x{{/rank_icon}} b\n${STARTER}\nend`);
        const pair = root.querySelector('[data-pair]');
        const whole = document.createRange();
        whole.selectNodeContents(root);
        const condition = document.createRange();
        condition.setStartBefore(pair);
        condition.setEndAfter(pair);

        expect(crossesStructure(whole)).toBe(false);
        expect(crossesStructure(condition)).toBe(false);
    });

    it('knows when the browser has added formatting of its own, which the template does not have', () => {
        const root = surface('[B]a[/B] {{unit}} [TABLE][TR][TD]x[/TD][/TR][/TABLE] {{#troopers}}y{{/troopers}}');
        expect(hasForeignMarkup(root)).toBe(false);
        const bold = document.createElement('b');
        bold.textContent = 'typed';
        root.append(bold);

        expect(hasForeignMarkup(root)).toBe(true);
    });

    it('knows when a section has lost one of its ends, so the surface can be drawn again without the other', () => {
        const root = surface('a {{#rank_icon}}x{{/rank_icon}} b');
        expect(hasLoneEdge(root)).toBe(false);
        root.querySelector('[data-edge="close"]').remove();

        expect(hasLoneEdge(root)).toBe(true);
    });
});

describe('the cursor', () => {
    it('gives the place in the template of a point on the page', () => {
        const root = surface('[B]ab[/B]{{unit}}cd');
        const bold = root.querySelector('strong').firstChild;
        const tail = root.lastChild;

        expect(readDom(root, [{ node: bold, offset: 1 }]).offsets).toEqual([4]);
        expect(readDom(root, [{ node: tail, offset: 2 }]).offsets).toEqual([19]);
        expect(readDom(root, [{ node: root, offset: 1 }]).offsets).toEqual([9]);
        // A point inside a chip is just before it.
        expect(readDom(root, [{ node: root.querySelector('[data-raw]').firstChild, offset: 3 }]).offsets).toEqual([9]);
    });

    it('says it cannot place a point it never reached, rather than guess the end', () => {
        const root = surface('a {{#rank_icon}}x{{/rank_icon}}');
        const open = root.querySelector('[data-edge="open"]');
        root.querySelector('[data-edge="close"]').remove();

        expect(readDom(root, [{ node: open, offset: 0 }]).offsets).toEqual([null]);
    });

    it('never puts the cursor between the rows or cells of a table, where typing would leave the table', () => {
        const source = '[TABLE][TR][TD]a[/TD][TD][/TD][/TR][/TABLE]';
        const root = surface(source);
        const point = pointAt(root, source.indexOf('[TD][/TD]'));

        expect(['TABLE', 'TBODY', 'TR']).not.toContain(point.node.nodeName);
        expect(readDom(root, [point]).offsets[0]).not.toBeNull();
    });

    it('finds the point on the page for a place in the template, inside the text it falls in', () => {
        const root = surface('[B]ab[/B]{{unit}}cd');

        expect(pointAt(root, 4)).toEqual({ node: root.querySelector('strong').firstChild, offset: 1 });
        expect(pointAt(root, 18)).toEqual({ node: root.lastChild, offset: 1 });
    });

    it('puts a place inside a tag or a code at the nearest edge of it', () => {
        const root = surface('[B]ab[/B]{{unit}}cd');

        expect(pointAt(root, 1)).toEqual({ node: root, offset: 0 });
        expect(pointAt(root, 12)).toEqual({ node: root, offset: 1 });
        expect(pointAt(root, 15)).toEqual({ node: root.lastChild, offset: 0 });
    });

    it('gives back every place it is given, in the same order', () => {
        const source = '[B]ab[/B]{{unit}}cd x {{#troopers}}{{name}}{{/troopers}}';
        const root = surface(source);
        const places = [0, 3, 4, 5, 9, 17, 18, 19, 22, 35, 43, source.length];

        for (const place of places) {
            const point = pointAt(root, place);
            expect(readDom(root, [point]).offsets[0], `place ${place}`).toBe(place);
        }
    });
});
