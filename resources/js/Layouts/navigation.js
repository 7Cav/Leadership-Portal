import {
    PhClipboardText,
    PhFileCode,
    PhFileText,
    PhFlask,
    PhGameController,
    PhHouse,
    PhJoystick,
    PhKey,
    PhListMagnifyingGlass,
    PhTreeStructure,
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
        items: [
            { label: 'Home', href: '/home', icon: PhHouse },
            { label: 'Roster', href: '/roster', icon: PhUsersThree },
            { label: 'Rollcall', href: '/rollcall', icon: PhClipboardText },
            { label: 'Reports', href: '/reports', icon: PhFileText },
            { label: 'Game identities', href: '/game-identities', icon: PhGameController },
        ],
    },
    {
        // Shown to Admins only (AC). This hides navigation, nothing more: every Settings screen
        // enforces its own server-side gate (AD-6).
        id: 'settings',
        label: 'Settings',
        adminOnly: true,
        items: [
            { label: 'Org structure', href: '/settings/org-structure', icon: PhTreeStructure },
            { label: 'Staff grants', href: '/settings/staff-grants', icon: PhKey },
            { label: 'Templates', href: '/settings/templates', icon: PhFileCode },
            { label: 'Games', href: '/settings/games', icon: PhJoystick },
            { label: 'Audit log', href: '/settings/audit-log', icon: PhListMagnifyingGlass },
            // Only while DEMO_TOOLS is on (#115): `feature` names the shared `features` flag it needs.
            { label: 'Demo data', href: '/settings/demo-data', icon: PhFlask, feature: 'demoTools' },
        ],
    },
];

// The groups and items this viewer sees: admin-only groups for an Admin, and an item that needs an
// optional feature only while the shared `features` prop switches it on.
export function visibleGroups(isAdmin, features = {}) {
    return NAV_GROUPS.filter((group) => isAdmin || !group.adminOnly)
        .map((group) => ({ ...group, items: group.items.filter((item) => !item.feature || features[item.feature] === true) }))
        .filter((group) => group.items.length);
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
