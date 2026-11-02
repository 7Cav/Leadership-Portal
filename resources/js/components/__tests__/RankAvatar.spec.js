import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import RankAvatar from '../RankAvatar.vue';

// Reka only shows the image once the browser says it loaded; jsdom never does, so load every image at once.
let RealImage;
beforeEach(() => {
    RealImage = window.Image;
    window.Image = class {
        set src(value) {
            this._src = value;
            setTimeout(() => this.onload?.());
        }
        get src() {
            return this._src;
        }
        addEventListener(type, fn) {
            if (type === 'load') this.onload = fn;
        }
        removeEventListener() {}
    };
});
afterEach(() => {
    window.Image = RealImage;
});

async function loaded(props) {
    const wrapper = mount(RankAvatar, { props });
    await new Promise((resolve) => setTimeout(resolve, 10));
    await nextTick();

    return wrapper;
}

describe('RankAvatar', () => {
    it('draws the rank avatar, decorative by default', async () => {
        const img = (await loaded({ rank: 'pvt', initials: 'HY' })).get('img[data-testid="rank-avatar"]');

        expect(img.attributes('src')).toBe('/assets/ranks/PVT.png');
        expect(img.attributes('alt')).toBe('');
    });

    it('names the rank when it is not written beside it', async () => {
        const wrapper = await loaded({ rank: 'SGT', initials: 'HY', alt: null });

        expect(wrapper.get('img').attributes('alt')).toBe('Sergeant');
    });

    it('shows the initials for a rank without an avatar', async () => {
        const wrapper = await loaded({ rank: 'RCT', initials: 'HY' });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-slot="avatar-fallback"]').text()).toBe('HY');
    });

    it('keeps a fixed size for each size', () => {
        expect(mount(RankAvatar, { props: { rank: null, size: 'sm' } }).classes()).toContain('size-8');
        expect(mount(RankAvatar, { props: { rank: null } }).classes()).toContain('size-10');
        expect(mount(RankAvatar, { props: { rank: null, size: 'lg' } }).classes()).toContain('size-12');
    });
});
