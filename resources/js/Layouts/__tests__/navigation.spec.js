import { describe, expect, it } from 'vitest';
import { NAV_GROUPS, isActive, locate, visibleGroups } from '../navigation';
import { resolveLayout } from '../resolveLayout';
import AppLayout from '../AppLayout.vue';

describe('navigation', () => {
    it.each([
        ['/home', '/home', true],
        ['/home/', '/home', true],
        ['/home?tab=inbox', '/home', true],
        ['/home#week', '/home', true],
        ['/settings/audit-log?page=2', '/settings/audit-log', true],
        ['/homework', '/home', false],
        ['/settings', '/settings/audit-log', false],
        [null, '/home', false],
        [undefined, '/home', false],
        ['/home', null, false],
    ])('isActive(%s, %s) is %s', (url, href, expected) => {
        expect(isActive(url, href)).toBe(expected);
    });

    it('locates a Settings page whatever the viewer can see (the breadcrumb relies on it)', () => {
        const found = locate('/settings/org-structure');

        expect(found.group.id).toBe('settings');
        expect(found.item.label).toBe('Org structure');
    });

    it('treats a trooper page as a child of Roster: the item stays active and the trail continues', () => {
        expect(isActive('/troopers/12', '/roster')).toBe(true);
        expect(isActive('/troopers/12', '/home')).toBe(false);
        expect(isActive('/troopers', '/roster')).toBe(false);
        expect(isActive('/readiness/4', '/roster')).toBe(true);
        expect(locate('/readiness/4').nested).toBe(true);

        const found = locate('/troopers/12?tab=x');
        expect(found.item.label).toBe('Roster');
        expect(found.nested).toBe(true);
        expect(locate('/roster').nested).toBe(false);
    });

    it('locates nothing for a page outside the nav', () => {
        expect(locate('/somewhere-else')).toBeNull();
    });

    it('hides only the admin-only groups from a non-Admin', () => {
        expect(visibleGroups(false).map((group) => group.id)).toEqual(['leadership']);
        expect(visibleGroups(true, { docs: true }).map((group) => group.id)).toEqual(NAV_GROUPS.map((group) => group.id));
    });

    it('shows Help > Docs to every viewer, only while the docs are on (#411)', () => {
        const ids = (isAdmin, features) => visibleGroups(isAdmin, features).map((group) => group.id);

        expect(ids(false, { docs: true })).toEqual(['leadership', 'help']);
        expect(ids(true, { docs: true })).toContain('help');
        expect(ids(false, { docs: false })).not.toContain('help');
        expect(ids(false)).not.toContain('help');
    });

    it('keeps Docs active on every guide, with the guide as the last crumb', () => {
        expect(isActive('/docs', '/docs')).toBe(true);
        expect(isActive('/docs/weekly-rollcall/run-rollcall', '/docs')).toBe(true);
        expect(locate('/docs').nested).toBe(false);

        const found = locate('/docs/weekly-rollcall/run-rollcall');
        expect(found.item.label).toBe('Docs');
        expect(found.group.crumb).toBe(false);
        expect(found.nested).toBe(true);
    });

    it('shows Demo data only while the demoTools feature is on (#115)', () => {
        const settingsItems = (features) =>
            visibleGroups(true, features)
                .find((group) => group.id === 'settings')
                .items.map((item) => item.label);

        expect(settingsItems(undefined)).not.toContain('Demo data');
        expect(settingsItems({ demoTools: false })).not.toContain('Demo data');
        expect(settingsItems({ demoTools: true })).toContain('Demo data');
        expect(visibleGroups(false, { demoTools: true }).map((group) => group.id)).toEqual(['leadership']);
    });

    it('locates the Demo data page for its breadcrumb', () => {
        expect(locate('/settings/demo-data').item.label).toBe('Demo data');
    });
});

describe('resolveLayout', () => {
    it.each(['Home', 'Settings/OrgStructure', 'Settings/StaffGrants', 'Settings/Templates', 'Settings/Games', 'Settings/AuditLog', 'Settings/DemoData'])('wraps %s in the app shell', (name) => {
        expect(resolveLayout(name)).toBe(AppLayout);
    });

    it('keeps the login screen outside the shell', () => {
        expect(resolveLayout('Auth/Login')).toBeNull();
    });

    it('does not treat a page that merely starts with "Auth" as an auth page', () => {
        expect(resolveLayout('Authorization/Overview')).toBe(AppLayout);
    });
});
