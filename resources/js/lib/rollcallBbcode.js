import { render } from './bbcode';
import { ROLE_LABELS, orderTroopers, shownRole } from './fireteams';
import { forumDate } from './forumDate';
import { insigniaWidth } from './rankInsignia';
import { displayName, fullName, isLocked, rankIconOf, rankOf } from './rollcall';

// The rollcall forum post (Stories 3.10, 3.11). The post is whatever the section's company has as its active template
// (3.5, resolved by the server on every render, 3.6, AD-17) filled with the week's marks. There is no built-in copy to
// fall back to: a company with no template shows what is missing instead of a post nobody wrote. The portal never
// posts it (AD-20); the leader copies it into the week's rollcall thread.

const RC_WORDS = { will_attend: 'Yes', cannot_attend: 'No' };
const SP_WORDS = { present: 'Yes', missed: 'No', makeup: 'MU', attached: 'Att', loa: 'LOA' };

/**
 * The values a template can print, from the section, the week and each trooper's current marks. The troopers are
 * listed SL, ASL, then fireteam leaders when the section shows them, then everyone else, each by rank
 * ({@link orderTroopers}), so `{{n}}` numbers them in that order.
 *
 * @param {{section: object, week: object, troopers: object[], fireteams?: {show: boolean, order: string}}} input the props
 *   the rollcall page received, with the working marks
 */
export function rollcallContext({ section, week, troopers, fireteams = {} }) {
    const rows = orderTroopers(troopers, fireteams).map((trooper, index) => {
        const sp = isLocked(trooper) ? 'loa' : trooper.status;
        const rc = trooper.rollcall;
        const icon = rankIconOf(trooper);
        const role = shownRole(trooper, fireteams.show ?? false);

        return {
            n: String(index + 1),
            role: ROLE_LABELS[role] ?? '',
            is_sl: role === 'sl',
            is_asl: role === 'asl',
            is_ftl: role === 'ftl',
            rank: trooper.rank ?? '',
            rank_short: rankOf(trooper) ?? '',
            rank_icon: icon,
            // The width that gives this insignia the same weight as every other rank's, for [IMG width="..."].
            rank_icon_width: icon ? `${insigniaWidth(icon)}px` : '',
            name: displayName(trooper),
            display_name: fullName(trooper),
            rc: RC_WORDS[rc] ?? '',
            sp: SP_WORDS[sp] ?? '',
            sp_present: sp === 'present',
            sp_missed: sp === 'missed',
            sp_makeup: sp === 'makeup',
            sp_attached: sp === 'attached',
            sp_loa: sp === 'loa',
            sp_unmarked: sp === null,
            rc_yes: rc === 'will_attend',
            rc_no: rc === 'cannot_attend',
            rc_unsigned: rc !== 'will_attend' && rc !== 'cannot_attend',
        };
    });

    return {
        unit: section.designation,
        unit_name: section.name,
        week: `${forumDate(week.start)} – ${forumDate(week.closes)}`,
        week_start: forumDate(week.start),
        week_end: forumDate(week.closes),
        count: String(rows.length),
        signed: String(rows.filter((row) => row.rc_yes).length),
        attended: String(rows.filter((row) => row.sp_present || row.sp_makeup || row.sp_attached).length),
        troopers: rows,
    };
}

/**
 * The forum post for the page's current marks.
 *
 * `ready` carries the BBCode and the version it came from; `none` says why no template applies; `invalid` is a stored
 * template that no longer renders (one saved before its grammar was checked), with the reason.
 *
 * @param {{state: string, version: ?number, body: ?string}|null} template the `template` prop (ResolvedTemplate::toArray)
 * @returns {{state: 'ready', text: string, version: number} | {state: 'none' | 'invalid', message: string}}
 */
export function rollcallPost(template, input) {
    if (template?.state === 'no_owning_company') {
        return { state: 'none', message: 'This section does not sit under a company, so there is no rollcall template for it.' };
    }

    if (template?.state !== 'resolved' || typeof template.body !== 'string') {
        return { state: 'none', message: 'This company has no rollcall template yet. An Admin writes one in Settings, Templates.' };
    }

    const out = render(template.body, rollcallContext(input));

    return out.ok
        ? { state: 'ready', text: out.text, version: template.version }
        : { state: 'invalid', message: `The company's template (version ${template.version}) does not render: ${out.error} An Admin can fix it in Settings, Templates.` };
}
