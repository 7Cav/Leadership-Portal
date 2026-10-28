import { describe, expect, it } from 'vitest';
import { audienceLabel, cameFrom, homeGroups, openablePage, roleLabel } from '../docs';

const guide = (title, audience, weekly = false) => ({ title, summary: '', url: `/docs/x/${title}`, audience, weekly });

describe('homeGroups', () => {
    const contents = [
        { key: 'basics', title: 'The basics', guides: [guide('roster', 'everyone')] },
        { key: 'weekly-rollcall', title: 'Weekly rollcall', guides: [guide('run', 'leader', true), guide('guests', 'leader')] },
        { key: 'admin', title: 'Admin', guides: [guide('games', 'admin')] },
    ];

    it('sorts the guides into Every week, When it comes up, For everyone and Admin', () => {
        const groups = homeGroups(contents);

        expect(groups.map((g) => [g.title, g.guides.map((x) => x.title)])).toEqual([
            ['Every week', ['run']],
            ['When it comes up', ['guests']],
            ['For everyone', ['roster']],
            ['Admin', ['games']],
        ]);
        expect(groups[0].guides[0].section).toBe('weekly-rollcall');
    });

    it('leaves out a group with nothing in it for this viewer', () => {
        expect(homeGroups([contents[0]]).map((g) => g.title)).toEqual(['For everyone']);
        expect(homeGroups([])).toEqual([]);
    });
});

describe('labels', () => {
    it('names each audience as docs home and the guide tag show it', () => {
        expect(['everyone', 'leader', 'admin'].map(roleLabel)).toEqual(['Trooper', 'Leadership billet', 'Admin']);
        expect(['everyone', 'leader', 'admin'].map(audienceLabel)).toEqual(['Everyone', 'Every leadership billet', 'Admin only']);
    });
});

describe('openablePage', () => {
    it('opens a fixed portal path, never a pattern or something off the site', () => {
        expect(openablePage('/rollcall')).toBe('/rollcall');
        expect(openablePage('/reports/{unit}/{week}')).toBeNull();
        expect(openablePage('https://example.com')).toBeNull();
        expect(openablePage(null)).toBeNull();
    });
});

describe('cameFrom', () => {
    it('names the page the "?" help was opened from', () => {
        expect(cameFrom('/docs/weekly-rollcall/run?from=/rollcall')).toEqual({ href: '/rollcall', label: 'Rollcall' });
        expect(cameFrom('/docs/admin/games?from=%2Fsettings%2Fgames')).toEqual({ href: '/settings/games', label: 'Games' });
        expect(cameFrom('/docs/x/y?from=/nowhere')).toEqual({ href: '/nowhere', label: 'the previous page' });
    });

    it('ignores a missing value and anything that would leave the portal', () => {
        expect(cameFrom('/docs/x/y')).toBeNull();
        expect(cameFrom('/docs/x/y?from=//evil.example')).toBeNull();
        expect(cameFrom('/docs/x/y?from=https://evil.example')).toBeNull();
        expect(cameFrom('/docs/x/y?from=/\\evil.example')).toBeNull();
        // A browser drops tabs and line breaks from a URL, so these would become `//evil.example`.
        expect(cameFrom('/docs/x/y?from=/%09/evil.example')).toBeNull();
        expect(cameFrom('/docs/x/y?from=/%0A/evil.example')).toBeNull();
        expect(cameFrom('/docs/x/y?from=/%0D/evil.example')).toBeNull();
        expect(cameFrom('/docs/x/y?from=/rollcall%5C..')).toBeNull();
    });
});
