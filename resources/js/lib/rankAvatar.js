import { RANK_ORDER } from '@/lib/roster';

// The 7Cav standard rank avatars (Standard Avatars on wiki.7cav.us/wiki/Avatars), self-hosted under
// public/assets/ranks so the CSP's img-src 'self' covers them, shrunk to 96x96. Every rank the
// portal knows has one except RCT, which the wiki doesn't draw; the caller falls back to initials.
const WITHOUT_AVATAR = new Set(['RCT']);

/** The avatar image path for a rank abbreviation (`1LT`, `CW2`), or null when there is none. */
export function rankAvatarUrl(rank) {
    const key = String(rank ?? '').trim().toUpperCase();
    if (!RANK_ORDER.includes(key) || WITHOUT_AVATAR.has(key)) return null;

    return `/assets/ranks/${key}.png`;
}
