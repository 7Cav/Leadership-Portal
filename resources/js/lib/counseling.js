// Developmental Counseling Statements and Letters of Reprimand on the trooper page (#381). The server decides who
// sees and may do what; these are the words and small rules the panel draws with.

export const COUNSELING_TYPES = {
    dcs: { short: 'DCS', label: 'Developmental Counseling Statement' },
    lor: { short: 'LOR', label: 'Letter of Reprimand' },
};

// Where each record's copy on the 7Cav Google Drive stands.
export const DRIVE_STATUS = {
    pending: { label: 'Drive copy pending', tone: 'outline' },
    uploaded: { label: 'Copied to Drive', tone: 'success' },
    failed: { label: 'Drive copy failed', tone: 'danger' },
    skipped: { label: 'Portal only', tone: 'secondary' },
};

// The server's own limit (CounselingRules::DOCUMENT_MAX_BYTES). Checked here only to say so before a slow upload.
export const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

// "34 KB", "1.2 MB".
export function fileSize(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// What filing one more record of `type` would mean under the 90-day rules, from the counts the server sent (records
// dated in the last 90 days that the viewer sees). Null when nothing applies.
export function nextStepHint(type, counts) {
    const dcs = counts?.dcs ?? 0;
    const lor = counts?.lor ?? 0;

    if (type === 'dcs' && dcs >= 1) {
        return `This trooper already has ${dcs === 1 ? 'a DCS' : `${dcs} DCSs`} in the last 90 days. A repeat within 90 days of a DCS calls for a Letter of Reprimand.`;
    }
    if (type === 'lor' && lor >= 2) {
        return `This trooper already has ${lor} LORs in the last 90 days. A third within 90 days calls for an Article 15.`;
    }
    if (type === 'lor' && lor === 1) {
        return 'This trooper already has an LOR in the last 90 days. A second within 90 days allows removal as Section Leader or Assistant Section Leader.';
    }

    return null;
}

// A client-side first look at the chosen file, so a wrong file is caught before it uploads. The server checks the
// bytes again and has the last word.
export function documentProblem(file) {
    if (!file) return 'Attach the signed document as a PDF.';
    const pdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name ?? '');
    if (!pdf) return 'Attach the signed document as a PDF.';
    if (file.size > MAX_DOCUMENT_BYTES) return 'The document must be 10 MB or smaller.';

    return null;
}

// Today in UTC as YYYY-MM-DD: the latest date a record may carry (the server judges dates on its UTC clock).
export function todayUtc(now = new Date()) {
    return now.toISOString().slice(0, 10);
}
