import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Guidon from '../Guidon.vue';

const design = { primary: '#1f2a44', secondary: '#ffffff', topLeft: '2/7', bottomRight: 'A', large: '7' };
const svgOf = (wrapper) => decodeURIComponent(wrapper.find('img').attributes('src'));

describe('Guidon', () => {
    it('draws the texts and the large character from 20px up', () => {
        const svg = svgOf(mount(Guidon, { props: { design, height: 20 } }));

        expect(svg).toContain('data-text="2/7"');
        expect(svg).toContain('clipPath');
    });

    it('draws a smaller flag plain: its colors and split, no text and no large character', () => {
        for (const height of [12, 16, 19]) {
            const svg = svgOf(mount(Guidon, { props: { design, height } }));

            expect(svg).not.toContain('data-text');
            expect(svg).not.toContain('clipPath');
            expect(svg).toContain('fill="#1f2a44"');
        }
    });

    it('lets the plain prop override the height either way', () => {
        expect(svgOf(mount(Guidon, { props: { design, height: 144, plain: true } }))).not.toContain('data-text');
        expect(svgOf(mount(Guidon, { props: { design, height: 12, plain: false } }))).toContain('data-text');
    });

    it('keeps its alt text whether plain or not', () => {
        expect(mount(Guidon, { props: { design, height: 12, alt: 'Guidon of A/2-7' } }).find('img').attributes('alt')).toBe('Guidon of A/2-7');
    });
});
