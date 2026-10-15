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
        // A known rank insignia sits in a fixed 18px square, scaled and offset so the insignia is centred in it.
        const box = wrapper.get('td [data-rank-insignia]');
        expect(box.attributes('style')).toContain('width: 18px');
        expect(box.attributes('style')).toContain('height: 18px');
        expect(img.attributes('style')).toMatch(/left: -?[\d.]+px/);
    });

    it("centres an insignia sized by the template's {{rank_icon_width}} in the same square", () => {
        for (const open of ['[IMG width="11px" height="auto"]', '[IMG width="11px"]']) {
            const wrapper = draw(`[TABLE][TR][TD]${open}https://7cav.us/data/roster_ranks/0/18.jpg[/IMG] Jay.JH[/TD][/TR][/TABLE]`);

            expect(wrapper.get('td [data-rank-insignia]').attributes('style')).toContain('width: 18px');
            expect(wrapper.find('[data-sized-image]').exists()).toBe(false);
        }
    });

    it('draws an image at the size an Admin gave it, as the forum does, an insignia included', () => {
        const sized = (source) => draw(source).get('img[data-sized-image]');

        let img = sized('[TABLE][TR][TD][IMG width="30px" height="auto"]https://7cav.us/data/roster_ranks/0/18.jpg[/IMG][/TD][/TR][/TABLE]');
        expect(img.element.closest('[data-rank-insignia]')).toBeNull();
        expect(img.attributes('style')).toBe('width: 30px;');
        expect(img.classes()).not.toContain('max-h-10');

        img = sized('[IMG width="322px" height="1px"]https://7cav.us/a.png[/IMG]');
        expect(img.attributes('style')).toBe('width: 322px; height: 1px;');
        expect(img.attributes()).toMatchObject({ src: 'https://7cav.us/a.png', loading: 'lazy', alt: '', referrerpolicy: 'no-referrer' });

        img = sized('[IMG width="auto" height="40px"]https://7cav.us/a.png[/IMG]');
        expect(img.attributes('style')).toBe('height: 40px;');

        img = sized('[IMG width="auto" height="auto"]https://7cav.us/data/roster_ranks/0/18.jpg[/IMG]');
        expect(img.attributes('style')).toBeUndefined();
    });

    it('keeps any other image in a cell row-sized', () => {
        const img = draw('[TABLE][TR][TD][IMG]https://7cav.us/styles/7cav/xenforo/cav_logo.png[/IMG][/TD][/TR][/TABLE]').get('td img');

        expect(img.classes()).toContain('max-h-10');
        expect(img.element.closest('[data-rank-insignia]')).toBeNull();
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
        '[IMG width="1px;background:url(x)"]https://7cav.us/a.png[/IMG]',
        '[IMG width="11px" style="position:fixed"]https://7cav.us/a.png[/IMG]',
        '[IMG width="11px" onerror="alert(1)"]https://7cav.us/a.png[/IMG]',
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
