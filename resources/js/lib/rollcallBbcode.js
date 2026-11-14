import { render, WEEK_JOIN } from './bbcode';
import { fireteamNames, orderTroopers, roleLabel } from './fireteams';
import { forumDate } from './forumDate';
import { insigniaWidth } from './rankInsignia';
import { displayName, fullName, practiceOf, rankIconOf, rankOf, signatureOf } from './rollcall';

// The forum post keeps its own date form, `03Oct26` with the month in title case, so a posted roll call reads the same
// as before the portal moved to capital months on screen (#568). Only the post: everything on screen uses `forumDate()`.
function postDate(value) {
    const date = forumDate(value);

    return date.slice(0, 3) + date.slice(3, 5).toLowerCase() + date.slice(5);
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// When the week's practice starts, as the post writes a moment (`Wed 07Oct26 2300Z`, #703): empty with no practice.
function postDateTime(value) {
    if (!value) return '';
    const at = new Date(value);
    const clock = `${String(at.getUTCHours()).padStart(2, '0')}${String(at.getUTCMinutes()).padStart(2, '0')}`;

    return `${WEEKDAYS[at.getUTCDay()]} ${postDate(at)} ${clock}Z`;
}

// The rollcall forum post (Stories 3.10, 3.11). The post is whatever the section's company has as its active template
// (3.5, resolved by the server on every render, 3.6, AD-17) filled with the week's marks. There is no built-in copy to
// fall back to: a company with no template shows what is missing instead of a post nobody wrote. The portal never
// posts it (AD-20); the leader copies it into the week's rollcall thread.

const RC_WORDS = { will_attend: 'Yes', cannot_attend: 'No', makeup_requested: 'MU', excused: 'EX' };
const SP_WORDS = { present: 'Yes', missed: 'No', excused: 'EX', makeup: 'MU', loa: 'LOA' };

/**
 * The values a template can print, from the section, the week and each trooper's current marks. The troopers are
 * listed SL, ASL, then fireteam leaders when the section shows them, then everyone else, each by rank
 * ({@link orderTroopers}), so `{{n}}` numbers them in that order. Shown fireteams are named by letter
 * ({@link fireteamNames}), and `fireteams_shown` is set only when the section shows FTLs and has at least one.
 *
 * @param {{section: object, week: object, troopers: object[], fireteams?: {show: boolean, order: string, alphabet: string}, resultImages?: {rc: string, sp: string}, training?: {date: ?string, topic: ?string}}} input
 *   the props the rollcall page received, with the working marks
 */
export function rollcallContext({ section, week, troopers, fireteams = {}, resultImages = {}, training = {} }) {
    const ordered = orderTroopers(troopers, fireteams);
    const names = fireteamNames(ordered, fireteams);
    const rows = ordered.map((trooper, index) => {
        // As they count: leave as LOA, and the automatic Excused of a first week where nobody recorded otherwise.
        const sp = practiceOf(trooper);
        const rc = signatureOf(trooper);
        const icon = rankIconOf(trooper);

        return {
            n: String(index + 1),
            role: roleLabel(trooper, names),
            is_sl: trooper.role === 'sl',
            is_asl: trooper.role === 'asl',
            is_ftl: names.has(trooper.id),
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
            sp_excused: sp === 'excused',
            sp_makeup: sp === 'makeup',
            // Attached merged into Make-up complete (2026-10-30): never set, kept so an older template still renders.
            sp_attached: false,
            sp_loa: sp === 'loa',
            sp_unmarked: sp === null,
            rc_yes: rc === 'will_attend',
            rc_no: rc === 'cannot_attend',
            rc_makeup_requested: rc === 'makeup_requested',
            rc_excused: rc === 'excused',
            rc_unsigned: !RC_WORDS[rc],
        };
    });

    return {
        unit: section.designation,
        unit_name: section.name,
        week: `${postDate(week.start)}${WEEK_JOIN}${postDate(week.closes)}`,
        week_start: postDate(week.start),
        week_end: postDate(week.closes),
        // This week's practice start and training topic (#703): the topic is the weekly report's training subject.
        training_date: postDateTime(training.date),
        training_topic: training.topic?.trim() ?? '',
        count: String(rows.length),
        signed: String(rows.filter((row) => row.rc_yes).length),
        attended: String(rows.filter((row) => row.sp_present || row.sp_makeup).length),
        // The live Signed RC and Attended SP images (#641), drawn by the server from the marks as they are when the forum fetches them.
        rc_image: resultImages.rc ?? '',
        sp_image: resultImages.sp ?? '',
        fireteams_shown: names.size > 0,
        troopers: rows,
    };
}

/**
 * The forum post for the page's current marks.
 *
 * `ready` carries the BBCode and the version it came from; `none` says why no template applies; `invalid` is a stored
 * template that no longer renders (one saved before its grammar was checked), with the reason.
 *
 * @param {{state: string, name: ?string, source: ?string, version: ?number, body: ?string}|null} template the `template` prop (ResolvedTemplate::toArray)
 * @returns {{state: 'ready', text: string, version: number} | {state: 'none' | 'invalid', message: string}}
 */
export function rollcallPost(template, input) {
    if (template?.state === 'no_owning_company') {
        return { state: 'none', message: 'This section does not sit under a company, so there is no roll call template for it.' };
    }

    if (template?.state !== 'resolved' || typeof template.body !== 'string') {
        return { state: 'none', message: 'This section has no roll call template, not even the regiment default. An Admin can check Settings, Templates.' };
    }

    const out = render(template.body, rollcallContext(input));
    const fixer = template.source === 'default' ? 'An Admin' : "The company's command";

    return out.ok
        ? { state: 'ready', text: out.text, version: template.version }
        : { state: 'invalid', message: `The template ${template.name ?? ''} (version ${template.version}) does not render: ${out.error} ${fixer} can fix it in Settings, Templates.`.replace('  ', ' ') };
}
