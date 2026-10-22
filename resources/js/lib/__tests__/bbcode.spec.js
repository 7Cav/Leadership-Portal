import { describe, expect, it } from 'vitest';
import cases from '../../../../tests/Fixtures/bbcode-templates.json';
import { DESCRIBED, FILTERS, LISTS, ROW_FLAGS, ROW_STRINGS, TOP_STRINGS, inert, parse, render, sampleContext, templateLegend } from '../bbcode';

// Story 3.10. The grammar cases are shared with the server's TemplateSyntax (tests/Fixtures/bbcode-templates.json), so the
// browser and the server accept and refuse the same templates with the same messages.

const row = (overrides = {}) => ({
    n: '1',
    rank: 'Sergeant',
    rank_short: 'SGT',
    rank_icon: 'https://7cav.us/data/roster_ranks/0/12.jpg',
    rank_icon_width: '8px',
    name: 'Jay.JH',
    display_name: 'SGT Jay.JH',
    rc: 'Yes',
    sp: 'Yes',
    sp_present: true,
    sp_missed: false,
    sp_makeup: false,
    sp_attached: false,
    sp_loa: false,
    sp_unmarked: false,
    rc_yes: true,
    rc_no: false,
    rc_unsigned: false,
    ...overrides,
});

const context = (overrides = {}) => ({
    unit: '1/E/2-7',
    unit_name: 'Jaegers',
    week: '16May26 – 22May26',
    week_start: '16May26',
    week_end: '22May26',
    count: '1',
    signed: '1',
    attended: '1',
    troopers: [row()],
    ...overrides,
});

describe('the legend of template codes', () => {
    it('describes every name the parser accepts and nothing else, so the legend cannot drift', () => {
        expect([...DESCRIBED].sort()).toEqual([...TOP_STRINGS, ...LISTS, ...ROW_STRINGS, ...ROW_FLAGS, ...FILTERS].sort());
    });

    it('groups the codes as the parser reads them, each with a meaning and an example', () => {
        const legend = templateLegend();

        expect(legend.map((group) => [group.key, group.entries.map((entry) => entry.name)])).toEqual([
            ['values', TOP_STRINGS],
            ['lists', LISTS],
            ['rowValues', ROW_STRINGS],
            ['rowFlags', ROW_FLAGS],
            ['filters', FILTERS],
        ]);
        for (const entry of legend.flatMap((group) => group.entries)) {
            expect(entry.meaning, entry.name).not.toBe('');
            expect(entry.example, entry.name).not.toBe('');
        }
    });

    it('writes each code as a template would, and every example that is a template parses', () => {
        const codes = Object.fromEntries(templateLegend().flatMap((group) => group.entries.map((entry) => [entry.name, entry])));

        expect(codes.unit.code).toBe('{{unit}}');
        expect(codes.troopers.code).toBe('{{#troopers}}...{{/troopers}}');
        expect(codes.rank_icon.code).toBe('{{rank_icon}}');
        expect(codes.rank_icon_width.code).toBe('{{rank_icon_width}}');
        expect(codes.rank_icon_width.example).toBe('[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG]');
        expect(codes.sp_present.code).toBe('{{#sp_present}}...{{/sp_present}}');
        expect(codes.upper.code).toBe('{{value|upper}}');

        const inRow = new Set([...ROW_STRINGS, ...ROW_FLAGS]);
        for (const { name, example } of Object.values(codes).filter((entry) => entry.example.includes('{{'))) {
            expect(parse(inRow.has(name) ? `{{#troopers}}${example}{{/troopers}}` : example), name).toMatchObject({ ok: true });
        }
    });

    it('splits a section into the open and close tags the editor puts around a selection, and keeps any other code whole', () => {
        const codes = Object.fromEntries(templateLegend().flatMap((group) => group.entries.map((entry) => [entry.name, entry])));

        expect(codes.troopers).toMatchObject({ open: '{{#troopers}}', close: '{{/troopers}}' });
        expect(codes.sp_present).toMatchObject({ open: '{{#sp_present}}', close: '{{/sp_present}}' });
        expect(codes.unit).toMatchObject({ open: '{{unit}}', close: '' });
        expect(codes.rank_icon).toMatchObject({ open: '{{rank_icon}}', close: '' });
        expect(codes.upper).toMatchObject({ open: '{{value|upper}}', close: '' });
    });
});

describe('the sample section for the template preview', () => {
    it('gives every value and flag the template language has, the first trooper being the legend example', () => {
        const sample = sampleContext();
        const codes = Object.fromEntries(templateLegend().flatMap((group) => group.entries.map((entry) => [entry.name, entry.example])));

        expect(Object.keys(sample).sort()).toEqual([...TOP_STRINGS, ...LISTS].sort());
        for (const name of ['unit', 'unit_name', 'week', 'week_start', 'week_end']) expect(sample[name], name).toBe(codes[name]);
        for (const trooper of sample.troopers) expect(Object.keys(trooper).sort()).toEqual([...ROW_STRINGS, ...ROW_FLAGS].sort());
        expect(sample.troopers[0]).toMatchObject({ n: '1', rank: codes.rank, rank_short: codes.rank_short, name: codes.name, rc: codes.rc, sp: codes.sp });
    });

    it('counts its totals from its rows, and gives every row its rank insignia', () => {
        const sample = sampleContext();

        expect(sample.count).toBe(String(sample.troopers.length));
        expect(sample.signed).toBe(String(sample.troopers.filter((row) => row.rc_yes).length));
        expect(sample.attended).toBe(String(sample.troopers.filter((row) => row.sp_present || row.sp_makeup || row.sp_attached).length));
        for (const row of sample.troopers) expect(row.rank_icon).toMatch(/^https:\/\/7cav\.us\/data\/roster_ranks\/0\/\d+\.jpg\?\d+$/);
        // Sized so every rank looks the same: a sergeant's chevrons, a corporal's, a specialist's badge, a PFC's, a private's.
        expect(sample.troopers.map((row) => row.rank_icon_width)).toEqual(['11px', '13px', '13px', '13px', '17px']);
    });
});

describe('the grammar, against the cases shared with the server', () => {
    it.each(cases.valid.map((c) => [c.name, c.template]))('accepts: %s', (_name, template) => {
        expect(parse(template)).toMatchObject({ ok: true });
    });

    it.each(cases.invalid.map((c) => [c.name, c.template, c.error]))('refuses: %s', (_name, template, error) => {
        const result = parse(template);

        expect(result.ok).toBe(false);
        expect(result.error).toContain(error);
    });

    it('lists the same names as the server, one per case, so the two lists cannot drift', () => {
        const valid = cases.valid.map((c) => c.template).join('\n');

        for (const name of [...TOP_STRINGS, ...ROW_STRINGS, ...ROW_FLAGS]) expect(valid).toContain(name);
        expect(LISTS).toEqual(['troopers']);
        expect(FILTERS).toEqual(['upper', 'lower']);
    });
});

describe('render', () => {
    it('prints values and leaves everything else exactly as written, BBCode included', () => {
        const out = render('[CENTER][B]{{unit}} ROLL CALL | {{week}}[/B][/CENTER]', context());

        expect(out).toEqual({ ok: true, text: '[CENTER][B]1/E/2-7 ROLL CALL | 16May26 – 22May26[/B][/CENTER]' });
    });

    it('repeats a trooper block once per trooper, in order, with the row values', () => {
        const out = render('{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{rank}} {{name}}[/TD][TD]{{rc}}[/TD][/TR]\n{{/troopers}}', context({
            troopers: [row(), row({ n: '2', rank: 'Specialist', name: 'Milkovic.D', rc: 'No' })],
        }));

        expect(out.text).toBe('[TR][TD]1[/TD][TD]Sergeant Jay.JH[/TD][TD]Yes[/TD][/TR]\n[TR][TD]2[/TD][TD]Specialist Milkovic.D[/TD][TD]No[/TD][/TR]\n');
    });

    it('still reaches the top-level values from inside the loop', () => {
        expect(render('{{#troopers}}{{unit}}:{{name}};{{/troopers}}', context()).text).toBe('1/E/2-7:Jay.JH;');
    });

    it('prints the summary counts', () => {
        expect(render('{{count}} troopers, {{signed}} signed, {{attended}} attended', context({ count: '8', signed: '3', attended: '7' })).text).toBe('8 troopers, 3 signed, 7 attended');
    });

    it('reads a count of zero as not set, so {{^attended}} can say nobody attended', () => {
        const template = '{{#attended}}{{attended}} attended{{/attended}}{{^attended}}Nobody attended{{/attended}}';

        expect(render(template, context({ attended: '0' })).text).toBe('Nobody attended');
        expect(render(template, context({ attended: '3' })).text).toBe('3 attended');
        expect(render(template, context({ attended: '10' })).text).toBe('10 attended');
    });

    it('renders each status the way the template defines it: present, missed, makeup, attached and LOA', () => {
        const template = '{{#troopers}}{{name}}={{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_attached}}ATT{{/sp_attached}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}};{{/troopers}}';
        const flags = (name) => ({ sp_present: false, sp_missed: false, sp_makeup: false, sp_attached: false, sp_loa: false, sp_unmarked: false, [name]: true });

        const out = render(template, context({
            troopers: [
                row({ name: 'A', ...flags('sp_present') }),
                row({ name: 'B', ...flags('sp_missed') }),
                row({ name: 'C', ...flags('sp_makeup') }),
                row({ name: 'D', ...flags('sp_attached') }),
                row({ name: 'E', ...flags('sp_loa') }),
                row({ name: 'F', ...flags('sp_unmarked') }),
            ],
        }));

        expect(out.text).toBe('A=✓;B=✗;C=MU;D=ATT;E=LOA;F=-;');
    });

    it('renders the signature three ways, including an inverted flag', () => {
        const out = render('{{#troopers}}{{#rc_yes}}Y{{/rc_yes}}{{#rc_no}}N{{/rc_no}}{{#rc_unsigned}}-{{/rc_unsigned}}{{^rc_yes}}!{{/rc_yes}},{{/troopers}}', context({
            troopers: [row({ rc_yes: true }), row({ rc_yes: false, rc_no: true }), row({ rc_yes: false, rc_unsigned: true })],
        }));

        expect(out.text).toBe('Y,N!,-!,');
    });

    it('renders an empty roster as the skeleton, with the template\'s empty-section text', () => {
        const template = '[B]{{unit}}[/B]\n{{#troopers}}{{name}}\n{{/troopers}}{{^troopers}}No troopers this week.{{/troopers}}';

        expect(render(template, context({ troopers: [], count: '0' })).text).toBe('[B]1/E/2-7[/B]\nNo troopers this week.');
        expect(render(template, context()).text).toBe('[B]1/E/2-7[/B]\nJay.JH\n');
    });

    it('renders an empty roster without crashing when the template has no empty-section text', () => {
        expect(render('[TABLE]{{#troopers}}[TR]{{name}}[/TR]{{/troopers}}[/TABLE]', context({ troopers: [] })).text).toBe('[TABLE][/TABLE]');
    });

    it('prints the rank icon into an [IMG] tag, and nothing at all when a trooper has none', () => {
        const template = '{{#troopers}}{{#rank_icon}}[IMG]{{rank_icon}}[/IMG] {{/rank_icon}}{{name}};{{/troopers}}';

        expect(render(template, context({ troopers: [row(), row({ name: 'Adams.P', rank_icon: '' })] })).text).toBe(
            '[IMG]https://7cav.us/data/roster_ranks/0/12.jpg[/IMG] Jay.JH;Adams.P;',
        );
    });

    it('prints the rank icon width into the [IMG] tag the forum sizes it by', () => {
        const template = '{{#troopers}}{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{name}};{{/troopers}}';

        expect(render(template, context({ troopers: [row(), row({ name: 'Adams.P', rank_icon: '', rank_icon_width: '' })] })).text).toBe(
            '[IMG width="8px" height="auto"]https://7cav.us/data/roster_ranks/0/12.jpg[/IMG] Jay.JH;Adams.P;',
        );
    });

    it('applies filters', () => {
        expect(render('{{week|upper}} {{unit|lower}} {{#troopers}}{{name | upper}}{{/troopers}}', context()).text).toBe('16MAY26 – 22MAY26 1/e/2-7 JAY.JH');
    });

    it('reports a malformed template instead of emitting broken BBCode', () => {
        expect(render('{{#troopers}}{{name}}', context())).toEqual({ ok: false, error: 'The section "{{#troopers}}" is never closed.' });
    });

    it('treats a section on an empty string or false as not set, and on a non-empty string as set', () => {
        expect(render('{{#troopers}}{{#rc}}yes{{/rc}}{{^rc}}no{{/rc}}{{/troopers}}', context({ troopers: [row({ rc: '' })] })).text).toBe('no');
        expect(render('{{#troopers}}{{#rc}}yes{{/rc}}{{/troopers}}', context({ troopers: [row({ rc: 'Yes' })] })).text).toBe('yes');
    });

    it('is deterministic: the same template and data give the same text every time', () => {
        const template = '{{#troopers}}{{n}}{{name}}{{/troopers}}{{week}}';

        expect(render(template, context()).text).toBe(render(template, context()).text);
    });

    it('prints nothing for a value the context does not carry, rather than "undefined"', () => {
        expect(render('[{{unit}}]', {}).text).toBe('[]');
    });
});

describe('an adversarial template', () => {
    it('is parsed in linear time: a long run of spaces inside a tag is not slow', () => {
        const started = performance.now();
        const result = parse('{{' + ' '.repeat(19000) + '!');
        const elapsed = performance.now() - started;

        expect(result.ok).toBe(false);
        expect(elapsed).toBeLessThan(150);
    });

    it('is not slow for thousands of tags either', () => {
        const template = '{{#troopers}}' + '{{name}}'.repeat(2400) + '{{/troopers}}';
        const started = performance.now();

        expect(parse(template).ok).toBe(true);
        expect(performance.now() - started).toBeLessThan(150);
    });

    it('treats a name that merely looks like an object property as unknown', () => {
        expect(parse('{{__proto__}}')).toEqual({ ok: false, error: 'Unknown name "__proto__".' });
        expect(parse('{{#troopers}}{{constructor}}{{/troopers}}').ok).toBe(false);
    });

    it('renders the same template again without parsing it again', () => {
        const first = render('[{{unit}}]', context());
        const second = render('[{{unit}}]', context({ unit: 'other' }));

        expect(first.text).toBe('[1/E/2-7]');
        expect(second.text).toBe('[other]');
    });
});

describe('a value cannot become BBCode', () => {
    it('writes an opening bracket with a zero-width space after it and a closing one fullwidth, so a forum will not read a tag', () => {
        expect(inert('[b]x[/b]')).toBe('[\u200Bb\uFF3Dx[\u200B/b\uFF3D');
        expect(inert('plain')).toBe('plain');
    });

    it('applies it to every printed value, so a username or billet can never open or close a tag', () => {
        const out = render('{{#troopers}}[TD]{{name}}[/TD]{{display_name}}{{/troopers}}', context({ troopers: [row({ name: '[/TD][B]evil', display_name: '[URL=x]click[/URL]' })] }));

        expect(out.text).toBe('[TD][\u200B/TD\uFF3D[\u200BB\uFF3Devil[/TD][\u200BURL=x\uFF3Dclick[\u200B/URL\uFF3D');
        // The only brackets left bare are the template's own [TD] and [/TD].
        expect(out.text.match(/\[(?!\u200B)/g)).toHaveLength(2);
        expect(out.text.match(/\]/g)).toHaveLength(2);
    });

    it('keeps a value inside a tag option from ending the option early', () => {
        const out = render('{{#troopers}}[URL={{name}}]profile[/URL]{{/troopers}}', context({ troopers: [row({ name: 'x]evil' })] }));

        expect(out.text).toBe('[URL=x\uFF3Devil]profile[/URL]');
        expect(out.text.indexOf(']')).toBe(out.text.indexOf(']profile'));
    });

    it('does not alter the template\'s own BBCode', () => {
        expect(render('[B]{{unit}}[/B]', context()).text).toBe('[B]1/E/2-7[/B]');
    });

    it('does not run a name that merely looks like code', () => {
        const out = render('{{#troopers}}{{name}}{{/troopers}}', context({ troopers: [row({ name: '{{unit}}' })] }));

        expect(out.text).toBe('{{unit}}');
    });
});
