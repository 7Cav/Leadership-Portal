// The AcroForm field names of the regiment's counseling forms (#565), as the checked-in templates name them:
// resources/counseling/7CAV-R-042.pdf (DCS) and 7CAV-R-043.pdf (LOR). The server decides what goes in each field
// (App\Commands\Counseling\CounselingDocument); this file is what the browser needs to fill a form (counselingPdf.js) and
// to read one back from an older PDF (the counseling import). Keep it in step with CounselingDocument.

export const TEMPLATES = {
    dcs: '7CAV-R-042',
    lor: '7CAV-R-043',
};

// Section 1. The authority row is named after the form: "Counselling Authority" on a DCS, "Disciplining Authority" on
// an LOR.
export const SECTION_1 = {
    date: 'Date of Letter',
    trooperRank: 'RankTrooper',
    trooperName: 'Name LastFTrooper',
    trooperBillet: 'BilletTrooper',
    authorityRank: { dcs: 'RankCounselling Authority', lor: 'RankDisciplining Authority' },
    authorityName: { dcs: 'Name LastFCounselling Authority', lor: 'Name LastFDisciplining Authority' },
    authorityBillet: { dcs: 'BilletCounselling Authority', lor: 'BilletDisciplining Authority' },
};

// The leader's and the trooper's text fields, keyed as the record's `form_data` keys them. Both forms name them alike.
export const TEXT_FIELDS = {
    infraction: 'Evidenced infraction and associated details  Previous instances and counsellings',
    regulation: 'Associated regulation  order  SOP',
    discussion: 'Notes of discussion',
    plan_authority: 'Counselling Authority',
    plan_trooper: 'Counselling Authority2',
    plan_other: 'Counselling Authority3',
    comments_authority: 'Counselling AuthorityAny comments',
    comments_trooper: 'TrooperAny comments',
    comments_other: 'Misc  OthersAny comments',
};

// The LOR's special punishments field: the template names it after the whole consequence box above it.
export const SPECIAL_PUNISHMENTS =
    'Any Instance  30 Days NFA 2nd Instance in 90 days  Removal from Section Leader position if applicable Assistant Section Leader position if applicable Unit Commander escalation to Article 15 Commanders Investigation Any special punishment directed by a General Staff Officer to followSpecial punishments ordered';

// The LOR's consequence checkboxes, in the form's order.
export const CONSEQUENCE_CHECKS = {
    nfa: 'Check Box1',
    removal: 'Check Box2',
    article_15: 'Check Box3',
    special_punishment: 'Check Box4',
};

// The LOR's radio groups. Both buttons of each group carry the same export value in the template, so a choice is the
// button's place on the page, `top`/`bottom` or `left`/`right`.
export const RADIOS = {
    removal: { group: 'Group2', section_leader: 'top', assistant_section_leader: 'bottom' },
    appeal: { group: 'Group4', do: 'left', do_not: 'right' },
    outcome: { group: 'Group3', amended: 'left', not_amended: 'right' },
};

// Which form a PDF is, by a field only that form has: R-043 names its authority "Disciplining", R-042 "Counselling".
export function typeOfFieldNames(names) {
    const set = new Set(names);

    if (set.has(SECTION_1.authorityRank.lor)) return 'lor';
    if (set.has(SECTION_1.authorityRank.dcs)) return 'dcs';

    return null;
}
