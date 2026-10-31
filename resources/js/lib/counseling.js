import { CONSEQUENCE_CHECKS, RADIOS, SECTION_1, SPECIAL_PUNISHMENTS, TEMPLATES, TEXT_FIELDS } from '@/lib/counselingFields';

// Developmental Counseling Statements and Letters of Reprimand, written in the portal through the wiki procedure (#381,
// #565). The server decides who sees and may do what, and where a record stands; these are the words and small rules
// the pages draw with.

export const COUNSELING_TYPES = {
    dcs: { short: 'DCS', label: 'Developmental Counseling Statement', form: TEMPLATES.dcs, authority: 'Counselling authority' },
    lor: { short: 'LOR', label: 'Letter of Reprimand', form: TEMPLATES.lor, authority: 'Disciplining authority' },
};

// The LOR's recommended consequences, in the form's order. `short` is the badge in lists.
export const LOR_CONSEQUENCES = {
    nfa: { label: 'Any instance: 30 days no favorable action', short: '30 days no favorable action' },
    removal: { label: '2nd instance in 90 days: removal from a position', short: 'Removal' },
    article_15: { label: "Escalate to Article 15 Commander's Investigation", short: 'Article 15 investigation' },
    special_punishment: { label: 'Any special punishment directed by a General Staff Officer (to follow)', short: 'Special punishment' },
};

export const LOR_REMOVALS = {
    section_leader: { label: 'Section Leader position', short: 'Removal from SL' },
    assistant_section_leader: { label: 'Assistant Section Leader position', short: 'Removal from ASL' },
};

// The leader's fields of the form, as the record's `form` keys them (CounselingForm on the server).
export const LEADER_TEXT = ['infraction', 'regulation', 'discussion', 'plan_authority', 'plan_other', 'comments_authority', 'comments_other', 'special_punishments'];

// The server's cap for each text field, from the page's `limits` (CounselingForm, CounselingResponse).
export function fieldLimit(key, limits = {}) {
    if (['infraction', 'discussion', 'special_punishments'].includes(key)) return limits.long;
    if (key === 'regulation') return limits.short;
    if (key.startsWith('plan_')) return limits.plan;
    if (key.startsWith('comments_')) return limits.comment;

    return limits.reason;
}

// The leader's fields from a record's stored `form`, every one present, so the draft form starts complete.
export function leaderForm(stored = {}) {
    const form = Object.fromEntries(LEADER_TEXT.map((key) => [key, typeof stored?.[key] === 'string' ? stored[key] : '']));

    return {
        ...form,
        consequences: Array.isArray(stored?.consequences) ? stored.consequences.filter((c) => c in LOR_CONSEQUENCES) : [],
        removal: stored?.removal in LOR_REMOVALS ? stored.removal : null,
    };
}

// The whole draft as PATCH counseling.update takes it. A DCS sends no LOR fields; a removal goes only with the removal
// consequence. The server trims and applies the same rules again.
export function draftPayload(draft) {
    const lor = draft.type === 'lor';
    const consequences = lor ? Object.keys(LOR_CONSEQUENCES).filter((c) => draft.form.consequences.includes(c)) : [];
    const text = Object.fromEntries(LEADER_TEXT.filter((key) => key !== 'special_punishments').map((key) => [key, draft.form[key] ?? '']));

    return {
        type: draft.type,
        kind: draft.kind,
        counseled_on: draft.counseled_on || null,
        meeting_confirmed: !!draft.meeting_confirmed,
        form: lor
            ? { ...text, consequences, removal: consequences.includes('removal') ? draft.form.removal || null : null, special_punishments: draft.form.special_punishments ?? '' }
            : text,
    };
}

// The Date of Letter as the form prints it: `29 Oct 2026`. Empty without a date.
export function letterDate(date) {
    if (!date) return '';
    const at = new Date(`${date.slice(0, 10)}T00:00:00Z`);
    if (Number.isNaN(at.getTime())) return '';

    return at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).replace('Sept', 'Sep');
}

// An instant in the Cav's Zulu style: `31 Oct 1900Z`. Empty for a missing one.
export function zulu(at) {
    if (!at) return '';
    const date = new Date(at);
    if (Number.isNaN(date.getTime())) return '';
    const day = date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' }).replace('Sept', 'Sep');

    return `${day} ${String(date.getUTCHours()).padStart(2, '0')}${String(date.getUTCMinutes()).padStart(2, '0')}Z`;
}

// What is left until `at`: `1 day 4 h`, `21 h 40 min`, `35 min`. Null once it has passed.
export function timeLeft(at, now = Date.now()) {
    if (!at) return null;
    const minutes = Math.floor((new Date(at).getTime() - Number(now)) / 60000);
    if (!(minutes > 0)) return null;

    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    if (days) return `${days} ${days === 1 ? 'day' : 'days'}${hours ? ` ${hours} h` : ''}`;
    if (hours) return `${hours} h ${minutes % 60} min`;

    return `${minutes} min`;
}

// The file name the server gives a record (CounselingRecord::fileName): `LOR 2026-10-29 Okafor.D.pdf`.
export function counselingFileName(type, date, trooper) {
    const who = String(trooper?.name ?? '').replace(/[^A-Za-z0-9._-]/g, '');

    return `${COUNSELING_TYPES[type]?.short ?? 'DCS'} ${date || 'draft'} ${who || `trooper-${trooper?.id}`}.pdf`;
}

// The form's fields for values on the page, the way CounselingDocument on the server maps a saved record: the live
// preview and the "Draft PDF" of a draft with unsaved changes. Keep it in step with CounselingDocument.
export function documentPayload({ type, date, section1, form, outcome = null, trooper = null, version = 0 }) {
    const lor = type === 'lor';
    const values = form ?? {};
    const text = {
        [SECTION_1.date]: letterDate(date),
        [SECTION_1.trooperRank]: section1?.trooper?.rank ?? '',
        [SECTION_1.trooperName]: section1?.trooper?.name ?? '',
        [SECTION_1.trooperBillet]: section1?.trooper?.billet ?? '',
        [SECTION_1.authorityRank[type]]: section1?.authority?.rank ?? '',
        [SECTION_1.authorityName[type]]: section1?.authority?.name ?? '',
        [SECTION_1.authorityBillet[type]]: section1?.authority?.billet ?? '',
    };
    for (const [key, field] of Object.entries(TEXT_FIELDS)) text[field] = typeof values[key] === 'string' ? values[key] : '';

    const checks = [];
    const radios = [];
    if (lor) {
        text[SPECIAL_PUNISHMENTS] = typeof values.special_punishments === 'string' ? values.special_punishments : '';
        const picked = Array.isArray(values.consequences) ? values.consequences : [];
        for (const [consequence, field] of Object.entries(CONSEQUENCE_CHECKS)) if (picked.includes(consequence)) checks.push(field);

        if (RADIOS.removal[values.removal]) radios.push({ group: RADIOS.removal.group, pick: RADIOS.removal[values.removal] });
        if (RADIOS.appeal[values.appeal]) radios.push({ group: RADIOS.appeal.group, pick: RADIOS.appeal[values.appeal] });
        if (RADIOS.outcome[outcome]) radios.push({ group: RADIOS.outcome.group, pick: RADIOS.outcome[outcome] });
    }

    return {
        template: COUNSELING_TYPES[type]?.form ?? TEMPLATES.dcs,
        fileName: counselingFileName(type, date, trooper),
        version,
        text,
        checks,
        radios,
    };
}

// The consequence an LOR's form recommends, as short badges: `30 days no favorable action`, `Removal from ASL`.
export function consequenceBadges(form) {
    const picked = Array.isArray(form?.consequences) ? form.consequences : [];

    return Object.keys(LOR_CONSEQUENCES)
        .filter((c) => picked.includes(c))
        .map((c) => (c === 'removal' && LOR_REMOVALS[form.removal] ? LOR_REMOVALS[form.removal].short : LOR_CONSEQUENCES[c].short));
}

// What share needs, in the order the steps ask for it, with the server's `missing` (field => message) marking what is
// still open. The server's list is the saved draft's: it is what share is judged on.
const CHECKLIST = [
    { key: 'invite', label: 'Meeting invite sent', lor: true },
    { key: 'infraction', label: 'Infraction' },
    { key: 'regulation', label: 'Regulation, order or SOP' },
    { key: 'counseled_on', label: 'Date of Letter' },
    { key: 'discussion', label: 'Notes of discussion' },
    { key: 'plan_authority', label: 'Your plan of action' },
    { key: 'meeting_confirmed', label: 'Meeting confirmed' },
    { key: 'consequences', label: 'Recommended consequence', lor: true },
];

export function shareChecklist(type, missing) {
    const open = missing && typeof missing === 'object' ? missing : {};
    const items = CHECKLIST.filter((item) => !item.lor || type === 'lor').map((item) => ({
        key: item.key,
        label: item.label,
        done: !(item.key in open) && !(item.key === 'consequences' && 'removal' in open),
        message: open[item.key] ?? (item.key === 'consequences' ? open.removal : undefined) ?? null,
    }));
    // A field the server names that is not on the list still blocks share: say so.
    for (const [key, message] of Object.entries(open)) {
        if (!CHECKLIST.some((item) => item.key === key) && key !== 'removal') items.push({ key, label: message, done: false, message });
    }

    return items;
}

// A record's place in the procedure as one badge for lists: `{ label, tone }`, tone a Badge variant.
export function recordBadge(record) {
    if (record.overturned) return { label: 'Overturned', tone: 'secondary' };
    if (record.state === 'draft') return record.withdrawn ? { label: 'Withdrawn to draft', tone: 'warning' } : { label: 'Draft', tone: 'outline' };
    if (record.state === 'invited') return { label: 'Invited', tone: 'info' };
    if (record.needsReview) return { label: 'Imported, needs review', tone: 'warning' };
    if (record.state === 'appealed') return { label: 'Appealed', tone: 'warning' };
    if (record.state === 'decided') return { label: 'Decided', tone: 'info' };
    if (record.type === 'dcs' && record.dcsPhase) {
        return { active: { label: 'Active', tone: 'success' }, inactive: { label: 'Inactive', tone: 'secondary' }, expired: { label: 'Expired', tone: 'outline' } }[record.dcsPhase];
    }
    if (record.source === 'import') return { label: 'Imported', tone: 'outline' };
    if (record.state === 'no_response') return { label: 'No response', tone: 'secondary' };
    if (record.state === 'acknowledged') return { label: 'Acknowledged', tone: 'success' };

    return { label: 'Waiting for the trooper', tone: 'info' };
}

// The flags a new record of `type` and `kind` dated `date` would earn, from the server's forecast: date ranges, oldest
// first, each running from its `from` date to the next one's. The server worked them out; this only picks the range
// the date falls in. An empty list when nothing applies, or nothing is chosen yet.
export function forecastFlags(forecast, type, kind, date) {
    const ranges = forecast?.[type]?.[kind];
    if (!ranges?.length || !date) return [];

    let flags = [];
    for (const range of ranges) {
        if (range.from > date) break;
        flags = range.flags;
    }

    return flags ?? [];
}

// Today in UTC as YYYY-MM-DD: the latest date a record may carry (the server judges dates on its UTC clock).
export function todayUtc(now = new Date()) {
    return now.toISOString().slice(0, 10);
}
