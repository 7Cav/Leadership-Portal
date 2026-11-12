import { describe, expect, it } from 'vitest';
import { parse, render } from '../bbcode';
import { STARTER } from './templateStarter';

// The server ships a starter template for a company that has none (`TemplateSyntax::STARTER`). The browser must be able to
// render it, so a copy (`templateStarter.js`) is checked against the same grammar: if the two ever drift, one of them fails.

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

    it('marks an excused practice EX, and a make-up requested or excused roll call MU and EX (#629)', () => {
        const rows = [
            { n: '1', rank: 'Private', rank_icon: '', rank_icon_width: '', name: 'Bell.A', rc_makeup_requested: true, ...flags('sp_excused') },
            { n: '2', rank: 'Private', rank_icon: '', rank_icon_width: '', name: 'Cole.B', rc_excused: true, ...flags('sp_missed') },
        ];
        const out = render(STARTER, { ...CONTEXT, troopers: rows }).text;

        expect(out).toContain('[TR][TD]1[/TD][TD]Private Bell.A[/TD][TD]MU[/TD][TD]EX[/TD][/TR]');
        expect(out).toContain('[TR][TD]2[/TD][TD]Private Cole.B[/TD][TD]EX[/TD][TD]✗[/TD][/TR]');
    });

    it('says so for an empty section', () => {
        expect(render(STARTER, { ...CONTEXT, troopers: [] }).text).toContain('[TR][TD]No troopers are placed in this section.[/TD][/TR]');
    });

    it('adds a Role column only when the section shows fireteam leaders and has one', () => {
        const roles = ['Section Leader (FTL Alpha)', ''];
        const shown = render(STARTER, { ...CONTEXT, fireteams_shown: true, troopers: CONTEXT.troopers.map((row, i) => ({ ...row, role: roles[i] })) }).text;
        const hidden = render(STARTER, { ...CONTEXT, fireteams_shown: false, troopers: CONTEXT.troopers.map((row, i) => ({ ...row, role: roles[i] })) }).text;

        expect(shown).toContain('[TH]Name[/TH][TH]Role[/TH][TH]Signed RC[/TH]');
        expect(shown).toContain('Sergeant Jay.JH[/TD][TD]Section Leader (FTL Alpha)[/TD][TD]✓[/TD]');
        expect(shown).toContain('Specialist Adams.P[/TD][TD][/TD][TD]✗[/TD]');
        expect(hidden).not.toContain('Role');
        expect(hidden).toContain('Sergeant Jay.JH[/TD][TD]✓[/TD]');
    });
});
