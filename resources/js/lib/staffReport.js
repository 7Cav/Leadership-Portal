import { forumDate } from '@/lib/forumDate';

// How a sent staff report's answer reads, shared by the report page, the overview and the HQ staff panel. `report`
// carries `{ attended, practiceType, ftx: { name, date } | null, officeHour: { date, note } | null, presenceType,
// practices }`, where `practices` is a list of labels. `ftx` holds the name and date of any named type.

// The accountability types sent with a name and a date: a short label, and how each reads in a sentence.
export const NAMED_TYPES = {
    ftx: { short: 'FTX', sentence: 'an FTX' },
    operation: { short: 'operation', sentence: 'an operation' },
    class: { short: 'class', sentence: 'a class' },
    event: { short: 'official event', sentence: 'an official event' },
};

/** `Yes: 1/1/A/1-7, 2/1/A/1-7` for an SP, `Yes, FTX Anvil (09JAN26)`, `Yes, operation Anvil (09JAN26)`, `Yes, office hour (09JAN26)`, or `No`. Empty while not sent. */
export function attendedText(report) {
    if (report.attended === null || report.attended === undefined) return '';
    if (!report.attended) return 'No';

    const practices = report.practices?.length ? `: ${report.practices.join(', ')}` : '';
    const named = NAMED_TYPES[report.practiceType];
    if (named) {
        const date = report.ftx?.date ? ` (${forumDate(report.ftx.date)})` : '';

        return `Yes, ${named.short} ${report.ftx?.name ?? ''}${date}${practices}`;
    }
    if (report.practiceType === 'office_hour') {
        const date = report.officeHour?.date ? ` (${forumDate(report.officeHour.date)})` : '';

        return `Yes, office hour${date}`;
    }

    return `Yes${practices}`;
}

/** "Attended an operation, Anvil on 09JAN26." for a named type, or null for anything else. */
export function namedSentence(report) {
    const named = NAMED_TYPES[report.practiceType];
    if (!report.attended || !named) return null;

    return `Attended ${named.sentence}, ${report.ftx?.name ?? ''}${report.ftx?.date ? ` on ${forumDate(report.ftx.date)}` : ''}.`;
}

/** `Public server hour`, `Office hour (06OCT26)`, `No public server hour or office hour`, or empty when not asked. */
export function presenceAnswer(report) {
    if (report.presenceType === 'public_server') return 'Public server hour';
    if (report.presenceType === 'office_hour') return `Office hour${report.officeHour?.date ? ` (${forumDate(report.officeHour.date)})` : ''}`;
    if (report.presenceType === 'none') return 'No public server hour or office hour';

    return '';
}

/** "Sent to the sheet", "Not sent to the sheet yet" while configured and pending, or null when there is no sheet. */
export function sheetText(sheetPushedAt, configured) {
    if (sheetPushedAt) return 'Sent to the sheet';

    return configured ? 'Not sent to the sheet yet' : null;
}

/**
 * What the game server saw against what was reported, `{ notSeen, seenElsewhere }` (lists of section labels), as the
 * sentences a reader's tooltip shows. Empty when there is nothing to say.
 */
export function discrepancyLines(discrepancy) {
    if (!discrepancy) return [];

    const lines = [];
    if (discrepancy.notSeen?.length) {
        lines.push(`Reported at ${discrepancy.notSeen.join(', ')}, but the game server capture of that practice did not see them.`);
    }
    if (discrepancy.seenElsewhere?.length) {
        lines.push(`The game server saw them at the practice of ${discrepancy.seenElsewhere.join(', ')}, which they did not report.`);
    }

    return lines;
}

/**
 * The game server check frozen on a sent report, `{ check, source }`: "Self-report conflicts with CRCON data" for a
 * conflict, "No CRCON data" when nothing was captured, and empty when it agreed or there was no check.
 */
export function presenceText(presence) {
    if (!presence) return '';
    const source = presence.source || 'game server';
    if (presence.check === 'conflict') return `Self-report conflicts with ${source} data`;
    if (presence.check === 'no_data') return `No ${source} data`;

    return '';
}

// More platoons than this in the practice picker and it spans a battalion: every platoon but the staff member's own
// starts collapsed.
export const PICKER_COLLAPSE_OVER = 4;

/**
 * The practice picker's sections, grouped nearest unit first (`group`, null for the rest) and within each by platoon,
 * keeping the order the server sent (nearest first, then order of battle). A platoon is `own` when it holds one of the
 * HQ's own sections.
 *
 * @returns {{ key: string, label: string, platoons: { key: string, label: string|null, own: boolean, rows: object[] }[] }[]}
 */
export function pickerGroups(sections) {
    const out = [];
    for (const section of sections) {
        const key = section.group ?? '';
        let group = out.find((candidate) => candidate.key === key);
        if (!group) {
            group = { key, label: section.group ? `In ${section.group}` : 'Other sections', platoons: [] };
            out.push(group);
        }
        const platoonKey = section.platoon ?? '';
        let platoon = group.platoons.find((candidate) => candidate.key === platoonKey);
        if (!platoon) {
            platoon = { key: platoonKey, label: section.platoon ?? null, own: false, rows: [] };
            group.platoons.push(platoon);
        }
        platoon.own ||= Boolean(section.own);
        platoon.rows.push(section);
    }

    return out;
}

/** Whether the picker collapses platoons: only when it spans more than PICKER_COLLAPSE_OVER of them. */
export function pickerCollapses(groups) {
    return groups.reduce((sum, group) => sum + group.platoons.length, 0) > PICKER_COLLAPSE_OVER;
}
