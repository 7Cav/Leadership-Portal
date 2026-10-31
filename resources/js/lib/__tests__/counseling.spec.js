import { describe, expect, it } from 'vitest';
import {
    COUNSELING_TYPES,
    consequenceBadges,
    counselingFileName,
    documentPayload,
    draftPayload,
    fieldLimit,
    forecastFlags,
    leaderForm,
    letterDate,
    recordBadge,
    shareChecklist,
    timeLeft,
    todayUtc,
    zulu,
} from '../counseling';

// #381, #565: the words and small rules of the counseling pages.

describe('counseling', () => {
    it('names both records the way the regulations do, with their form', () => {
        expect(COUNSELING_TYPES.dcs).toMatchObject({ short: 'DCS', label: 'Developmental Counseling Statement', form: '7CAV-R-042' });
        expect(COUNSELING_TYPES.lor).toMatchObject({ short: 'LOR', label: 'Letter of Reprimand', form: '7CAV-R-043' });
    });

    it('picks the forecast range the filing date falls in, edges included', () => {
        const repeat = { key: 'dcs-repeat', message: 'Repeat within 90 days of a DCS.' };
        const forecast = {
            dcs: {
                conduct: [
                    { from: '1970-01-01', flags: [] },
                    { from: '2026-08-01', flags: [repeat] },
                    { from: '2026-10-31', flags: [] },
                ],
            },
        };

        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-07-31')).toEqual([]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-08-01')).toEqual([repeat]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-10-30')).toEqual([repeat]);
        expect(forecastFlags(forecast, 'dcs', 'conduct', '2026-10-31')).toEqual([]);
        expect(forecastFlags(forecast, 'dcs', '', '2026-08-01')).toEqual([]);
        expect(forecastFlags(undefined, 'dcs', 'conduct', '2026-08-01')).toEqual([]);
    });

    it('takes today on the UTC clock', () => {
        expect(todayUtc(new Date('2026-10-31T23:30:00-02:00'))).toBe('2026-11-01');
    });

    it('writes dates and instants the way the form and the Cav do', () => {
        expect(letterDate('2026-10-29')).toBe('29 Oct 2026');
        expect(letterDate('2026-09-04')).toBe('4 Sep 2026');
        expect(letterDate(null)).toBe('');
        expect(zulu('2026-10-31T19:00:00Z')).toBe('31 Oct 1900Z');
        expect(zulu(null)).toBe('');
    });

    it('counts down in days and hours, then hours and minutes, and stops once passed', () => {
        const now = Date.parse('2026-10-30T14:00:00Z');

        expect(timeLeft('2026-10-31T18:30:00Z', now)).toBe('1 day 4 h');
        expect(timeLeft('2026-11-02T14:00:00Z', now)).toBe('3 days');
        expect(timeLeft('2026-10-31T11:40:00Z', now)).toBe('21 h 40 min');
        expect(timeLeft('2026-10-30T14:35:00Z', now)).toBe('35 min');
        expect(timeLeft('2026-10-30T13:59:00Z', now)).toBeNull();
        expect(timeLeft(null, now)).toBeNull();
    });

    it('gives each text field the server cap for its kind', () => {
        const limits = { long: 3000, plan: 2000, short: 500, comment: 1000, reason: 2000 };

        expect(fieldLimit('infraction', limits)).toBe(3000);
        expect(fieldLimit('regulation', limits)).toBe(500);
        expect(fieldLimit('plan_trooper', limits)).toBe(2000);
        expect(fieldLimit('comments_other', limits)).toBe(1000);
        expect(fieldLimit('appeal_reason', limits)).toBe(2000);
    });

    it('starts the draft form complete from whatever the record stored', () => {
        const form = leaderForm({ infraction: 'x', consequences: ['nfa', 'bogus'], removal: 'nope' });

        expect(form.infraction).toBe('x');
        expect(form.regulation).toBe('');
        expect(form.consequences).toEqual(['nfa']);
        expect(form.removal).toBeNull();
    });

    it('sends the whole draft, without the LOR fields on a DCS and a removal only with its consequence', () => {
        const form = { ...leaderForm(), infraction: 'x', consequences: ['article_15', 'nfa'], removal: 'section_leader', special_punishments: 'y' };

        expect(draftPayload({ type: 'dcs', kind: 'conduct', counseled_on: '', meeting_confirmed: false, form })).toEqual({
            type: 'dcs',
            kind: 'conduct',
            counseled_on: null,
            meeting_confirmed: false,
            form: { infraction: 'x', regulation: '', discussion: '', plan_authority: '', plan_other: '', comments_authority: '', comments_other: '' },
        });
        const lor = draftPayload({ type: 'lor', kind: 'conduct', counseled_on: '2026-10-29', meeting_confirmed: true, form });
        expect(lor.form).toMatchObject({ consequences: ['nfa', 'article_15'], removal: null, special_punishments: 'y' });
        expect(draftPayload({ type: 'lor', kind: 'conduct', form: { ...form, consequences: ['removal'] } }).form.removal).toBe('section_leader');
    });

    it('maps live values onto the form as the server does, Section 1 named after the form', () => {
        const section1 = { trooper: { rank: 'PFC', name: 'Okafor.D', billet: 'Rifleman' }, authority: { rank: '2LT', name: 'Varga.T', billet: 'PL' } };
        const dcs = documentPayload({ type: 'dcs', date: '2026-10-29', section1, form: { infraction: 'x', consequences: ['nfa'] } });

        expect(dcs.text['Date of Letter']).toBe('29 Oct 2026');
        expect(dcs.text['RankCounselling Authority']).toBe('2LT');
        expect(dcs.checks).toEqual([]);
        expect(dcs.radios).toEqual([]);

        const lor = documentPayload({ type: 'lor', date: null, section1, form: { consequences: ['removal'], removal: 'section_leader', appeal: 'do_not' }, outcome: 'not_amended' });
        expect(lor.text['Name LastFDisciplining Authority']).toBe('Varga.T');
        expect(lor.text['Date of Letter']).toBe('');
        expect(lor.checks).toEqual(['Check Box2']);
        expect(lor.radios).toEqual([
            { group: 'Group2', pick: 'top' },
            { group: 'Group4', pick: 'right' },
            { group: 'Group3', pick: 'right' },
        ]);
    });

    it('names the file as the server does', () => {
        expect(counselingFileName('dcs', '2026-10-29', { id: 3, name: 'Okafor.D' })).toBe('DCS 2026-10-29 Okafor.D.pdf');
        expect(counselingFileName('lor', null, { id: 3, name: '' })).toBe('LOR draft trooper-3.pdf');
    });

    it('lists the recommended consequence as short badges, naming the removal', () => {
        expect(consequenceBadges({ consequences: ['removal', 'nfa'], removal: 'assistant_section_leader' })).toEqual(['30 days no favorable action', 'Removal from ASL']);
        expect(consequenceBadges({})).toEqual([]);
    });

    it('builds the share checklist from what the server says is missing', () => {
        const items = shareChecklist('lor', { invite: 'Send the meeting invite.', removal: 'Choose which position the removal is from.' });

        expect(items.map((item) => item.key)).toEqual(['invite', 'infraction', 'regulation', 'counseled_on', 'discussion', 'plan_authority', 'meeting_confirmed', 'consequences']);
        expect(items.filter((item) => !item.done).map((item) => item.key)).toEqual(['invite', 'consequences']);
        expect(items.at(-1).message).toBe('Choose which position the removal is from.');
        expect(shareChecklist('dcs', []).every((item) => item.done)).toBe(true);
        expect(shareChecklist('dcs', []).map((item) => item.key)).not.toContain('invite');
        expect(shareChecklist('dcs', { other: 'Something else.' }).at(-1)).toMatchObject({ key: 'other', done: false });
    });

    it('gives each record one badge for where it stands', () => {
        const badge = (record) => recordBadge({ type: 'dcs', state: 'shared', ...record }).label;

        expect(badge({ state: 'draft' })).toBe('Draft');
        expect(badge({ state: 'draft', withdrawn: { reason: 'x' } })).toBe('Withdrawn to draft');
        expect(badge({ state: 'invited' })).toBe('Invited');
        expect(badge({ dcsPhase: 'active' })).toBe('Active');
        expect(badge({ dcsPhase: 'inactive' })).toBe('Inactive');
        expect(badge({ dcsPhase: 'expired' })).toBe('Expired');
        expect(badge({ needsReview: true, dcsPhase: 'active' })).toBe('Imported, needs review');
        expect(badge({ type: 'lor', state: 'no_response' })).toBe('No response');
        expect(badge({ type: 'lor', state: 'appealed' })).toBe('Appealed');
        expect(badge({ type: 'lor', state: 'decided' })).toBe('Decided');
        expect(badge({ type: 'lor', state: 'overturned', overturned: { reason: 'x' } })).toBe('Overturned');
        expect(badge({ type: 'lor', source: 'import' })).toBe('Imported');
    });
});
