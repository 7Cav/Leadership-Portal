import { describe, expect, it } from 'vitest';
import { countsOf, displayName, fullName, initials, isLocked, landingStep, practiceGroups, sectionStatus, signatureGroups, stepsOf, weeksNeedingWork } from '../rollcall';

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
            total: 4, loa: 1, willAttend: 2, cannotAttend: 1, unsigned: 1,
            present: 1, missed: 1, makeup: 1, attached: 0, unmarked: 1,
        });
    });

    it('marks a step done only once nothing is left on it', () => {
        expect(stepsOf([t(1, 'unsigned', null)]).map((s) => s.state)).toEqual(['warn', 'warn', 'todo']);
        expect(stepsOf([t(1, 'will_attend', 'present')], '2026-10-08T16:20:00Z').map((s) => s.state)).toEqual(['done', 'done', 'done']);
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

    it('lists "cannot attend" first on the practice step, LOA last', () => {
        const groups = practiceGroups([t(1, 'will_attend', null), t(2, 'unsigned', null), t(3, 'cannot_attend', null), t(4, 'unsigned', 'loa')]);

        expect(groups.map((g) => g.id)).toEqual(['cannot', 'unsigned', 'will', 'loa']);
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
        expect(sectionStatus(progress({ started: true, unsigned: 2, unmarked: 3 }))).toEqual({ state: 'todo', text: '2 unsigned, 3 unmarked' });
        expect(sectionStatus(progress({ started: true, unsigned: 0, unmarked: 3 }))).toEqual({ state: 'todo', text: '3 unmarked' });
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
