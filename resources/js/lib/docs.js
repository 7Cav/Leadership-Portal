import { locate } from '@/Layouts/navigation';

// Shared wording and small rules for the docs pages (#410).

// What docs home calls the viewer's role, by the audience they read the guides as.
const ROLES = { everyone: 'Trooper', leader: 'Leadership billet', admin: 'Admin' };

// Who a guide is for, as its tag says.
const AUDIENCES = { everyone: 'Everyone', leader: 'Every leadership billet', admin: 'Admin only' };

export function roleLabel(audience) {
    return ROLES[audience] ?? ROLES.everyone;
}

export function audienceLabel(audience) {
    return AUDIENCES[audience] ?? AUDIENCES.everyone;
}

// Docs home's groups (agreed design): weekly leader tasks, the other leader tasks, guides for everyone, and Admin's.
// A group with nothing in it for this viewer is left out.
export function homeGroups(contents) {
    const guides = contents.flatMap((section) => section.guides.map((guide) => ({ ...guide, section: section.key })));
    const groups = [
        { id: 'weekly', title: 'Every week', guides: guides.filter((g) => g.audience === 'leader' && g.weekly), rows: true },
        { id: 'when', title: 'When it comes up', guides: guides.filter((g) => g.audience === 'leader' && !g.weekly) },
        { id: 'everyone', title: 'For everyone', guides: guides.filter((g) => g.audience === 'everyone') },
        { id: 'admin', title: 'Admin', guides: guides.filter((g) => g.audience === 'admin') },
    ];

    return groups.filter((group) => group.guides.length);
}

// A guide's `page` the "Open in portal" button can go to: a fixed path, not a pattern with `{unit}` in it.
export function openablePage(page) {
    return typeof page === 'string' && page.startsWith('/') && !page.includes('{') ? page : null;
}

// Where the reader came from when the "?" help opened this guide (`?from=/rollcall`): a path on this portal only,
// never `//elsewhere` or a full URL, so the back link can never leave the site. Null when there is none.
export function cameFrom(url) {
    const query = (url ?? '').split('?')[1] ?? '';
    const from = new URLSearchParams(query.split('#')[0]).get('from');
    if (!from || !from.startsWith('/') || from.startsWith('//') || from.startsWith('/\\')) return null;

    const found = locate(from);

    return { href: from, label: found ? found.item.label : 'the previous page' };
}
