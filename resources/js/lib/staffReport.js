import { forumDate } from '@/lib/forumDate';

// How a sent staff report's answer reads, shared by the report page, the overview and the HQ staff panel. `report`
// carries `{ attended, practiceType, ftx: { name, date } | null, officeHour: { date, note } | null, practices }`, where
// `practices` is a list of labels.

/** `Yes: 1/1/A/1-7, 2/1/A/1-7` for an SP, `Yes, FTX Operation Anvil (09Jan26)`, `Yes, office hour (09Jan26)`, or `No`. Empty while not sent. */
export function attendedText(report) {
    if (report.attended === null || report.attended === undefined) return '';
    if (!report.attended) return 'No';

    const practices = report.practices?.length ? `: ${report.practices.join(', ')}` : '';
    if (report.practiceType === 'ftx') {
        const date = report.ftx?.date ? ` (${forumDate(report.ftx.date)})` : '';

        return `Yes, FTX ${report.ftx?.name ?? ''}${date}${practices}`;
    }
    if (report.practiceType === 'office_hour') {
        const date = report.officeHour?.date ? ` (${forumDate(report.officeHour.date)})` : '';

        return `Yes, office hour${date}`;
    }

    return `Yes${practices}`;
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
