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

// What a record is for: it decides which ladder of the regulations it is weighed on (CounselingKind on the server).
export const COUNSELING_KINDS = {
    missed_practice: { label: 'Missed section practice or rollcall' },
    conduct: { label: 'Other conduct' },
};

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
