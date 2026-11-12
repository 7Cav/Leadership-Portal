// A copy of the server's starter template (`TemplateSyntax::STARTER`), for the browser tests: the renderer must render it
// and the visual editor must give it back unchanged. `tests/Unit/Rollcall/TemplateSyntaxTest.php` holds it to the
// server's word for word, so a change to one that is not made to the other fails there.
export const STARTER = `[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH]{{#fireteams_shown}}[TH]Role[/TH]{{/fireteams_shown}}[TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD]{{#fireteams_shown}}[TD]{{role}}[/TD]{{/fireteams_shown}}[TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_makeup_requested}}MU{{/rc_makeup_requested}}{{#rc_excused}}EX{{/rc_excused}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_excused}}EX{{/sp_excused}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
{{/troopers}}{{^troopers}}[TR][TD]No troopers are placed in this section.[/TD][/TR]
{{/troopers}}[/TABLE]`;
