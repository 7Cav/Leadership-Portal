import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LoaSyncNote from '../LoaSyncNote.vue';

// `LoaSyncState::toArray()` (Story 4.3): where leave comes from and when it last synced (AD-20).
const sync = (over = {}) => ({
    source: 'Forum thread',
    lastSyncedAt: '2026-10-08T09:14:00+00:00',
    stale: false,
    neverSynced: false,
    latestStatus: 'success',
    latestFinishedAt: '2026-10-08T09:14:00+00:00',
    ...over,
});

const note = (over) => mount(LoaSyncNote, { props: { sync: sync(over) } }).get('[data-testid="loa-sync"]');

describe('LoaSyncNote', () => {
    it('names the leave source and its last sync in UTC', () => {
        const el = note();

        expect(el.text()).toBe('Leave from Forum thread, synced 08Oct26 09:14Z');
        expect(el.attributes('data-state')).toBe('ok');
        expect(el.attributes('role')).toBeUndefined();
    });

    it.each([
        ['a failed latest sync', { stale: true, latestStatus: 'failure' }, 'Leave from Forum thread: the latest sync did not succeed, so on LOA and at-risk use the last good sync, 08Oct26 09:14Z'],
        ['a partial latest sync', { stale: true, latestStatus: 'partial' }, 'Leave from Forum thread: the latest sync did not succeed, so on LOA and at-risk use the last good sync, 08Oct26 09:14Z'],
        ['an overdue sync', { stale: true }, 'Leave from Forum thread, last synced 08Oct26 09:14Z, overdue. On LOA and at-risk may be out of date'],
        ['no sync yet', { lastSyncedAt: null, neverSynced: true, latestStatus: null }, 'Leave from Forum thread has not synced yet, so nobody shows as on LOA'],
        ['only failed syncs', { lastSyncedAt: null, neverSynced: true, stale: true, latestStatus: 'failure' }, 'Leave from Forum thread: the sync failed and has never succeeded, so nobody shows as on LOA'],
    ])('says so plainly, as a status, for %s', (_label, over, text) => {
        const el = note(over);

        expect(el.text()).toBe(text);
        expect(el.attributes('data-state')).toBe('problem');
        expect(el.attributes('role')).toBe('status');
    });

    it('adds how far back the first read of the year has got while it is still going, as a note and not a warning', () => {
        const el = note({ backfilling: true, backfilledTo: '2026-04-02T08:00:00+00:00' });

        expect(el.text()).toBe('Leave from Forum thread, synced 08Oct26 09:14Z. Older leave is still being read, back to 02Apr26 so far');
        expect(el.attributes('data-state')).toBe('ok');
    });

    it('says it is still going before it has reached any date, and keeps a warning that applies', () => {
        expect(note({ backfilling: true, backfilledTo: null }).text()).toBe('Leave from Forum thread, synced 08Oct26 09:14Z. Older leave is still being read');
        expect(note({ backfilling: true, backfilledTo: '2026-04-02T08:00:00+00:00', stale: true, latestStatus: 'partial' }).text()).toBe(
            'Leave from Forum thread: the latest sync did not succeed, so on LOA and at-risk use the last good sync, 08Oct26 09:14Z. Older leave is still being read, back to 02Apr26 so far',
        );
    });
});
