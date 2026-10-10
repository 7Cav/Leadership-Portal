import { describe, expect, it } from 'vitest';
import cases from '../../../../tests/Fixtures/bbcode-templates.json';
import { FILTERS, LISTS, ROW_FLAGS, ROW_STRINGS, TOP_STRINGS, inert, parse, render, rollcallContext } from '../bbcode';
import { compareByBillet } from '../ranks';

// Story 3.10. The grammar cases are shared with the server's TemplateSyntax (tests/Fixtures/bbcode-templates.json), so the
// browser and the server accept and refuse the same templates with the same messages.

const row = (overrides = {}) => ({
    n: '1',
    rank: 'Sergeant',
    rank_short: 'SGT',
    name: 'Jay.JH',
    display_name: 'SGT Jay.JH',
    role: 'Section Leader',
    rc: 'Yes',
    sp: 'Yes',
    loa_ends: '',
    loa_type: '',
    sp_present: true,
    sp_missed: false,
    sp_makeup: false,
    sp_attached: false,
    sp_loa: false,
    sp_unmarked: false,
    rc_yes: true,
    rc_no: false,
    rc_unsigned: false,
    loa_review: false,
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
        const out = render('{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{rank}} {{name}}[/TD][TD]{{role}}[/TD][/TR]\n{{/troopers}}', context({
            troopers: [row(), row({ n: '2', rank: 'Specialist', name: 'Milkovic.D', role: 'Asst. Section Leader' })],
        }));

        expect(out.text).toBe('[TR][TD]1[/TD][TD]Sergeant Jay.JH[/TD][TD]Section Leader[/TD][/TR]\n[TR][TD]2[/TD][TD]Specialist Milkovic.D[/TD][TD]Asst. Section Leader[/TD][/TR]\n');
    });

    it('still reaches the top-level values from inside the loop', () => {
        expect(render('{{#troopers}}{{unit}}:{{name}};{{/troopers}}', context()).text).toBe('1/E/2-7:Jay.JH;');
    });

    it('prints the summary counts', () => {
        expect(render('{{count}} troopers, {{signed}} signed, {{attended}} attended', context({ count: '8', signed: '3', attended: '7' })).text).toBe('8 troopers, 3 signed, 7 attended');
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

    it('shows a leave line only for a trooper who has one', () => {
        const out = render('{{#troopers}}{{name}}{{#loa_ends}} {{loa_type}} · ends {{loa_ends}}{{/loa_ends}};{{/troopers}}', context({
            troopers: [row({ name: 'Adams.P', loa_ends: '30May26', loa_type: 'LOA' }), row({ name: 'Jay.JH' })],
        }));

        expect(out.text).toBe('Adams.P LOA · ends 30May26;Jay.JH;');
    });

    it('flags a leave that needs review for a person', () => {
        expect(render('{{#troopers}}{{#loa_review}}CHECK LOA{{/loa_review}}{{/troopers}}', context({ troopers: [row({ loa_review: true })] })).text).toBe('CHECK LOA');
    });

    it('renders an empty roster as the skeleton, with the template\'s empty-section text', () => {
        const template = '[B]{{unit}}[/B]\n{{#troopers}}{{name}}\n{{/troopers}}{{^troopers}}No troopers this week.{{/troopers}}';

        expect(render(template, context({ troopers: [], count: '0' })).text).toBe('[B]1/E/2-7[/B]\nNo troopers this week.');
        expect(render(template, context()).text).toBe('[B]1/E/2-7[/B]\nJay.JH\n');
    });

    it('renders an empty roster without crashing when the template has no empty-section text', () => {
        expect(render('[TABLE]{{#troopers}}[TR]{{name}}[/TR]{{/troopers}}[/TABLE]', context({ troopers: [] })).text).toBe('[TABLE][/TABLE]');
    });

    it('applies filters', () => {
        expect(render('{{week|upper}} {{unit|lower}} {{#troopers}}{{name | upper}}{{/troopers}}', context()).text).toBe('16MAY26 – 22MAY26 1/e/2-7 JAY.JH');
    });

    it('reports a malformed template instead of emitting broken BBCode', () => {
        expect(render('{{#troopers}}{{name}}', context())).toEqual({ ok: false, error: 'The section "{{#troopers}}" is never closed.' });
    });

    it('treats a section on an empty string or false as not set, and on a non-empty string as set', () => {
        expect(render('{{#troopers}}{{#loa_ends}}yes{{/loa_ends}}{{^loa_ends}}no{{/loa_ends}}{{/troopers}}', context({ troopers: [row({ loa_ends: '' })] })).text).toBe('no');
        expect(render('{{#troopers}}{{#loa_ends}}yes{{/loa_ends}}{{/troopers}}', context({ troopers: [row({ loa_ends: '30May26' })] })).text).toBe('yes');
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
    it('writes an opening bracket with a zero-width space after it, so a forum will not read a tag', () => {
        expect(inert('[b]x[/b]')).toBe('[\u200Bb]x[\u200B/b]');
        expect(inert('plain')).toBe('plain');
    });

    it('applies it to every printed value, so a username or billet can never open or close a tag', () => {
        const out = render('{{#troopers}}[TD]{{name}}[/TD]{{role}}{{/troopers}}', context({ troopers: [row({ name: '[/TD][B]evil', role: '[URL=x]click[/URL]' })] }));

        expect(out.text).toBe('[TD][\u200B/TD][\u200BB]evil[/TD][\u200BURL=x]click[\u200B/URL]');
        // The only brackets left bare are the template's own [TD] and [/TD].
        expect(out.text.match(/\[(?!\u200B)/g)).toHaveLength(2);
    });

    it('does not alter the template\'s own BBCode', () => {
        expect(render('[B]{{unit}}[/B]', context()).text).toBe('[B]1/E/2-7[/B]');
    });

    it('does not run a name that merely looks like code', () => {
        const out = render('{{#troopers}}{{name}}{{/troopers}}', context({ troopers: [row({ name: '{{unit}}' })] }));

        expect(out.text).toBe('{{unit}}');
    });
});

describe('rollcallContext', () => {
    const screen = {
        section: { id: 5, name: 'Jaegers', callsign: '1/E/2-7' },
        week: { start: '2026-05-16', end: '2026-05-22' },
        troopers: [
            { id: 1, displayName: 'PFC Svensson.A', username: 'Svensson.A', rank: 'Private First Class', rankShort: 'PFC', billet: null, practice: { value: 'present', origin: 'carried', source: null }, rollcall: 'unsigned', loa: { state: 'none' } },
            { id: 2, displayName: 'SGT Jay.JH', username: 'Jay.JH', rank: 'Sergeant', rankShort: 'SGT', billet: 'Section Leader', practice: { value: 'present', origin: 'saved', source: 'manual' }, rollcall: 'will_attend', loa: { state: 'none' } },
            { id: 3, displayName: 'SPC Adams.P', username: 'Adams.P', rank: 'Specialist', rankShort: 'SPC', billet: 'Rifleman', practice: { value: 'loa', origin: 'leave', source: null }, rollcall: 'cannot_attend', loa: { state: 'covered', typeLabel: 'LOA', endsAt: '2026-05-30' } },
            { id: 4, displayName: 'SPC Crooks.A', username: 'Crooks.A', rank: 'Specialist', rankShort: 'SPC', billet: 'Rifleman', practice: { value: null, origin: null, source: null }, rollcall: 'unsigned', loa: { state: 'needs_review', typeLabel: 'LOA', endsAt: null } },
        ],
    };
    const draft = { 1: { sp: 'missed', rc: null }, 2: { sp: 'present', rc: 'will_attend' }, 3: { sp: null, rc: 'cannot_attend' }, 4: { sp: 'makeup', rc: null } };
    const built = rollcallContext(screen, draft, compareByBillet);

    it('describes the section and the week in forum convention', () => {
        expect(built).toMatchObject({ unit: '1/E/2-7', unit_name: 'Jaegers', week: '16May26 – 22May26', week_start: '16May26', week_end: '22May26' });
    });

    it('falls back to the section name when it has no callsign', () => {
        expect(rollcallContext({ ...screen, section: { id: 5, name: 'Jaegers', callsign: null } }, draft, compareByBillet).unit).toBe('Jaegers');
    });

    it('lists the troopers senior first and numbers them', () => {
        expect(built.troopers.map((t) => [t.n, t.name])).toEqual([['1', 'Jay.JH'], ['2', 'Adams.P'], ['3', 'Crooks.A'], ['4', 'Svensson.A']]);
    });

    it('takes each practice value from the draft, so the BBCode follows the toggles', () => {
        const byName = Object.fromEntries(built.troopers.map((t) => [t.name, t]));

        expect(byName['Svensson.A']).toMatchObject({ sp: 'No', sp_missed: true, sp_present: false });
        expect(byName['Crooks.A']).toMatchObject({ sp: 'MU', sp_makeup: true });
        expect(byName['Jay.JH']).toMatchObject({ sp: 'Yes', sp_present: true });
    });

    it('derives LOA for a trooper on leave whatever the draft says, with the end date in forum convention', () => {
        const adams = built.troopers.find((t) => t.name === 'Adams.P');

        expect(adams).toMatchObject({ sp: 'LOA', sp_loa: true, sp_unmarked: false, loa_ends: '30May26', loa_type: 'LOA' });
    });

    it('flags a leave that needs review, and shows no date for it', () => {
        const crooks = built.troopers.find((t) => t.name === 'Crooks.A');

        expect(crooks).toMatchObject({ loa_review: true, loa_ends: '', sp_loa: false });
    });

    it('reads the signature from the draft too', () => {
        const byName = Object.fromEntries(built.troopers.map((t) => [t.name, t]));

        expect(byName['Jay.JH']).toMatchObject({ rc: 'Yes', rc_yes: true, rc_unsigned: false });
        expect(byName['Adams.P']).toMatchObject({ rc: 'No', rc_no: true });
        expect(byName['Svensson.A']).toMatchObject({ rc: '', rc_unsigned: true });
    });

    it('counts who signed and who attended', () => {
        expect(built).toMatchObject({ count: '4', signed: '1', attended: '2' });
    });

    it('marks a trooper nobody has marked as unmarked', () => {
        const unmarked = rollcallContext(screen, { 1: { sp: null, rc: null }, 2: { sp: null, rc: null }, 3: { sp: null, rc: null }, 4: { sp: null, rc: null } }, compareByBillet);

        expect(unmarked.troopers.find((t) => t.name === 'Jay.JH')).toMatchObject({ sp: '', sp_unmarked: true });
        expect(unmarked.attended).toBe('0');
    });

    it('prints a trooper with no billet as an empty role, and one with no username by display name', () => {
        const custom = rollcallContext({ ...screen, troopers: [{ ...screen.troopers[0], username: null, billet: null }] }, draft, compareByBillet);

        expect(custom.troopers[0]).toMatchObject({ role: '', name: 'PFC Svensson.A' });
    });

    it('renders a whole mockup-style post from a real context', () => {
        const template = '[CENTER][B]{{unit}} ROLL CALL | {{week|upper}}[/B][/CENTER]\n[TABLE]\n[TR][TH]#[/TH][TH]Name[/TH][TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]\n{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{rank}} {{name}}[/TD][TD]{{rc}}[/TD][TD]{{#sp_loa}}LOA · ends {{loa_ends}}{{/sp_loa}}{{^sp_loa}}{{sp}}{{/sp_loa}}[/TD][/TR]\n{{/troopers}}[/TABLE]';

        expect(render(template, built).text).toBe(
            '[CENTER][B]1/E/2-7 ROLL CALL | 16MAY26 – 22MAY26[/B][/CENTER]\n[TABLE]\n[TR][TH]#[/TH][TH]Name[/TH][TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]\n'
            + '[TR][TD]1[/TD][TD]Sergeant Jay.JH[/TD][TD]Yes[/TD][TD]Yes[/TD][/TR]\n'
            + '[TR][TD]2[/TD][TD]Specialist Adams.P[/TD][TD]No[/TD][TD]LOA · ends 30May26[/TD][/TR]\n'
            + '[TR][TD]3[/TD][TD]Specialist Crooks.A[/TD][TD][/TD][TD]MU[/TD][/TR]\n'
            + '[TR][TD]4[/TD][TD]Private First Class Svensson.A[/TD][TD][/TD][TD]No[/TD][/TR]\n[/TABLE]',
        );
    });
});
