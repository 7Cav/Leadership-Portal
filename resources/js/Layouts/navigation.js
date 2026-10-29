import {
    PhBookOpenText,
    PhClipboardText,
    PhFileCode,
    PhFileText,
    PhFlask,
    PhFootprints,
    PhGameController,
    PhHardDrives,
    PhHouse,
    PhJoystick,
    PhKey,
    PhListMagnifyingGlass,
    PhTreeStructure,
    PhUserCheck,
    PhUsersThree,
} from '@phosphor-icons/vue';

// The shell's primary navigation (Story 1.15). An item with `href: null` has no screen yet: the
// sidebar renders it disabled with a "Coming soon" hint instead of a link that would 404. Give it
// an href when its story ships the page.
export const NAV_GROUPS = [
    {
        id: 'leadership',
        label: 'Leadership',
        adminOnly: false,
        // The breadcrumb starts at the item, not the group: 7CAV > Roster, not 7CAV > Leadership > Roster.
        crumb: false,
        items: [
            { label: 'Home', href: '/home', icon: PhHouse },
            { label: 'Roster', href: '/roster', icon: PhUsersThree },
            { label: 'Roll call', href: '/rollcall', icon: PhClipboardText },
            { label: 'Reports', href: '/reports', icon: PhFileText },
            // A platoon or company HQ staff member's own weekly staff report, only for them.
            { label: 'Staff report', href: '/staff-report', icon: PhUserCheck, feature: 'staffReport' },
            { label: 'Game identities', href: '/game-identities', icon: PhGameController },
        ],
    },
    {
        // The guides (#411), for every signed-in viewer while DOCS_ENABLED is on. 7CAV > Docs > {guide}.
        id: 'help',
        label: 'Help',
        adminOnly: false,
        crumb: false,
        items: [{ label: 'Docs', href: '/docs', icon: PhBookOpenText, feature: 'docs' }],
    },
    {
        // Shown to Admins, and to anyone else only for an item whose `allow` names a shared prop that is true for them
        // (Templates, for whoever may manage some unit's templates). This hides navigation, nothing more: every Settings
        // screen enforces its own server-side gate (AD-6).
        id: 'settings',
        label: 'Settings',
        adminOnly: true,
        items: [
            { label: 'Org structure', href: '/settings/org-structure', icon: PhTreeStructure },
            // `count` names a shared prop whose number the sidebar shows beside the item while it is above zero: here the
            // Regimental HQ billets waiting for an Admin to confirm them.
            { label: 'Staff grants', href: '/settings/staff-grants', icon: PhKey, count: 'adminBilletsPending' },
            { label: 'Templates', href: '/settings/templates', icon: PhFileCode, allow: 'canManageTemplates' },
            { label: 'Games', href: '/settings/games', icon: PhJoystick },
            { label: 'Servers', href: '/settings/servers', icon: PhHardDrives },
            { label: 'Audit log', href: '/settings/audit-log', icon: PhListMagnifyingGlass },
            { label: 'Activity log', href: '/settings/activity-log', icon: PhFootprints },
            // Only while DEMO_TOOLS is on (#115): `feature` names the shared `features` flag it needs.
            { label: 'Demo data', href: '/settings/demo-data', icon: PhFlask, feature: 'demoTools' },
        ],
    },
];

// The groups and items this viewer sees: admin-only groups for an Admin, and for anyone else only their items whose
// `allow` prop is true in `props` (the page's shared props); an item that needs an optional feature only while the
// shared `features` prop switches it on.
export function visibleGroups(isAdmin, features = {}, props = {}) {
    return NAV_GROUPS.map((group) => ({
        ...group,
        items: group.items
            .filter((item) => isAdmin || !group.adminOnly || (item.allow && props[item.allow] === true))
            .filter((item) => !item.feature || features[item.feature] === true),
    })).filter((group) => group.items.length);
}

function pathOf(url) {
    return (url ?? '').split(/[?#]/)[0].replace(/\/+$/, '') || '/';
}

// Pages that belong under a nav item without being one of its links: a trooper's milpac (`/troopers/12`) and a
// unit's training-readiness matrix (`/readiness/4`) are reached from the roster and read as children of it, so the Roster item stays active and the breadcrumb trail
// continues past it.
const CHILD_PAGES = [
    { prefix: '/troopers/', parent: '/roster' },
    { prefix: '/readiness/', parent: '/roster' },
    // A guide sits under Docs, and names itself as the last crumb.
    { prefix: '/docs/', parent: '/docs' },
];

function childOf(url, href) {
    const path = pathOf(url);

    return CHILD_PAGES.some((child) => child.parent === href && path.startsWith(child.prefix));
}

// Active when the page is the item itself or sits beneath it (`/settings/audit-log?page=2`).
export function isActive(url, href) {
    if (!href) return false;

    const path = pathOf(url);

    return path === href || path.startsWith(`${href}/`) || childOf(url, href);
}

// The group and item the current page belongs to, regardless of whether the viewer can see
// that group in the sidebar (a non-Admin may still open Org structure directly). `nested` is true for a
// child page, which adds its own last crumb after the item's.
export function locate(url) {
    for (const group of NAV_GROUPS) {
        const item = group.items.find((candidate) => isActive(url, candidate.href));
        if (item) return { group, item, nested: childOf(url, item.href) };
    }

    return null;
}
