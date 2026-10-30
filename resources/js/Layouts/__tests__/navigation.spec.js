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
        expect(visibleGroups(false).map((group) => group.id)).toEqual(['leadership', 'help']);
        expect(visibleGroups(true, { docs: true }).map((group) => group.id)).toEqual(NAV_GROUPS.map((group) => group.id));
    });

    it('shows Help > Docs to every viewer only while the docs are on, and Help > Changelog always', () => {
        const ids = (isAdmin, features) => visibleGroups(isAdmin, features).map((group) => group.id);

        expect(ids(false, { docs: true })).toEqual(['leadership', 'help']);
        expect(ids(true, { docs: true })).toContain('help');
        const helpItems = (features) => visibleGroups(false, features).find((group) => group.id === 'help').items.map((item) => item.label);
        expect(helpItems({ docs: true })).toEqual(['Docs', 'Changelog']);
        // The changelog is there for everyone, docs on or off.
        expect(helpItems({ docs: false })).toEqual(['Changelog']);
        expect(helpItems()).toEqual(['Changelog']);
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
        expect(visibleGroups(false, { demoTools: true }).map((group) => group.id)).toEqual(['leadership', 'help']);
    });

    it('shows Settings > Templates alone to a non-Admin who may manage templates (canManageTemplates)', () => {
        const settings = (isAdmin, props) => visibleGroups(isAdmin, {}, props).find((group) => group.id === 'settings');

        expect(settings(false, {})).toBeUndefined();
        expect(settings(false, { canManageTemplates: false })).toBeUndefined();
        expect(settings(false, { canManageTemplates: true }).items.map((item) => item.label)).toEqual(['Templates']);
        expect(settings(false, { canManageTemplates: true }).label).toBe('Settings');
        expect(settings(true, {}).items.map((item) => item.label)).toContain('Org structure');
    });

    it('shows Staff report to HQ staff who read only their own, Staff reports to readers, and keeps it active on a week', () => {
        const leadership = (features) =>
            visibleGroups(false, features)
                .find((group) => group.id === 'leadership')
                .items.map((item) => item.label);

        expect(leadership({})).not.toContain('Staff report');
        expect(leadership({ staffReports: null })).not.toContain('Staff reports');
        expect(leadership({ staffReports: 'own' })).toContain('Staff report');
        expect(leadership({ staffReports: 'own' })).not.toContain('Staff reports');
        expect(leadership({ staffReports: 'overview' })).toContain('Staff reports');
        expect(leadership({ staffReports: 'overview' })).not.toContain('Staff report');
        expect(isActive('/staff-reports?week=2026-10-03', '/staff-reports')).toBe(true);
        expect(isActive('/staff-report/12/2026-10-03', '/staff-report')).toBe(true);
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
