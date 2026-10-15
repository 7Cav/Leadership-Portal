import { describe, expect, it } from 'vitest';
import { parse, render } from '../bbcode';

// The server ships a starter template for a company that has none (`TemplateSyntax::STARTER`). The browser must be able to
// render it, so it is kept here as a copy and checked against the same grammar: if the two ever drift, one of them fails.

const STARTER = `[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH][TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD][TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_attached}}ATT{{/sp_attached}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
{{/troopers}}{{^troopers}}[TR][TD]No troopers are placed in this section.[/TD][/TR]
{{/troopers}}[/TABLE]`;

const flags = (name) => ({ sp_present: false, sp_missed: false, sp_makeup: false, sp_attached: false, sp_loa: false, sp_unmarked: false, [name]: true });
const CONTEXT = {
    unit: '1/E/2-7',
    week: '16May26 – 22May26',
    troopers: [
        { n: '1', rank: 'Sergeant', rank_icon: 'https://7cav.us/data/roster_ranks/0/18.jpg', rank_icon_width: '11px', name: 'Jay.JH', rc_yes: true, rc_no: false, rc_unsigned: false, ...flags('sp_present') },
        { n: '2', rank: 'Specialist', rank_icon: '', rank_icon_width: '', name: 'Adams.P', rc_yes: false, rc_no: true, rc_unsigned: false, ...flags('sp_loa') },
    ],
};

describe('the starter template', () => {
    it('follows the grammar', () => {
        expect(parse(STARTER)).toMatchObject({ ok: true });
    });

    it('renders a post like the mockup, each status in its own mark', () => {
        const out = render(STARTER, CONTEXT).text;

        expect(out).toContain('[B]1/E/2-7 ROLL CALL | 16MAY26 – 22MAY26[/B]');
        expect(out).toContain('[TR][TD]1[/TD][TD][IMG width="11px" height="auto"]https://7cav.us/data/roster_ranks/0/18.jpg[/IMG] Sergeant Jay.JH[/TD][TD]✓[/TD][TD]✓[/TD][/TR]');
        // No insignia from Cav: no empty [IMG] tag, just the name.
        expect(out).toContain('[TR][TD]2[/TD][TD]Specialist Adams.P[/TD][TD]✗[/TD][TD]LOA[/TD][/TR]');
    });

    it('says so for an empty section', () => {
        expect(render(STARTER, { ...CONTEXT, troopers: [] }).text).toContain('[TR][TD]No troopers are placed in this section.[/TD][/TR]');
    });
});
