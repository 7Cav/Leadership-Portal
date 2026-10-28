import { describe, expect, it } from 'vitest';
import { NAV_GROUPS } from '../navigation';

// #411: every screen in the sidebar has a guide the "?" help opens on it, or a written decision that it has none. A
// new nav item fails here until its pull request adds a guide (front matter `page:`) or lists it below. The guides are
// read straight from resources/docs, so this follows the real files.

const sources = import.meta.glob('../../../docs/**/*.md', { query: '?raw', import: 'default', eager: true });

// The `page:` of every guide's front matter.
const guidedPages = Object.values(sources)
    .map((source) => source.replace(/\r\n/g, '\n').match(/^---\n([\s\S]*?)\n---/)?.[1] ?? '')
    .map((frontMatter) => frontMatter.match(/^page:\s*(\S+)\s*$/m)?.[1])
    .filter(Boolean);

// Screens that will never have a guide of their own, and why.
const NO_GUIDE = {
    '/docs': 'the docs themselves',
    '/settings/demo-data': 'a testing tool, never on in production',
};

// Screens whose guide is still being written by the content tickets (#414 to #417). Remove a line when its guide
// lands; the test fails if a line stays after its guide exists, so this list only shrinks.
const AWAITING_GUIDE = [
    '/home',
    '/settings/servers',
];

const items = NAV_GROUPS.flatMap((group) => group.items).filter((item) => item.href);

// A guide covers a nav item when its page is the item's path or a page beneath it (`/reports/{unit}/{week}`).
const covered = (href) => guidedPages.some((page) => page === href || page.startsWith(`${href}/`));

describe('guide coverage', () => {
    it.each(items.map((item) => [item.label, item.href]))('%s (%s) has a guide, or a decision not to have one', (label, href) => {
        expect(covered(href) || href in NO_GUIDE || AWAITING_GUIDE.includes(href)).toBe(true);
    });

    it('lists no screen as awaiting a guide once its guide exists', () => {
        expect(AWAITING_GUIDE.filter(covered)).toEqual([]);
    });

    it('lists only screens that are in the sidebar', () => {
        const hrefs = items.map((item) => item.href);

        expect([...Object.keys(NO_GUIDE), ...AWAITING_GUIDE].filter((href) => !hrefs.includes(href))).toEqual([]);
    });
});
