import { forumDate } from '@/lib/forumDate';

// How a sent staff report's answer reads, shared by the report page, the overview and the HQ staff panel. `report`
// carries `{ attended, practiceType, ftx: { name, date } | null, practices }`, where `practices` is a list of labels.

/** `Yes: 1/1/A/1-7, 2/1/A/1-7` for an SP, `Yes, FTX Operation Anvil (09Jan26)`, or `No`. Empty while not sent. */
export function attendedText(report) {
    if (report.attended === null || report.attended === undefined) return '';
    if (!report.attended) return 'No';

    const practices = report.practices?.length ? `: ${report.practices.join(', ')}` : '';
    if (report.practiceType === 'ftx') {
        const date = report.ftx?.date ? ` (${forumDate(report.ftx.date)})` : '';

        return `Yes, FTX ${report.ftx?.name ?? ''}${date}${practices}`;
    }

    return `Yes${practices}`;
}

/** "Sent to the sheet", "Not sent to the sheet yet" while configured and pending, or null when there is no sheet. */
export function sheetText(sheetPushedAt, configured) {
    if (sheetPushedAt) return 'Sent to the sheet';

    return configured ? 'Not sent to the sheet yet' : null;
}
