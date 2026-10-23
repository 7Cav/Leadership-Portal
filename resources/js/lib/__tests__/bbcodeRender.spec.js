import { describe, expect, it } from 'vitest';
import { imageSize, parseBbcode, safeColor, safeImage, safeImageSide, safeLink, safeSize } from '../bbcodeRender';
import { inert, render, sampleContext } from '../bbcode';

// The preview tree, written short: text as a string, a line break as 'br', an element as [kind, children] (or
// [kind, {attrs/style}, children] when it has any).
function short(nodes) {
    return nodes.map((node) => {
        if (node.type === 'text') return node.text;
        if (node.type === 'br') return 'br';
        if (node.type === 'quote-by') return { by: node.text };
        const extra = {};
        if (Object.keys(node.attrs).length) extra.attrs = node.attrs;
        if (Object.keys(node.style).length) extra.style = node.style;

        return Object.keys(extra).length ? [node.kind, extra, short(node.children)] : [node.kind, short(node.children)];
    });
}

const read = (source) => short(parseBbcode(source));

// Every text and attribute value in a tree, to check none of them carries what an attack wrote as live markup.
function attrsOf(nodes) {
    return nodes.flatMap((node) => (node.type === 'element' ? [node.attrs, ...attrsOf(node.children)] : []));
}
function elementsOf(nodes) {
    return nodes.flatMap((node) => (node.type === 'element' ? [node, ...elementsOf(node.children)] : []));
}

describe('reading BBCode for the preview', () => {
    it('keeps plain text and turns each line end into a line break', () => {
        expect(read('Roll call\nWeek two')).toEqual(['Roll call', 'br', 'Week two']);
        expect(read('a\r\n\r\nb')).toEqual(['a', 'br', 'br', 'b']);
        expect(read('')).toEqual([]);
    });

    it.each([
        ['[B]x[/B]', 'b'],
        ['[I]x[/I]', 'i'],
        ['[U]x[/U]', 'u'],
        ['[S]x[/S]', 's'],
    ])('reads %s', (source, kind) => {
        expect(read(source)).toEqual([[kind, ['x']]]);
    });

    it('reads tag names in any case', () => {
        expect(read('[b]x[/B] [Center]y[/cEnTeR]')).toEqual([['b', ['x']], ' ', ['center', { style: { textAlign: 'center' } }, ['y']]]);
    });

    it.each([
        ['CENTER', 'center'],
        ['LEFT', 'left'],
        ['RIGHT', 'right'],
    ])('aligns [%s] blocks, without the layout line breaks around them', (tag, align) => {
        expect(read(`before\n[${tag}]\nx\n[/${tag}]\nafter`)).toEqual(['before', 'br', [tag.toLowerCase(), { style: { textAlign: align } }, ['x']], 'after']);
    });

    it('reads [SIZE] as the forum sizes it, and in pixels within bounds', () => {
        expect(read('[SIZE=1]a[/SIZE][SIZE=4]b[/SIZE][SIZE=7]c[/SIZE]')).toEqual([
            ['size', { style: { fontSize: '9px' } }, ['a']],
            ['size', { style: { fontSize: '15px' } }, ['b']],
            ['size', { style: { fontSize: '26px' } }, ['c']],
        ]);
        expect(read('[SIZE=20px]a[/SIZE]')).toEqual([['size', { style: { fontSize: '20px' } }, ['a']]]);
    });

    it.each(['0', '8', '-1', '3em', '100px', '7px', '1;color:red', ''])('shows [SIZE=%s] as written', (size) => {
        expect(read(`[SIZE=${size}]a[/SIZE]`)).toEqual([`[SIZE=${size}]`, 'a', '[/SIZE]']);
    });

    it('reads [COLOR] with a name, a hex value or rgb()', () => {
        expect(read('[COLOR=Red]a[/COLOR][COLOR=#ff8800]b[/COLOR][COLOR=#0f0]c[/COLOR][COLOR=rgb(10, 20, 255)]d[/COLOR]')).toEqual([
            ['color', { style: { color: 'red' } }, ['a']],
            ['color', { style: { color: '#ff8800' } }, ['b']],
            ['color', { style: { color: '#0f0' } }, ['c']],
            ['color', { style: { color: 'rgb(10, 20, 255)' } }, ['d']],
        ]);
    });

    it.each(['red;background:url(x)', 'rgb(300,0,0)', '#12345', 'url(javascript:x)', 'expression(alert(1))', 'red background', '"red"x'])(
        'shows [COLOR=%s] as written',
        (color) => {
            const tree = parseBbcode(`[COLOR=${color}]a[/COLOR]`);

            expect(elementsOf(tree)).toEqual([]);
        },
    );

    it('links [URL] and [URL=...] to http and https addresses only, opening a new tab with no referrer', () => {
        expect(read('[URL]https://7cav.us/threads/1/[/URL]')).toEqual([
            ['url', { attrs: { href: 'https://7cav.us/threads/1/', rel: 'noopener noreferrer nofollow', target: '_blank' } }, ['https://7cav.us/threads/1/']],
        ]);
        expect(read('[URL="http://example.com/a?b=1"]the [B]thread[/B][/URL]')).toEqual([
            ['url', { attrs: { href: 'http://example.com/a?b=1', rel: 'noopener noreferrer nofollow', target: '_blank' } }, ['the ', ['b', ['thread']]]],
        ]);
    });

    it('shows [IMG] for an https address, loaded lazily with no referrer', () => {
        expect(read('[IMG]https://7cav.us/data/roster_ranks/0/18.jpg?1741376797[/IMG]')).toEqual([
            ['img', { attrs: { src: 'https://7cav.us/data/roster_ranks/0/18.jpg?1741376797', alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' } }, []],
        ]);
    });

    it('reads a table of rows and header and data cells, leaving out the line breaks between them', () => {
        expect(read('[TABLE]\n[TR][TH]#[/TH][TH]Name[/TH][/TR]\n[TR][TD]1[/TD][TD]Jay.JH[/TD][/TR]\n[/TABLE]\nafter')).toEqual([
            [
                'table',
                [
                    ['tr', [['th', ['#']], ['th', ['Name']]]],
                    ['tr', [['td', ['1']], ['td', ['Jay.JH']]]],
                ],
            ],
            'after',
        ]);
    });

    it('closes an open cell or row when the next one starts', () => {
        expect(read('[TABLE][TR][TD]a[TD]b[TR][TD]c[/TABLE]')).toEqual([['table', [['tr', [['td', ['a']], ['td', ['b']]]], ['tr', [['td', ['c']]]]]]]);
    });

    it('shows rows and cells written outside a table as text', () => {
        expect(read('[TR]a[/TR]')).toEqual(['[TR]', 'a', '[/TR]']);
        expect(read('[TD]a[/TD]')).toEqual(['[TD]', 'a', '[/TD]']);
        expect(read('[TABLE][TD]a[/TD][/TABLE]')).toEqual([['table', []]]);
    });

    it('reads [LIST] with [*] items, and a numbered or lettered [LIST=1]', () => {
        expect(read('[LIST]\n[*]one\n[*]two\n[/LIST]')).toEqual([['list', [['li', ['one']], ['li', ['two']]]]]);
        expect(read('[LIST=1][*]a[/LIST]')).toEqual([['list', { attrs: { type: '1' } }, [['li', ['a']]]]]);
        expect(read('[LIST=a][*]a[/LIST]')).toEqual([['list', { attrs: { type: 'a' } }, [['li', ['a']]]]]);
        expect(read('[LIST=disc][*]a[/LIST]')).toEqual(['[LIST=disc]', '[*]', 'a', '[/LIST]']);
        expect(read('[*]not in a list')).toEqual(['[*]', 'not in a list']);
    });

    it('reads [QUOTE] and [QUOTE=name], naming who said it', () => {
        expect(read('[QUOTE]hi[/QUOTE]')).toEqual([['quote', ['hi']]]);
        expect(read('[QUOTE="Jay.JH, post: 12, member: 3"]hi[/QUOTE]')).toEqual([['quote', [{ by: 'Jay.JH said:' }, 'hi']]]);
    });

    it('draws a rule for [HR], with or without a closing tag', () => {
        expect(read('a\n[HR]\nb')).toEqual(['a', 'br', ['hr', []], 'b']);
        expect(read('a[HR][/HR]b')).toEqual(['a', ['hr', []], 'b']);
    });

    it('nests tags', () => {
        expect(read('[CENTER][B][COLOR=gold]1/E/2-7[/COLOR] ROLL CALL[/B][/CENTER]')).toEqual([
            ['center', { style: { textAlign: 'center' } }, [['b', [['color', { style: { color: 'gold' } }, ['1/E/2-7']], ' ROLL CALL']]]],
        ]);
    });
});

describe('[IMG] with a width and a height, as the forum takes them', () => {
    const IMG = 'https://7cav.us/data/roster_ranks/0/18.jpg';
    const ATTRS = { src: IMG, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' };

    it.each([
        ['[IMG width="11px" height="auto"]', { width: '11px', height: 'auto' }],
        ['[IMG width="322px" height="1px"]', { width: '322px', height: '1px' }],
        ['[IMG height="auto" width="11px"]', { width: '11px', height: 'auto' }],
        ['[IMG width="auto" height="40px"]', { width: 'auto', height: '40px' }],
        ['[IMG width="11px"]', { width: '11px' }],
        ['[IMG height="1000px"]', { height: '1000px' }],
        ["[IMG width='11px' height='auto']", { width: '11px', height: 'auto' }],
        ['[IMG width=11px height=auto]', { width: '11px', height: 'auto' }],
        ['[img WIDTH="11PX" Height="AUTO"]', { width: '11px', height: 'auto' }],
        ['[IMG  width="011px"   height="auto" ]', { width: '11px', height: 'auto' }],
    ])('reads %s', (open, style) => {
        expect(read(`${open}${IMG}[/IMG]`)).toEqual([['img', { attrs: ATTRS, style }, []]]);
    });

    it.each([
        '[IMG width="50%"]',
        '[IMG width="1px;background:url(x)"]',
        '[IMG width="9999px"]',
        '[IMG width="1001px"]',
        '[IMG width="0px"]',
        '[IMG height="-1px"]',
        '[IMG width="11"]',
        '[IMG width="1.5px"]',
        '[IMG width="11 px"]',
        '[IMG width=" 11px"]',
        '[IMG width="11px" width="12px"]',
        '[IMG width="11px" alt="x"]',
        '[IMG style="position:fixed"]',
        '[IMG onerror="alert(1)"]',
        '[IMG width="11px" onerror=alert(1)]',
        '[IMG width="11px"height="auto"]',
        '[IMG width = "11px"]',
        '[IMG width]',
        '[IMG ]',
    ])('shows %s as written, with no image', (open) => {
        const source = `${open}${IMG}[/IMG]`;
        const tree = parseBbcode(source);

        expect(elementsOf(tree)).toEqual([]);
        expect(tree.map((node) => node.text).join('')).toBe(source);
    });

    it('still takes only an https address', () => {
        const source = '[IMG width="11px"]javascript:alert(1)[/IMG]';

        expect(elementsOf(parseBbcode(source))).toEqual([]);
    });

    it('accepts a side of 1 to 1000 pixels or auto, and nothing else', () => {
        expect(['1px', '1000px', 'auto', 'AUTO', '18PX'].map(safeImageSide)).toEqual(['1px', '1000px', 'auto', 'auto', '18px']);
        for (const bad of ['0px', '1001px', '-1px', '50%', '1em', '11', '', 'auto;', 'calc(1px)', '1px;background:url(x)']) expect(safeImageSide(bad), bad).toBeNull();
        expect(imageSize(' width="5px" height="auto"')).toEqual({ width: '5px', height: 'auto' });
        expect(imageSize(' height=auto')).toEqual({ height: 'auto' });
        expect(imageSize(' ')).toBeNull();
        expect(imageSize(' width="5px" style="x"')).toBeNull();
    });
});

describe('BBCode the preview cannot use', () => {
    it('shows an unknown tag as the text that was written', () => {
        expect(read('[FOO]x[/FOO] [SPOILER=a]b[/SPOILER]')).toEqual(['[FOO]', 'x', '[/FOO]', ' ', '[SPOILER=a]', 'b', '[/SPOILER]']);
    });

    it('shows a tag that is never closed as written, with what follows it', () => {
        expect(read('[B]bold to the end')).toEqual(['[B]', 'bold to the end']);
        expect(read('[CENTER][B]x[/B]')).toEqual(['[CENTER]', ['b', ['x']]]);
        expect(read('[QUOTE=Jay]x')).toEqual(['[QUOTE=Jay]', 'x']);
    });

    it('shows a closing tag with nothing open as written', () => {
        expect(read('x[/B]y')).toEqual(['x', '[/B]', 'y']);
    });

    it('closes tags left open inside one that closes, and shows the stray closing tag as written', () => {
        expect(read('[B][I]x[/B]y[/I]')).toEqual([['b', [['i', ['x']]]], 'y', '[/I]']);
    });

    it('shows [IMG] or [URL] with no closing tag as written', () => {
        expect(read('[IMG]https://7cav.us/a.png')).toEqual(['[IMG]', 'https://7cav.us/a.png']);
        expect(read('[URL]https://7cav.us')).toEqual(['[URL]', 'https://7cav.us']);
    });

    it('leaves text alone that only looks like a tag', () => {
        expect(read('[ B ] [b x] [] [/] [1]')).toEqual(['[ B ] [b x] [] [/] [1]']);
    });

    it('stops reading tags past a sane depth instead of recursing without end', () => {
        const tree = parseBbcode(`${'[B]'.repeat(200)}x${'[/B]'.repeat(200)}`);
        let depth = 0;
        for (let nodes = tree; nodes.some((node) => node.type === 'element'); depth += 1) {
            nodes = nodes.find((node) => node.type === 'element').children;
        }

        expect(depth).toBeLessThanOrEqual(41);
    });

    it('cannot be opened by a value the rollcall printed, which is made inert', () => {
        expect(read(`[TD]${inert('[B]Jay[/B]')}`)).toEqual(['[TD]', inert('[B]Jay[/B]')]);
    });
});

describe('injection attempts', () => {
    it.each([
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '"><svg onload=alert(1)>',
        '[B]<b onclick="x()">bold</b>[/B]',
    ])('keeps HTML as text: %s', (source) => {
        const tree = parseBbcode(source);
        const texts = JSON.stringify(tree);

        expect(elementsOf(tree).every((node) => ['b'].includes(node.kind))).toBe(true);
        expect(texts).toContain('<');
    });

    it.each([
        '[URL=javascript:alert(1)]x[/URL]',
        '[URL="javascript:alert(1)"]x[/URL]',
        '[URL=JaVaScRiPt:alert(1)]x[/URL]',
        '[URL]javascript:alert(1)[/URL]',
        '[URL]data:text/html,<script>alert(1)</script>[/URL]',
        '[URL=//evil.example]x[/URL]',
        '[URL=/relative]x[/URL]',
        '[URL=ftp://example.com]x[/URL]',
        '[URL=https://a.com" onmouseover="alert(1)]x[/URL]',
        '[URL=" onmouseover=alert(1) "]x[/URL]',
    ])('links nothing for %s', (source) => {
        const tree = parseBbcode(source);

        expect(elementsOf(tree)).toEqual([]);
        expect(tree.map((node) => node.text).join('')).toBe(source);
    });

    it.each([
        '[IMG]javascript:alert(1)[/IMG]',
        '[IMG]http://7cav.us/a.png[/IMG]',
        '[IMG]data:image/svg+xml,<svg onload=alert(1)>[/IMG]',
        '[IMG]https://7cav.us/a.png" onerror="alert(1)[/IMG]',
        '[IMG]https://7cav.us/a.png onerror=alert(1)[/IMG]',
        '[IMG]//7cav.us/a.png[/IMG]',
        '[IMG][/IMG]',
        '[IMG=https://7cav.us/a.png][/IMG]',
    ])('shows no image for %s', (source) => {
        const tree = parseBbcode(source);

        expect(elementsOf(tree)).toEqual([]);
        expect(tree.map((node) => node.text).join('')).toBe(source);
    });

    it.each([
        'https://tracker.example/x.png',
        'https://evil7cav.us/x.png',
        'https://7cav.us.evil.example/x.png',
        'https://7cav.us@evil.example/x.png',
    ])('shows an image on another host, %s, as a link to it and never loads it', (address) => {
        const href = new URL(address).href;

        expect(read(`a [IMG]${address}[/IMG] b`)).toEqual(['a ', ['url', { attrs: { href, rel: 'noopener noreferrer nofollow', target: '_blank' } }, [address]], ' b']);
        expect(read(`[IMG width="20px"]${address}[/IMG]`)).toEqual([['url', { attrs: { href, rel: 'noopener noreferrer nofollow', target: '_blank' } }, [address]]]);
    });

    it('loads an image from 7cav.us or one of its subdomains', () => {
        expect(read('[IMG]https://www.7cav.us/a.png[/IMG]')).toEqual([['img', { attrs: { src: 'https://www.7cav.us/a.png', alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' } }, []]]);
    });

    it('finds an [IMG] or [URL] close by its place in the post, even after a letter whose lower case is longer', () => {
        // "İ" lower-cases to two characters, so a search in a lower-cased copy lands one place off per "İ".
        const tree = read('İnce.M [IMG]https://7cav.us/a.png[/IMG] after [URL]https://7cav.us[/URL] end');

        expect(tree).toEqual([
            'İnce.M ',
            ['img', { attrs: { src: 'https://7cav.us/a.png', alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' } }, []],
            ' after ',
            ['url', { attrs: { href: 'https://7cav.us/', rel: 'noopener noreferrer nofollow', target: '_blank' } }, ['https://7cav.us']],
            ' end',
        ]);
    });

    it('only ever sets the attributes it chose, with checked values', () => {
        const tree = parseBbcode(
            '[URL=https://7cav.us]a[/URL][IMG]https://7cav.us/a.png[/IMG][LIST=1][*]x[/LIST][COLOR=red;background:url(x)]c[/COLOR][SIZE=3;position:fixed]d[/SIZE]',
        );
        const names = new Set(attrsOf(tree).flatMap((attrs) => Object.keys(attrs)));

        expect([...names].sort()).toEqual(['alt', 'href', 'loading', 'referrerpolicy', 'rel', 'src', 'target', 'type']);
        expect(elementsOf(tree).flatMap((node) => Object.values(node.style))).toEqual([]);
    });
});

describe('the address and style checks', () => {
    it('accepts only full http and https links, and only https images on 7cav.us', () => {
        expect(safeLink('https://7cav.us/x')).toBe('https://7cav.us/x');
        expect(safeLink(' http://7cav.us ')).toBe('http://7cav.us/');
        expect(safeLink('javascript:alert(1)')).toBeNull();
        expect(safeLink('https://')).toBeNull();
        expect(safeImage('https://7cav.us/a.png')).toBe('https://7cav.us/a.png');
        expect(safeImage('http://7cav.us/a.png')).toBeNull();
        expect(safeImage('https://WWW.7cav.US/a.png')).toBe('https://www.7cav.us/a.png');
        for (const bad of ['https://tracker.example/x.png', 'https://evil7cav.us/a.png', 'https://7cav.us.evil.example/a.png', 'https://7cav.us@evil.example/a.png', 'https://7cav.us./a.png']) {
            expect(safeImage(bad), bad).toBeNull();
        }
    });

    it('accepts colours and sizes it knows', () => {
        expect(safeColor('Gold')).toBe('gold');
        expect(safeColor('#ABC')).toBe('#abc');
        expect(safeColor('rgb(0,0,0)')).toBe('rgb(0, 0, 0)');
        expect(safeColor('rgba(0,0,0,0.5)')).toBeNull();
        expect(safeSize('5')).toBe('18px');
        expect(safeSize('36px')).toBe('36px');
        expect(safeSize('37px')).toBeNull();
    });
});

describe('the starter template, filled', () => {
    const STARTER = `[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH][TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD][TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_attached}}ATT{{/sp_attached}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
{{/troopers}}{{^troopers}}[TR][TD]No troopers are placed in this section.[/TD][/TR]
{{/troopers}}[/TABLE]`;

    it('draws the heading, then a table with a row per trooper and the rank insignia before the name', () => {
        const context = sampleContext();
        const tree = read(render(STARTER, context).text);
        const icon = (file, width) => ['img', { attrs: { src: `https://7cav.us/data/roster_ranks/0/${file}`, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }, style: { width, height: 'auto' } }, []];

        expect(tree[0]).toEqual(['center', { style: { textAlign: 'center' } }, [['b', ['1/E/2-7 ROLL CALL | 16MAY26 – 22MAY26']]]]);
        expect(tree[1]).toEqual('br');
        expect(tree[2]).toEqual(['b', ['Roll Call Accountability']]);
        const [, rows] = tree[4];
        expect(tree[4][0]).toBe('table');
        expect(rows).toHaveLength(1 + context.troopers.length);
        expect(rows[0]).toEqual(['tr', [['th', ['#']], ['th', ['Name']], ['th', ['Signed RC']], ['th', ['Attended SP']]]]);
        expect(rows[1]).toEqual(['tr', [['td', ['1']], ['td', [icon('18.jpg?1741376797', '11px'), ' Sergeant Jay.JH']], ['td', ['✓']], ['td', ['MU']]]]);
        expect(rows[5]).toEqual(['tr', [['td', ['5']], ['td', [icon('22.jpg?1741376831', '17px'), ' Private Okafor.B']], ['td', ['-']], ['td', ['-']]]]);
    });

    it('prints just the name for a trooper Cav has no insignia for', () => {
        const context = sampleContext();
        context.troopers[4] = { ...context.troopers[4], rank_icon: '' };
        const [, rows] = read(render(STARTER, context).text)[4];

        expect(rows[5]).toEqual(['tr', [['td', ['5']], ['td', ['Private Okafor.B']], ['td', ['-']], ['td', ['-']]]]);
    });

    it('says so in the table for an empty section', () => {
        const tree = read(render(STARTER, { ...sampleContext(), troopers: [] }).text);

        expect(tree.at(-1)).toEqual(['table', [['tr', [['th', ['#']], ['th', ['Name']], ['th', ['Signed RC']], ['th', ['Attended SP']]]], ['tr', [['td', ['No troopers are placed in this section.']]]]]]);
    });

    it('keeps a name that holds BBCode or HTML as text inside its cell', () => {
        const context = sampleContext();
        context.troopers[0].name = '[IMG]https://7cav.us/x.png[/IMG]<script>alert(1)</script>';
        const tree = parseBbcode(render(STARTER, context).text);

        // One insignia per trooper; the name's own [IMG] stays text.
        expect(elementsOf(tree).filter((node) => node.kind === 'img')).toHaveLength(context.troopers.length);
        expect(JSON.stringify(tree)).toContain('<script>alert(1)</script>');
    });
});
