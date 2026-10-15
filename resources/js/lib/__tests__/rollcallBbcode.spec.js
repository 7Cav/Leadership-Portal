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

    it('numbers the troopers in the order the page lists them and takes names from the synced username', () => {
        expect(context.troopers.map((row) => [row.n, row.name])).toEqual([['1', 'Vasquez.R'], ['2', 'Okafor.T'], ['3', 'Kowalczyk.P'], ['4', 'Reyes.M'], ['5', 'Doyle.A'], ['6', 'Tanaka.H']]);
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

        expect(rows[0]).toMatchObject({ sp: 'LOA', sp_loa: true, sp_unmarked: false });
        expect(rows[1]).toMatchObject({ sp: 'LOA', sp_loa: true, sp_present: false, rc: 'Yes' });
        expect(rows[2]).toMatchObject({ sp: '', sp_loa: false, sp_unmarked: true });
    });

    it('does not count a trooper on leave as attended, even with a present mark on the page, so the post shows no tick (3.4)', () => {
        const troopers = [
            t(1, 'Vasquez.R', 'will_attend', 'present'),
            { ...t(2, 'Adams.P', 'unsigned', 'present'), loa: { state: 'covered' } },
        ];
        const out = rollcallPost(resolved('{{#troopers}}{{name}} {{#sp_present}}✓{{/sp_present}}{{#sp_loa}}LOA{{/sp_loa}}\n{{/troopers}}attended {{attended}}'), input(troopers));

        expect(rollcallContext(input(troopers)).attended).toBe('1');
        expect(out.text).toBe('Vasquez.R ✓\nAdams.P LOA\nattended 1');
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

    it('names a trooper whose username has not synced by milpac id', () => {
        expect(rollcallContext(input([t(1, null, 'unsigned', null)])).troopers[0]).toMatchObject({ name: '#1001' });
    });
});

describe('rollcallPost (3.11)', () => {
    it('renders the active template with the current marks and says which version it came from', () => {
        const post = rollcallPost(resolved('[b]{{unit}}[/b] {{signed}}/{{count}}\n{{#troopers}}{{name}}={{#sp_missed}}No{{/sp_missed}}{{^sp_missed}}ok{{/sp_missed}};{{/troopers}}'), input());

        expect(post).toEqual({
            state: 'ready',
            version: 3,
            text: '[b]2/1/A/1-7[/b] 2/6\nVasquez.R=ok;Okafor.T=No;Kowalczyk.P=ok;Reyes.M=ok;Doyle.A=ok;Tanaka.H=ok;',
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
        expect(post.message).toContain('no rollcall template');
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
