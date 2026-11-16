// A copy of the server's starter template (`TemplateSyntax::STARTER`), for the browser tests: the renderer must render it
// and the visual editor must give it back unchanged. `tests/Unit/Rollcall/TemplateSyntaxTest.php` holds it to the
// server's word for word, so a change to one that is not made to the other fails there.
export const STARTER = `[center]
[b]{{unit}} ROLL CALL | {{week|upper}}[/b]
[/center]

[b]Roll Call Accountability[/b]
[table]
[tr][th]#[/th][th]Name[/th]{{#fireteams_shown}}[th]Role[/th]{{/fireteams_shown}}[th]Signed RC[/th][th]Attended SP[/th][/tr]
{{#troopers}}[tr][td]{{n}}[/td][td]{{#rank_icon}}[img width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/img] {{/rank_icon}}{{rank}} {{name}}[/td]{{#fireteams_shown}}[td]{{role}}[/td]{{/fireteams_shown}}[td]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_makeup_requested}}MU{{/rc_makeup_requested}}{{#rc_excused}}EX{{/rc_excused}}{{#rc_loa}}LOA{{/rc_loa}}{{#rc_unsigned}}-{{/rc_unsigned}}[/td][td]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_excused}}EX{{/sp_excused}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/td][/tr]
{{/troopers}}{{^troopers}}[tr][td]No troopers are placed in this section.[/td][/tr]
{{/troopers}}[/table]`;
