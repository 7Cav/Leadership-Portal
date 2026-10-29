import { describe, expect, it } from 'vitest';
import { rollcallContext, rollcallPost } from '../rollcallBbcode';

const t = (id, name, rollcall, status, rankShort = 'PFC') => ({ id, cavId: 1000 + id, rank: 'Long Rank Title', rankShort, name, displayName: `${rankShort} ${name ?? `#${1000 + id}`}`, rollcall, status, source: null, priorStatus: null });
const SECTION = { id: 5, designation: '2/1/A/1-7', name: '2nd Section', platoon: '1/A/1-7' };
const WEEK = { start: '2026-10-03T00:00:00+00:00', closes: '2026-10-09T23:59:00+00:00' };
const TROOPERS = [
    t(1, 'Vasquez.R', 'will_attend', 'present', 'SGT'),
    t(2, 'Okafor.T', 'cannot_attend', 'missed'),
    t(3, 'Kowalczyk.P', 'cannot_attend', 'makeup'),
    t(4, 'Reyes.M', 'will_attend', 'attached', 'PVT'),
    t(5, 'Doyle.A', 'unsigned', null, 'PVT'),
    t(6, 'Tanaka.H', 'unsigned', 'loa'),
];
const input = (troopers = TROOPERS) => ({ section: SECTION, week: WEEK, troopers });
const resolved = (body, version = 3) => ({ state: 'resolved', companyId: 1, versionId: 30, version, body });

describe('rollcallContext (3.11)', () => {
    const context = rollcallContext(input());

    it('describes the section and the week in forum convention', () => {
        expect(context).toMatchObject({ unit: '2/1/A/1-7', unit_name: '2nd Section', week: '03Oct26 – 09Oct26', week_start: '03Oct26', week_end: '09Oct26' });
    });

    it('numbers the troopers by rank, senior first, then name, and takes names from the synced username', () => {
        expect(context.troopers.map((row) => [row.n, row.name])).toEqual([['1', 'Vasquez.R'], ['2', 'Kowalczyk.P'], ['3', 'Okafor.T'], ['4', 'Tanaka.H'], ['5', 'Doyle.A'], ['6', 'Reyes.M']]);
        expect(context.troopers[0]).toMatchObject({ rank: 'Long Rank Title', rank_short: 'SGT', display_name: 'SGT Vasquez.R' });
    });

    it('turns each practice status into its flag and word, LOA from the leave rather than a choice', () => {
        const by = Object.fromEntries(context.troopers.map((row) => [row.name, row]));

        expect(by['Vasquez.R']).toMatchObject({ sp: 'Yes', sp_present: true, sp_unmarked: false });
        expect(by['Okafor.T']).toMatchObject({ sp: 'No', sp_missed: true });
        expect(by['Kowalczyk.P']).toMatchObject({ sp: 'MU', sp_makeup: true });
        expect(by['Reyes.M']).toMatchObject({ sp: 'Att', sp_attached: true });
        expect(by['Doyle.A']).toMatchObject({ sp: '', sp_unmarked: true });
        expect(by['Tanaka.H']).toMatchObject({ sp: 'LOA', sp_loa: true });
    });

    it('writes LOA for a trooper whose leave covers the week, even with no stored mark or with one (3.4)', () => {
        const rows = rollcallContext(input([
            { ...t(7, 'Ito.K', 'unsigned', null), loa: { state: 'covered' } },
            { ...t(8, 'Diaz.L', 'will_attend', 'present'), loa: { state: 'covered' } },
            { ...t(9, 'Park.J', 'unsigned', null), loa: { state: 'needs_review' } },
        ])).troopers;
        // The post orders the rows itself (same rank, then by last name), so they are read by name.
        const by = Object.fromEntries(rows.map((row) => [row.name, row]));

        expect(by['Ito.K']).toMatchObject({ sp: 'LOA', sp_loa: true, sp_unmarked: false });
        expect(by['Diaz.L']).toMatchObject({ sp: 'LOA', sp_loa: true, sp_present: false, rc: 'Yes' });
        expect(by['Park.J']).toMatchObject({ sp: '', sp_loa: false, sp_unmarked: true });
    });

    it('does not count a trooper on leave as attended, even with a present mark on the page, so the post shows no tick (3.4)', () => {
        const troopers = [
            t(1, 'Vasquez.R', 'will_attend', 'present'),
            { ...t(2, 'Adams.P', 'unsigned', 'present'), loa: { state: 'covered' } },
        ];
        const out = rollcallPost(resolved('{{#troopers}}{{name}} {{#sp_present}}✓{{/sp_present}}{{#sp_loa}}LOA{{/sp_loa}}\n{{/troopers}}attended {{attended}}'), input(troopers));

        expect(rollcallContext(input(troopers)).attended).toBe('1');
        // Same rank, so by last name: Adams before Vasquez.
        expect(out.text).toBe('Adams.P LOA\nVasquez.R ✓\nattended 1');
    });

    it('turns each signature into its flag and word', () => {
        const by = Object.fromEntries(context.troopers.map((row) => [row.name, row]));

        expect(by['Vasquez.R']).toMatchObject({ rc: 'Yes', rc_yes: true, rc_unsigned: false });
        expect(by['Okafor.T']).toMatchObject({ rc: 'No', rc_no: true });
        expect(by['Doyle.A']).toMatchObject({ rc: '', rc_unsigned: true });
    });

    it('counts who signed and who attended, a makeup and an attachment included', () => {
        expect(context).toMatchObject({ count: '6', signed: '2', attended: '3' });
    });

    it('gives each trooper their rank insignia for {{rank_icon}}, only an https URL on 7cav.us, else empty', () => {
        const icon = (rankIcon) => rollcallContext(input([{ ...t(1, 'Vasquez.R', 'unsigned', null), rankIcon }])).troopers[0].rank_icon;

        expect(icon('https://7cav.us/data/roster_ranks/0/12.jpg')).toBe('https://7cav.us/data/roster_ranks/0/12.jpg');
        // The forum serves rank images with a numeric cache-busting query.
        expect(icon('https://7cav.us/data/roster_ranks/0/22.jpg?1741376831')).toBe('https://7cav.us/data/roster_ranks/0/22.jpg?1741376831');
        expect(icon('https://7cav.us/data/roster_ranks/0/22.jpg?x=1')).toBe('');
        // It is written into an [IMG] tag: another host, a closing tag or nothing at all prints nothing.
        expect(icon(null)).toBe('');
        expect(icon(undefined)).toBe('');
        expect(icon('http://7cav.us/data/roster_ranks/0/12.jpg')).toBe('');
        expect(icon('https://example.com/12.jpg')).toBe('');
        expect(icon('https://7cav.us/12.jpg[/IMG][URL=https://example.com]x[/URL]')).toBe('');
    });

    it('gives each trooper the width that shows their insignia at the same weight as every other rank, empty with no insignia', () => {
        const width = (rankIcon) => rollcallContext(input([{ ...t(1, 'Vasquez.R', 'unsigned', null), rankIcon }])).troopers[0].rank_icon_width;
        const rank = (n) => `https://7cav.us/data/roster_ranks/0/${n}.jpg?1741376831`;

        expect(width(rank(10))).toBe('5px'); // First Lieutenant: a solid bar
        expect(width(rank(29))).toBe('5px'); // Chief Warrant Officer 2
        expect(width(rank(18))).toBe('11px'); // Sergeant
        expect(width(rank(12))).toBe('8px'); // Command Sergeant Major
        expect(width(rank(22))).toBe('17px'); // Private
        expect(width(rank(1))).toBe('18px'); // General of the Army: as wide as the square allows
        // An image on 7cav.us that is not a rank insignia it knows: the square's size.
        expect(width('https://7cav.us/data/roster_ranks/0/99.jpg')).toBe('18px');
        expect(width('https://7cav.us/styles/7cav/xenforo/cav_logo.png')).toBe('18px');
        // No insignia, or one refused: no width, so {{#rank_icon_width}} is not set either.
        expect(width(null)).toBe('');
        expect(width('https://example.com/data/roster_ranks/0/18.jpg')).toBe('');
    });

    it('names a trooper whose username has not synced by milpac id', () => {
        expect(rollcallContext(input([t(1, null, 'unsigned', null)])).troopers[0]).toMatchObject({ name: '#1001' });
    });
});

describe('rollcallPost (3.11)', () => {
    it("sizes each insignia in the starter template's [IMG] tag", () => {
        const starter = '{{#troopers}}{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{name}};{{/troopers}}';
        const troopers = [{ ...t(1, 'Vasquez.R', 'unsigned', null, 'SGT'), rankIcon: 'https://7cav.us/data/roster_ranks/0/10.jpg' }, t(2, 'Okafor.T', 'unsigned', null)];

        expect(rollcallPost(resolved(starter), input(troopers)).text).toBe('[IMG width="5px" height="auto"]https://7cav.us/data/roster_ranks/0/10.jpg[/IMG] Vasquez.R;Okafor.T;');
    });

    it('renders the active template with the current marks and says which version it came from', () => {
        const post = rollcallPost(resolved('[b]{{unit}}[/b] {{signed}}/{{count}}\n{{#troopers}}{{name}}={{#sp_missed}}No{{/sp_missed}}{{^sp_missed}}ok{{/sp_missed}};{{/troopers}}'), input());

        expect(post).toEqual({
            state: 'ready',
            version: 3,
            text: '[b]2/1/A/1-7[/b] 2/6\nVasquez.R=ok;Kowalczyk.P=ok;Okafor.T=No;Tanaka.H=ok;Doyle.A=ok;Reyes.M=ok;',
        });
    });

    it('follows the marks: the same template gives a different post after a toggle', () => {
        const template = resolved('{{attended}}');
        const before = rollcallPost(template, input());
        const after = rollcallPost(template, input(TROOPERS.map((row) => (row.id === 2 ? { ...row, status: 'present' } : row))));

        expect([before.text, after.text]).toEqual(['3', '4']);
    });

    it('follows the template: a new version changes the post for the same marks', () => {
        expect(rollcallPost(resolved('one {{unit}}', 1), input()).text).toBe('one 2/1/A/1-7');
        expect(rollcallPost(resolved('two {{unit}}', 2), input())).toMatchObject({ text: 'two 2/1/A/1-7', version: 2 });
    });

    it('keeps forum markup in a name from opening a tag', () => {
        const out = rollcallPost(resolved('{{#troopers}}{{name}}{{/troopers}}'), input([t(1, '[/b][url]x[/url]', 'unsigned', null)]));

        expect(out.text).toBe('[\u200B/b\uFF3D[\u200Burl\uFF3Dx[\u200B/url\uFF3D');
    });

    it('says a company without a template has none, and writes no post of its own', () => {
        const post = rollcallPost({ state: 'no_active_template', companyId: 1, versionId: null, version: null, body: null }, input());

        expect(post).toMatchObject({ state: 'none' });
        expect(post.message).toContain('no roll call template');
    });

    it('says a section that sits under no company has no template to render from', () => {
        expect(rollcallPost({ state: 'no_owning_company', companyId: null, versionId: null, version: null, body: null }, input()).message).toContain('does not sit under a company');
    });

    it('treats a missing template prop as no template', () => {
        expect(rollcallPost(null, input())).toMatchObject({ state: 'none' });
    });

    it('reports a stored template that no longer renders, with the reason, instead of broken BBCode', () => {
        const post = rollcallPost(resolved('{{#troopers}}{{name}}', 4), input());

        expect(post.state).toBe('invalid');
        expect(post.message).toContain('version 4');
        expect(post.message).toContain('never closed');
    });

    it('renders an empty section from the template\'s own empty text', () => {
        expect(rollcallPost(resolved('{{#troopers}}{{name}}{{/troopers}}{{^troopers}}nobody{{/troopers}}'), input([])).text).toBe('nobody');
    });
});

describe('rollcallContext roles and order (the owner, 2026-10-16)', () => {
    const r = (id, name, rankShort, role = null, fireteamLeaderId = null, leadsFireteam = role === 'ftl') => ({
        ...t(id, name, 'will_attend', 'present', rankShort),
        role,
        fireteamLeaderId,
        leadsFireteam,
    });
    const SQUAD = [
        r(1, 'Able.A', 'PVT'),
        r(2, 'Ftl.B', 'CPL', 'ftl'),
        r(3, 'Asl.C', 'CPL', 'asl'),
        r(4, 'Member.D', 'SPC', null, 2),
        r(5, 'Sl.E', 'SGT', 'sl'),
        r(6, 'Senior.F', 'SGT'),
    ];
    const context = (fireteams, troopers = SQUAD) => rollcallContext({ ...input(troopers), fireteams });
    const rows = (fireteams) => context(fireteams).troopers;

    it('lists the SL, then the ASL, then everyone by rank, and names only the SL and ASL while FTLs are hidden', () => {
        expect(rows().map((row) => [row.n, row.name, row.role])).toEqual([
            ['1', 'Sl.E', 'Section Leader'], ['2', 'Asl.C', 'Assistant Section Leader'], ['3', 'Senior.F', ''], ['4', 'Ftl.B', ''], ['5', 'Member.D', ''], ['6', 'Able.A', ''],
        ]);
        expect(rows({ show: false, order: 'by_fireteam' }).map((row) => row.name)).toEqual(rows().map((row) => row.name));
        expect(context().fireteams_shown).toBe(false);
    });

    it('puts every FTL above the rest and names their fireteam, once the section shows them', () => {
        expect(rows({ show: true, order: 'leaders_first' }).map((row) => [row.name, row.role])).toEqual([
            ['Sl.E', 'Section Leader'], ['Asl.C', 'Assistant Section Leader'], ['Ftl.B', 'FTL Alpha'], ['Senior.F', ''], ['Member.D', ''], ['Able.A', ''],
        ]);
        expect(context({ show: true }).fireteams_shown).toBe(true);
    });

    it('puts each FTL directly above their fireteam, troopers in no fireteam last, with the by-fireteam order', () => {
        expect(rows({ show: true, order: 'by_fireteam' }).map((row) => row.name)).toEqual(['Sl.E', 'Asl.C', 'Ftl.B', 'Member.D', 'Senior.F', 'Able.A']);
    });

    it("names an SL or ASL who leads a fireteam by both, the SL's fireteam first, in the WW2 alphabet for B/ACD", () => {
        const squad = SQUAD.map((row) => (row.role === 'sl' || row.role === 'asl' ? { ...row, leadsFireteam: true } : row));

        expect(context({ show: true }, squad).troopers.slice(0, 3).map((row) => row.role)).toEqual([
            'Section Leader (FTL Alpha)', 'Assistant Section Leader (FTL Bravo)', 'FTL Charlie',
        ]);
        expect(context({ show: true, alphabet: 'ww2' }, squad).troopers.slice(0, 3).map((row) => row.role)).toEqual([
            'Section Leader (FTL Able)', 'Assistant Section Leader (FTL Baker)', 'FTL Charlie',
        ]);
    });

    it('sets fireteams_shown only when FTLs are shown and the section has one, so a forced-on policy with no FTL prints nothing', () => {
        const noFtl = SQUAD.filter((row) => row.role !== 'ftl');

        expect(context({ show: true }, noFtl).fireteams_shown).toBe(false);
        expect(context({ show: false }).fireteams_shown).toBe(false);
        expect(context({ show: true }).fireteams_shown).toBe(true);
    });

    it('sets the role flags: SL and ASL always, FTL only while shown', () => {
        const by = (fireteams) => Object.fromEntries(rows(fireteams).map((row) => [row.name, [row.is_sl, row.is_asl, row.is_ftl]]));

        expect(by({ show: true })).toMatchObject({ 'Sl.E': [true, false, false], 'Asl.C': [false, true, false], 'Ftl.B': [false, false, true], 'Able.A': [false, false, false] });
        expect(by({ show: false })['Ftl.B']).toEqual([false, false, false]);
    });

    it('prints the roles through a template, numbering the rows in the new order', () => {
        const body = '{{#fireteams_shown}}Teams:{{/fireteams_shown}}{{#troopers}}{{n}}.{{#role}}({{role}}){{/role}}{{name}}{{#is_ftl}}*{{/is_ftl}} {{/troopers}}';
        const out = rollcallPost(resolved(body), { ...input(SQUAD), fireteams: { show: true, order: 'by_fireteam' } });

        expect(out.text).toBe('Teams:1.(Section Leader)Sl.E 2.(Assistant Section Leader)Asl.C 3.(FTL Alpha)Ftl.B* 4.Member.D 5.Senior.F 6.Able.A ');
    });
});
