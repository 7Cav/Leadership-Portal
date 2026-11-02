import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import RankInsignia from '../RankInsignia.vue';

describe('RankInsignia', () => {
    it('draws the self-hosted insignia in a fixed 18px square, decorative by default', () => {
        const wrapper = mount(RankInsignia, { props: { rank: 'PVT' } });
        const box = wrapper.get('[data-testid="rank-insignia"]');

        expect(box.attributes('style')).toContain('width: 18px');
        expect(box.attributes('style')).toContain('height: 18px');
        expect(wrapper.get('img').attributes('src')).toBe('/assets/ranks/insignia/PVT.png');
        expect(wrapper.get('img').attributes('alt')).toBe('');
    });

    it('names the rank when asked', () => {
        expect(mount(RankInsignia, { props: { rank: '1lt', alt: null } }).get('img').attributes('alt')).toBe('First Lieutenant');
    });

    it('draws nothing for an unknown or missing rank', () => {
        expect(mount(RankInsignia, { props: { rank: 'Sergeant' } }).find('img').exists()).toBe(false);
        expect(mount(RankInsignia, { props: { rank: null } }).find('img').exists()).toBe(false);
    });
});
