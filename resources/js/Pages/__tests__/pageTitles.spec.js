import { describe, expect, it } from 'vitest';

// Every page's browser tab title reads "<Page> · 7Cav Leadership Portal". The `<Head>` titles are
// checked in the page sources, so a page added later is covered without mounting it.

const sources = import.meta.glob('../**/*.vue', { query: '?raw', import: 'default', eager: true });

// The title attribute of a page's `<Head>`, static (`title="..."`) or bound (`:title="..."`).
function headTitle(source) {
    const match = source.match(/<Head\s+(:?)title="([^"]*)"/);

    return match ? { bound: match[1] === ':', value: match[2] } : null;
}

// The outer string literals in a bound title expression: each branch the title can take.
function literals(expression) {
    return [...expression.matchAll(/`[^`]*`|'[^']*'/g)].map((m) => m[0].slice(1, -1));
}

const pages = Object.entries(sources)
    .map(([path, source]) => [path.replace('../', ''), headTitle(source)])
    .filter(([, title]) => title !== null);

describe('page titles', () => {
    it('finds the pages that set a title', () => {
        expect(pages.map(([path]) => path)).toEqual(expect.arrayContaining(['Home.vue', 'Roster.vue', 'TrooperDetail.vue', 'Auth/Login.vue']));
    });

    it.each(pages)('%s ends its title with the portal name', (path, title) => {
        const branches = title.bound ? literals(title.value) : [title.value];

        expect(branches.length).toBeGreaterThan(0);
        for (const branch of branches) {
            expect(branch).toMatch(/^.+ · 7Cav Leadership Portal$/);
        }
    });
});
