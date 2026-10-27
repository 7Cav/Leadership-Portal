import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import RankBadge from '../RankBadge.vue';

enableAutoUnmount(afterEach);

const classes = (rank) => mount(RankBadge, { props: { rank } }).get('span').classes();

describe('RankBadge', () => {
    it('fills commissioned officers solid', () => {
        expect(classes('CPT')).toContain('bg-foreground');
        expect(classes('2LT')).toContain('bg-foreground');
    });

    it('outlines warrant officers like NCOs instead of filling them', () => {
        for (const rank of ['WO1', 'CW2', 'CW3', 'CW4', 'CW5']) {
            expect(classes(rank)).not.toContain('bg-foreground');
            expect(classes(rank)).toEqual(expect.arrayContaining(['border-foreground/30', 'bg-card', 'text-foreground']));
        }
        expect(classes('SGT')).toEqual(expect.arrayContaining(['border-foreground/30', 'bg-card']));
    });

    it('mutes enlisted ranks', () => {
        expect(classes('PFC')).toContain('bg-muted/60');
    });
});
