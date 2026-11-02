import { describe, expect, it } from 'vitest';
import { RANK_ORDER } from '@/lib/roster';
import { rankAvatarUrl } from '@/lib/rankAvatar';

describe('rankAvatarUrl', () => {
    it('maps a rank to its self-hosted avatar', () => {
        expect(rankAvatarUrl('1LT')).toBe('/assets/ranks/1LT.png');
        expect(rankAvatarUrl('CW2')).toBe('/assets/ranks/CW2.png');
        expect(rankAvatarUrl(' pfc ')).toBe('/assets/ranks/PFC.png');
    });

    it('covers every known rank but RCT', () => {
        const missing = RANK_ORDER.filter((rank) => rankAvatarUrl(rank) === null);
        expect(missing).toEqual(['RCT']);
    });

    it('has none for recruits, unknown or absent ranks', () => {
        for (const rank of ['RCT', 'CIV', '', null, undefined]) expect(rankAvatarUrl(rank)).toBeNull();
    });
});
