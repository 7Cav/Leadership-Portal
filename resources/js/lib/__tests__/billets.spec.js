import { describe, it, expect, vi, beforeEach } from 'vitest';
import { conflictHeading, holdersLabel, joinAnd, otherUnitsLabel, placementBadge, postBillet, sameNameSentence, sourceLabel } from '../billets';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...args) => post(...args) } }));

describe('billets', () => {
    beforeEach(() => post.mockReset());

    it('words the holders, the source and a conflict unit', () => {
        expect([holdersLabel(0), holdersLabel(1), holdersLabel(3)]).toEqual(['vacant', '1 holder', '3 holders']);
        expect(sourceLabel({ source: 'rule', rule: 'staff billet of S1' })).toBe('Rule: staff billet of S1');
        expect(sourceLabel({ source: 'rule', rule: null })).toBe('Rule');
        expect(sourceLabel({ source: 'admin' })).toBe('Placed by an Admin');
        expect(sourceLabel({ source: 'command' })).toBe('Commands this unit');
        expect([placementBadge({ source: 'admin' }), placementBadge({ source: 'rule' }), placementBadge({ source: 'command' })]).toEqual([
            'placed by Admin',
            'automatic',
            'automatic',
        ]);
    });

    it('words a billet in two units', () => {
        const units = [
            { unitId: 1, label: 'A' },
            { unitId: 2, label: 'B' },
            { unitId: 2, label: 'B' },
        ];
        expect([joinAnd(['A']), joinAnd(['A', 'B']), joinAnd(['A', 'B', 'C'])]).toEqual(['A', 'A and B', 'A, B and C']);
        expect(conflictHeading({ kind: 'title', units: units.slice(0, 2) })).toBe('Same billet name in two units');
        expect(conflictHeading({ kind: 'rule', units: [...units, { unitId: 3, label: 'C' }] })).toBe('Billet placed in several units');
        expect(sameNameSentence({ title: 'Trooper', units })).toBe('Three Cav billets are all called "Trooper": one in A, two in B.');
        expect(otherUnitsLabel({ units }, 1)).toBe('B');
    });

    it('tells a move or a billet level to confirm apart from any other refusal', () => {
        const onError = vi.fn();
        postBillet('/x', {}, { failed: 'Not placed', onError });
        const options = post.mock.calls[0][2];

        options.onError({ billet_move: 'Move it here instead?' });
        options.onError({ billet_level: 'Place it in A/1-7, a company, anyway?' });
        options.onError({ billet: 'Already there.' });
        options.onError({});
        options.onHttpException({ status: 403 });

        expect(onError.mock.calls).toEqual([
            ['Move it here instead?', 'move'],
            ['Place it in A/1-7, a company, anyway?', 'level'],
            ['Already there.', null],
            ['Not placed. Try again.', null],
            ['Not placed. You are not allowed to do that; reload the page, or exit view as first.', null],
        ]);
    });
});
