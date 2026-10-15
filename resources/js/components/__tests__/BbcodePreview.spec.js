import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import BbcodePreview from '../BbcodePreview.vue';

const draw = (source) => mount(BbcodePreview, { props: { source } });

// Every attribute on every element the preview drew, as "tag attr=value".
function attributes(wrapper) {
    return [...wrapper.element.querySelectorAll('*')].flatMap((el) => [...el.attributes].map((attr) => `${el.tagName.toLowerCase()} ${attr.name}=${attr.value}`));
}

describe('BbcodePreview', () => {
    it('draws the post as the forum shows it', () => {
        const wrapper = draw('[CENTER][B]1/E/2-7[/B][/CENTER]\n[I]week[/I] [U]u[/U] [S]s[/S] [COLOR=#ff0000]red[/COLOR] [SIZE=5]big[/SIZE]');

        expect(wrapper.get('div[style]').attributes('style')).toContain('text-align: center');
        expect(wrapper.get('strong').text()).toBe('1/E/2-7');
        expect(wrapper.get('em').text()).toBe('week');
        expect(wrapper.get('u').text()).toBe('u');
        expect(wrapper.get('s').text()).toBe('s');
        expect(wrapper.findAll('span[style]').map((span) => span.attributes('style'))).toEqual(['color: #ff0000;', 'font-size: 18px;']);
    });

    it('draws a table with header and data cells, and keeps a rank insignia small in its cell', () => {
        const wrapper = draw('[TABLE][TR][TH]Name[/TH][/TR][TR][TD][IMG]https://7cav.us/data/roster_ranks/0/18.jpg[/IMG] Jay.JH[/TD][/TR][/TABLE]');

        expect(wrapper.get('table th').text()).toBe('Name');
        expect(wrapper.get('table td').text()).toBe('Jay.JH');
        const img = wrapper.get('td img');
        expect(img.attributes()).toMatchObject({ src: 'https://7cav.us/data/roster_ranks/0/18.jpg', loading: 'lazy', alt: '', referrerpolicy: 'no-referrer' });
        expect(img.classes()).toContain('max-h-10');
    });

    it('opens links in a new tab with no referrer or opener', () => {
        expect(draw('[URL=https://7cav.us]Cav[/URL]').get('a').attributes()).toMatchObject({
            href: 'https://7cav.us/',
            rel: 'noopener noreferrer nofollow',
            target: '_blank',
        });
    });

    it('draws lists, quotes and rules', () => {
        const wrapper = draw('[LIST=1][*]one[*]two[/LIST][QUOTE=Jay.JH]hi[/QUOTE][HR]');

        expect(wrapper.findAll('ol li').map((li) => li.text())).toEqual(['one', 'two']);
        expect(wrapper.get('blockquote').text()).toBe('Jay.JH said:hi');
        expect(wrapper.find('hr').exists()).toBe(true);
    });

    it.each([
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '[B]"><svg onload=alert(1)>[/B]',
        '[URL=javascript:alert(1)]x[/URL]',
        '[URL]javascript:alert(1)[/URL]',
        '[IMG]javascript:alert(1)[/IMG]',
        '[IMG]https://7cav.us/a.png" onerror="alert(1)[/IMG]',
        '[COLOR=red;background:url(x)]x[/COLOR]',
        '[SIZE=3;position:fixed]x[/SIZE]',
        '[QUOTE="<img src=x onerror=alert(1)>"]x[/QUOTE]',
    ])('adds no markup, handler or address from %s', (source) => {
        const wrapper = draw(source);

        expect(wrapper.find('script, svg, iframe, a, img').exists()).toBe(false);
        expect(attributes(wrapper).filter((line) => / on[a-z]+=|javascript:|url\(|position/i.test(line))).toEqual([]);
        // What was written is still there to read, as text.
        expect(wrapper.text().length).toBeGreaterThan(0);
    });

    it('shows HTML as the text that was written', () => {
        expect(draw('<b>not bold</b>').text()).toBe('<b>not bold</b>');
        expect(draw('<b>not bold</b>').find('b').exists()).toBe(false);
    });
});
