import { describe, expect, it } from 'vitest';
import {
    captureSourceLabel,
    countsOf,
    displayName,
    feedLabel,
    fullName,
    initials,
    isLocked,
    landingStep,
    makeupOwedList,
    optionsFor,
    owesMakeup,
    placementNote,
    practiceGroups,
    practiceLabel,
    practiceOf,
    PRACTICE,
    sectionStatus,
    SIGNATURES,
    signatureGroups,
    signatureLabel,
    signatureOf,
    stepsOf,
    unlinkedLabel,
    weeksNeedingWork,
} from '../rollcall';

const t = (id, rollcall, status, extra = {}) => ({ id, cavId: 1000 + id, rank: 'PFC', name: `Trooper${id}.A`, rollcall, status, source: null, priorStatus: null, ...extra });

describe('rollcall vocabulary', () => {
    it('names a trooper whose username has not synced by milpac id, never an invented name', () => {
        const nameless = t(1, 'unsigned', null, { name: null, rank: null });

        expect(displayName(nameless)).toBe('#1001');
        expect(fullName(nameless)).toBe('#1001');
        expect(fullName(t(3, 'unsigned', null, { name: 'Okafor.T', rank: 'Private First Class', rankShort: 'PFC' }))).toBe('PFC Okafor.T');
        expect(fullName(t(3, 'unsigned', null, { displayName: 'PFC Okafor.T' }))).toBe('PFC Okafor.T');
        expect(initials(nameless)).toBe('#');
        expect(fullName(t(2, 'unsigned', null, { name: 'Vasquez.R', rank: 'Sergeant' }))).toBe('Sergeant Vasquez.R');
        expect(initials(t(2, 'unsigned', null, { name: 'Vasquez.R' }))).toBe('VR');
    });

    it('counts LOA rows apart from everyone expected to sign and attend', () => {
        const counts = countsOf([
            t(1, 'will_attend', 'present'),
            t(2, 'cannot_attend', 'missed'),
            t(3, 'unsigned', null),
            t(4, 'will_attend', 'makeup'),
            t(5, 'unsigned', 'loa'),
        ]);

        expect(counts).toEqual({
            total: 4, loa: 1, willAttend: 2, cannotAttend: 1, makeupRequested: 0, excusedSignature: 0, unsigned: 1,
            present: 1, missed: 1, excused: 0, makeup: 1, unmarked: 1, makeupOwed: 0,
        });
    });

    it('labels the buttons in the words of 2026-10-30, in the order the number keys follow', () => {
        expect(SIGNATURES.map((o) => o.label)).toEqual(['Will attend', 'Will not attend', 'Make-up requested', 'Excused', 'Unsigned']);
        expect(PRACTICE.map((o) => o.label)).toEqual(['Attended', 'Not attended', 'Excused', 'Make-up complete']);
        expect(optionsFor('practice').map((o) => o.value)).toEqual(['present', 'missed', 'excused', 'makeup']);
        expect(PRACTICE.some((o) => o.value === 'attached')).toBe(false);
        // Excused shares the warning tone with LOA.
        expect(SIGNATURES.find((o) => o.value === 'excused').tone).toBe('warn');
        expect(PRACTICE.find((o) => o.value === 'excused').tone).toBe('warn');
        expect(signatureLabel('cannot_attend')).toBe('Will not attend');
        expect(signatureLabel('makeup_requested')).toBe('Make-up requested');
        expect(practiceLabel('makeup')).toBe('Make-up complete');
        expect(practiceLabel('present')).toBe('Attended');
        expect(practiceLabel('missed')).toBe('Not attended');
    });

    it('reads an automatic Excused on whichever half nobody recorded, and lets a leader override it', () => {
        const fresh = t(1, 'unsigned', null, { excused: 'transfer' });
        const signed = t(2, 'will_attend', null, { excused: 'recruit' });
        const marked = t(3, 'unsigned', 'present', { source: 'manual', excused: 'transfer' });
        const autoMiss = t(4, 'unsigned', 'missed', { source: 'auto', excused: 'transfer' });
        const manualMiss = t(5, 'unsigned', 'missed', { source: 'manual', excused: 'transfer' });

        expect([signatureOf(fresh), practiceOf(fresh)]).toEqual(['excused', 'excused']);
        expect([signatureOf(signed), practiceOf(signed)]).toEqual(['will_attend', 'excused']);
        expect([signatureOf(marked), practiceOf(marked)]).toEqual(['excused', 'present']);
        expect(practiceOf(autoMiss)).toBe('excused');
        expect(practiceOf(manualMiss)).toBe('missed');
        // Excused counts on its own: neither unsigned nor unmarked.
        expect(countsOf([fresh, signed])).toMatchObject({ unsigned: 0, unmarked: 0, excused: 2, excusedSignature: 1, willAttend: 1 });
        expect(signatureGroups([fresh]).map((g) => g.id)).toEqual(['excused']);
    });

    it('keeps an automatically excused trooper out of "Needs a look" and the landing step', () => {
        const fresh = t(1, 'unsigned', null, { excused: 'transfer' });

        expect(practiceGroups([fresh, t(2, 'will_attend', 'present')], true)).toEqual([]);
        expect(landingStep([fresh, t(2, 'will_attend', 'present')], true)).toBe(3);
    });

    it('counts make-up requested as signed and lists the trooper as owing until a make-up is recorded', () => {
        const owing = t(1, 'makeup_requested', null);
        const missed = t(2, 'makeup_requested', 'missed');
        const done = t(3, 'makeup_requested', 'makeup');
        const attended = t(4, 'makeup_requested', 'present');

        expect(countsOf([owing, missed, done, attended])).toMatchObject({ unsigned: 0, makeupRequested: 4, makeupOwed: 2 });
        expect([owing, missed, done, attended].map(owesMakeup)).toEqual([true, true, false, false]);
        expect(stepsOf([done])[0].state).toBe('done');
        // Still open on the practice step until the make-up is in.
        expect(practiceGroups([owing, missed, done], true).flatMap((g) => g.troopers.map((x) => x.id))).toEqual([1, 2]);
        expect(practiceGroups([owing]).map((g) => g.id)).toEqual(['makeup']);
    });

    it('merges the server\'s earlier owed weeks with this week read live from the cards', () => {
        const server = [{ trooperId: 1, weeks: ['2026-09-19', '2026-10-03'] }, { trooperId: 2, weeks: ['2026-10-03'] }];
        const troopers = [t(1, 'will_attend', 'present'), t(2, 'makeup_requested', 'makeup'), t(3, 'makeup_requested', null)];

        expect(makeupOwedList(troopers, server, '2026-10-03').map((row) => [row.trooper.id, row.weeks])).toEqual([
            [1, ['2026-09-19']],
            [3, ['2026-10-03']],
        ]);
    });

    it('marks a step done only once nothing is left on it', () => {
        expect(stepsOf([t(1, 'unsigned', null)]).map((s) => s.state)).toEqual(['warn', 'warn', 'todo']);
        expect(stepsOf([t(1, 'will_attend', 'present')], '2026-10-08T16:20:00Z').map((s) => s.state)).toEqual(['done', 'done', 'done']);
    });

    it('keeps the practice step a warning, with the count, while players are unlinked (#176)', () => {
        const steps = stepsOf([t(1, 'will_attend', 'present')], null, 3);

        expect(steps.map((s) => s.state)).toEqual(['done', 'warn', 'todo']);
        expect(steps[1].unlinked).toBe(3);
        expect(stepsOf([t(1, 'will_attend', 'present')])[1].unlinked).toBe(0);
        expect(unlinkedLabel(1)).toBe('1 unmatched player');
        expect(unlinkedLabel(3)).toBe('3 unmatched players');
    });

    it('names the feed that is down by game and source (#176)', () => {
        expect(feedLabel({ platformLabel: 'Hell Let Loose', feeds: ['crcon', 'battlemetrics'] })).toBe('Hell Let Loose server (CRCON, BattleMetrics)');
        expect(feedLabel({ platformLabel: 'WARDOGS', feeds: ['nightwatch'] })).toBe('WARDOGS server (Nightwatch)');
        expect(feedLabel({ platformLabel: 'Arma Reforger', feeds: [] })).toBe('Arma Reforger server');
        expect(feedLabel({ platformLabel: null, feeds: [] })).toBe('Game server');
    });

    it('names every feed a capture read from, and "the game server" when none is recorded (#389)', () => {
        expect(captureSourceLabel('crcon')).toBe('CRCON');
        expect(captureSourceLabel('crcon,battlemetrics')).toBe('CRCON + BattleMetrics');
        expect(captureSourceLabel(null)).toBe('the game server');
    });

    it('opens on the first step with work left', () => {
        expect(landingStep([t(1, 'unsigned', null), t(2, 'will_attend', 'present')], true)).toBe(1);
        expect(landingStep([t(1, 'will_attend', null), t(2, 'cannot_attend', 'missed')], true)).toBe(2);
        expect(landingStep([t(1, 'will_attend', 'present'), t(2, 'cannot_attend', 'missed')], true)).toBe(3);
    });

    it('skips the signatures once the thread has closed', () => {
        expect(landingStep([t(1, 'unsigned', null)], false)).toBe(2);
        expect(landingStep([t(1, 'unsigned', 'missed')], false)).toBe(3);
    });

    it('leaves LOA rows out of the work left, and opens an empty roster on step 1', () => {
        expect(landingStep([t(1, 'unsigned', 'loa'), t(2, 'will_attend', 'present')], true)).toBe(3);
        expect(landingStep([], true)).toBe(1);
    });

    it('splits the past weeks still needing work around the shown week, newest first', () => {
        const status = [
            { date: '2026-09-26', unmarked: 0, posted: true },
            { date: '2026-09-19', unmarked: 2, posted: false },
            { date: '2026-09-12', unmarked: 0, posted: false },
            { date: '2026-09-05', unmarked: 1, posted: true },
        ];

        const fromCurrent = weeksNeedingWork(status, '2026-10-03');
        expect(fromCurrent.earlier.map((w) => [w.date, w.what])).toEqual([
            ['2026-09-19', '2 not marked, not posted'],
            ['2026-09-12', 'not posted'],
            ['2026-09-05', '1 not marked'],
        ]);
        expect(fromCurrent.later).toEqual([]);

        const fromPast = weeksNeedingWork(status, '2026-09-12');
        expect(fromPast.earlier.map((w) => w.date)).toEqual(['2026-09-05']);
        expect(fromPast.later.map((w) => w.date)).toEqual(['2026-09-19']);
        expect(weeksNeedingWork([], '2026-10-03')).toEqual({ earlier: [], later: [] });
    });

    it('lists "will not attend" and "make-up requested" first on the practice step, LOA last', () => {
        const groups = practiceGroups([
            t(1, 'will_attend', null),
            t(2, 'unsigned', null),
            t(3, 'cannot_attend', null),
            t(4, 'unsigned', 'loa'),
            t(5, 'makeup_requested', null),
            t(6, 'excused', 'excused'),
        ]);

        expect(groups.map((g) => g.id)).toEqual(['cannot', 'makeup', 'unsigned', 'will', 'excused', 'loa']);
        expect(groups[0].title).toBe('Said they will not attend');
    });

    it('narrows the practice step to the rows that still need a look', () => {
        const groups = practiceGroups([t(1, 'will_attend', 'present'), t(2, 'will_attend', 'missed'), t(3, 'will_attend', null), t(4, 'unsigned', 'loa')], true);

        expect(groups.flatMap((g) => g.troopers.map((x) => x.id))).toEqual([2, 3]);
    });

    it('locks a trooper whose leave covers the week, from the server coverage rather than a stored mark (3.4)', () => {
        const covered = t(1, 'unsigned', null, { loa: { state: 'covered' } });
        const review = t(2, 'unsigned', null, { loa: { state: 'needs_review' } });
        const troopers = [covered, review, t(3, 'will_attend', null)];

        expect(isLocked(covered)).toBe(true);
        expect(isLocked(review)).toBe(false);
        // An older stored `loa` mark still locks its row.
        expect(isLocked(t(4, 'unsigned', 'loa'))).toBe(true);

        expect(signatureGroups(troopers).find((g) => g.id === 'loa').troopers).toEqual([covered]);
        expect(practiceGroups(troopers).find((g) => g.id === 'loa').troopers).toEqual([covered]);
        // A needs-review leave asserts nothing (AD-4): that row stays with everyone still to mark.
        expect(practiceGroups(troopers).find((g) => g.id === 'unsigned').troopers).toEqual([review]);
        expect(countsOf(troopers)).toMatchObject({ total: 2, loa: 1, unsigned: 1, unmarked: 2 });
        expect(stepsOf([covered, t(3, 'will_attend', 'present')]).map((s) => s.state)).toEqual(['done', 'done', 'todo']);
    });

    it('lists the unsigned first on the signature step and drops empty groups', () => {
        expect(signatureGroups([t(1, 'will_attend', null), t(2, 'unsigned', null)]).map((g) => g.id)).toEqual(['unsigned', 'will']);
    });
});

describe('section status for the picker', () => {
    const progress = (extra = {}) => ({ strength: 5, onLoa: 0, unsigned: 5, unmarked: 5, started: false, posted: false, ...extra });

    it('says posted once the rollcall is marked posted', () => {
        expect(sectionStatus(progress({ posted: true, started: true, unsigned: 1 }))).toEqual({ state: 'done', text: 'Posted' });
    });

    it('says not started when nobody has signed or been marked', () => {
        expect(sectionStatus(progress())).toEqual({ state: 'todo', text: 'Not started' });
    });

    it('counts what is left, signatures first', () => {
        expect(sectionStatus(progress({ started: true, unsigned: 2, unmarked: 3 }))).toEqual({ state: 'todo', text: '2 unsigned, 3 not marked' });
        expect(sectionStatus(progress({ started: true, unsigned: 0, unmarked: 3 }))).toEqual({ state: 'todo', text: '3 not marked' });
        expect(sectionStatus(progress({ started: true, unsigned: 1, unmarked: 0 }))).toEqual({ state: 'todo', text: '1 unsigned' });
    });

    it('says not posted when everything is in but the post is not', () => {
        expect(sectionStatus(progress({ started: true, unsigned: 0, unmarked: 0 }))).toEqual({ state: 'todo', text: 'Not posted' });
    });

    it('does not call a section where everyone is on LOA not started', () => {
        expect(sectionStatus(progress({ onLoa: 5, unsigned: 0, unmarked: 0 }))).toEqual({ state: 'todo', text: 'Not posted' });
    });

    it('says when a section has no troopers, and nothing without progress', () => {
        expect(sectionStatus(progress({ strength: 0, unsigned: 0, unmarked: 0 }))).toEqual({ state: 'none', text: 'No troopers' });
        expect(sectionStatus(undefined)).toBeNull();
    });
});

describe('placement note on a past week', () => {
    it('labels a trooper who moved out, left the Cav or joined partway through the week, and nobody else', () => {
        expect(placementNote('moved_out')?.label).toBe('Moved out');
        expect(placementNote('departed')?.label).toBe('Departed');
        expect(placementNote('joined')?.label).toBe('Joined');
        expect(placementNote(null)).toBeNull();
        expect(placementNote(undefined)).toBeNull();
    });
});
