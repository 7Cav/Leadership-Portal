import { forumDate, weekLabel } from '@/lib/forumDate';
import { owesMakeup, practiceOf, signatureOf } from '@/lib/rollcall';

// Presentation rules for the weekly report screen (Stories 5.3–5.5, mockups/v2/report-p3.html).
// The numbers themselves come from the server (KpiService, AD-21); this file only formats them,
// colours their change and turns the week's facts into the readiness checklist and the
// highlights a leader reviews before sending.

// The six KPIs in report order. `polarity` is what an increase means (5.4): +1 good, -1 needs
// attention. `deltaKey` is the matching field of the server's delta (rates in basis points).
export const KPIS = [
    { key: 'strength', label: 'In roll call', polarity: 1, deltaKey: 'strength' },
    { key: 'rc', label: 'RC % 30d', polarity: 1, deltaKey: 'rcBp', rate: true },
    { key: 'sp', label: 'SP % 30d', polarity: 1, deltaKey: 'spBp', rate: true },
    { key: 'atRisk', label: 'At-risk', polarity: -1, deltaKey: 'atRisk' },
    { key: 'onLoa', label: 'On LOA', polarity: -1, deltaKey: 'onLoa' },
    { key: 'makeups', label: 'Makeups', polarity: 1, deltaKey: 'makeups' },
];

// A ratio as a whole percent, or null when nothing was eligible — rendered "—", never 0%.
export function percent(ratio) {
    if (!ratio || !ratio.denominator) return null;

    return Math.round((ratio.numerator / ratio.denominator) * 100);
}

export function kpiValue(kpis, kpi) {
    if (!kpis) return null;

    return kpi.rate ? percent(kpis[kpi.key]) : kpis[kpi.key];
}

export function formatKpi(kpis, kpi) {
    const value = kpiValue(kpis, kpi);
    if (value === null || value === undefined) return '—';

    return kpi.rate ? `${value}%` : String(value);
}

// The change shown beside a KPI: a signed whole number (rates in percentage points), its
// direction, and whether that direction is good, bad or neutral (no change). Null when there is
// no change to report — a rate that had nothing eligible in either week.
export function kpiChange(delta, kpi) {
    const raw = delta?.[kpi.deltaKey];
    if (raw === null || raw === undefined) return null;

    const value = kpi.rate ? Math.round(raw / 100) : raw;
    const tone = value === 0 ? 'neutral' : (value > 0) === (kpi.polarity > 0) ? 'good' : 'bad';

    return {
        value,
        text: `${value > 0 ? '+' : ''}${value}`,
        direction: value === 0 ? 'flat' : value > 0 ? 'up' : 'down',
        tone,
        unit: kpi.rate ? 'points' : '',
    };
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function clock(value) {
    const at = new Date(value);

    return `${String(at.getUTCHours()).padStart(2, '0')}:${String(at.getUTCMinutes()).padStart(2, '0')}Z`;
}

// `Sat 10Oct26 23:59Z`
export function stamp(value) {
    if (!value) return null;

    return `${WEEKDAYS[new Date(value).getUTCDay()]} ${forumDate(value)} ${clock(value)}`;
}

export function timeOf(value) {
    return value ? clock(value) : null;
}

export function unitLabel(unit) {
    return unit?.callsign || unit?.name || '';
}

// What the deltas are measured against (5.4), as one sentence under the KPI grid.
export function baselineText(baseline) {
    if (baseline?.kind === 'prior') return `Change compared with your sent report for the week ending ${weekLabel(baseline.week)}.`;
    if (baseline?.kind === 'no_prior') {
        return `No prior baseline: last week was not sent. The last sent report is for the week ending ${weekLabel(baseline.week)}.`;
    }

    return 'First report: no earlier sent report to compare with.';
}

const isLoa = (t) => t.onLoa || t.status === 'loa';
// Excused (by a leader or automatically in a first week, 2026-10-30) is accounted, like leave: nothing to chase.
const signedOrOut = (t) => signatureOf(t) !== 'unsigned' || isLoa(t);
const practiceOut = (t) => isLoa(t) || practiceOf(t) === 'excused';

// The week's unaccounted troopers: marked missed with no makeup. Each needs a reason (5.5).
export function unaccountedTroopers(troopers) {
    return (troopers ?? []).filter((t) => t.status === 'missed');
}

// The readiness checklist (P3). `content` is the leader's live form state; `reviewed` the local
// "I have read the preview" tick. Leaf and roll-up reports check different things. `suggested` lists the troopers
// whose reason is a pre-selected suggestion the leader has not kept yet: their check stays open until they do.
export function readinessChecks({ isLeaf, troopers, children, content, reviewed, suggested = [] }) {
    const reviewCheck = {
        id: 'reviewed',
        title: 'Notes reviewed',
        done: reviewed,
        count: '',
        detail: reviewed ? 'You read the preview as command will see it.' : 'Read the preview, then confirm under your notes.',
        next: 'Read the preview and confirm your notes',
    };

    if (!isLeaf) {
        const kids = children ?? [];
        const sent = kids.filter((c) => c.status === 'sent').length;
        const waiting = kids.filter((c) => c.status !== 'sent').map(unitLabel);

        return [
            {
                id: 'children',
                title: 'Every unit has sent',
                done: waiting.length === 0,
                count: `${sent}/${kids.length}`,
                detail: waiting.length ? `Waiting on ${waiting.join(', ')}.` : 'Every report below you is in.',
                next: `Wait for ${waiting.join(', ')}, or send without them`,
            },
            reviewCheck,
        ];
    }

    const roster = troopers ?? [];
    const signed = roster.filter(signedOrOut).length;
    const eligible = roster.filter((t) => !practiceOut(t));
    const marked = eligible.filter((t) => t.status !== null).length;
    const unmarked = eligible.filter((t) => t.status === null);
    const unaccounted = unaccountedTroopers(roster);
    const reasons = new Map((content.unaccounted ?? []).map((row) => [row.trooper_id, row.reason]));
    const explained = unaccounted.filter((t) => reasons.get(t.id)).length;
    const toConfirm = unaccounted.filter((t) => !reasons.get(t.id) && suggested.includes(t.id));
    const subject = (content.trainingSubject ?? '').trim();

    return [
        {
            id: 'rollcall',
            title: 'Roll call complete',
            done: signed === roster.length,
            count: `${signed}/${roster.length}`,
            detail: signed === roster.length ? 'Everyone signed, is excused or is on LOA.' : `${roster.length - signed} not signed yet.`,
            next: 'Chase the unsigned troopers in roll call',
        },
        {
            id: 'practice',
            title: 'Practice marked',
            done: unmarked.length === 0,
            count: `${marked}/${eligible.length}`,
            detail: unmarked.length ? `${unmarked.map((t) => t.name).join(', ')} not marked.` : 'Every trooper has a practice mark.',
            next: 'Mark the rest of practice in roll call',
        },
        {
            id: 'unaccounted',
            title: 'Unaccounted troopers explained',
            done: explained === unaccounted.length,
            count: `${explained}/${unaccounted.length}`,
            detail:
                explained === unaccounted.length
                    ? 'Every miss without a makeup has a reason command can read.'
                    : toConfirm.length === unaccounted.length - explained
                      ? `${plural(toConfirm.length, 'suggested reason')} to keep or change.`
                      : `${unaccounted.length - explained} ${unaccounted.length - explained === 1 ? 'miss has' : 'misses have'} no reason yet.`,
            next: `Choose a reason for ${unaccounted.filter((t) => !reasons.get(t.id)).map((t) => t.name).join(' and ')}`,
        },
        {
            id: 'subject',
            title: 'Training subject filled',
            done: subject.length >= 3,
            count: '',
            detail: subject.length >= 3 ? `"${subject}"` : 'Command uses it to track what each section trained.',
            next: 'Fill in the training subject',
        },
        reviewCheck,
    ];
}

// The reason recorded with a send (AD-16). Prefilled and editable; an incomplete report says
// which checks were still open, so the audit log shows it was sent knowingly.
export function defaultSendReason(openChecks, missingUnits = []) {
    const open = openChecks.map((c) => c.title.toLowerCase());
    const parts = [];
    if (open.length) parts.push(`open items: ${open.join(', ')}`);
    if (missingUnits.length) parts.push(`without ${missingUnits.join(', ')}`);

    return parts.length ? `Weekly report, sent with ${parts.join('; ')}` : 'Weekly report';
}

function plural(n, word) {
    return `${n} ${word}${n === 1 ? '' : 's'}`;
}

// Highlights written from the week's data (P3). Facts only: each sentence restates something the
// KPIs or the trooper rows already show, so it can never claim what the data does not.
export function highlights({ kpis, delta, troopers, reasons, reasonLabels, children, isLeaf }) {
    if (!kpis) return [];

    const list = [];
    const sp = percent(kpis.sp);
    const spChange = kpiChange(delta, KPIS[2]);
    const makeups = plural(kpis.makeups, 'makeup');

    if (sp === null) {
        list.push({ id: 'sp', icon: 'minus', tone: 'neutral', text: `No practice held in the last 30 days yet, ${makeups} this week.` });
    } else if (spChange && spChange.value !== 0) {
        list.push({
            id: 'sp',
            icon: spChange.direction === 'up' ? 'up' : 'down',
            tone: spChange.tone,
            text: `SP ${spChange.direction} ${Math.abs(spChange.value)} pts to ${sp}% over 30 days, ${makeups} this week.`,
        });
    } else {
        list.push({ id: 'sp', icon: 'minus', tone: 'neutral', text: `SP at ${sp}% over 30 days, ${makeups} this week.` });
    }

    if (!isLeaf) {
        const kids = children ?? [];
        const sent = kids.filter((c) => c.status === 'sent');
        const waiting = kids.filter((c) => c.status !== 'sent').map(unitLabel);
        list.push({
            id: 'children',
            icon: 'stack',
            tone: waiting.length ? 'warn' : 'good',
            text: waiting.length
                ? `${sent.length} of ${kids.length} sent. Waiting on ${waiting.join(', ')}.`
                : `All ${kids.length} reports below this one are in.`,
        });
        sent.filter((c) => c.trainingSubject).forEach((c) =>
            list.push({ id: `trained-${c.id}`, icon: 'target', tone: 'neutral', text: `${unitLabel(c)} trained ${c.trainingSubject}.` }),
        );
        if (kpis.atRisk > 0) {
            list.push({ id: 'risk', icon: 'warning', tone: 'bad', text: `${plural(kpis.atRisk, 'trooper')} at-risk across the sent reports.` });
        }

        return list;
    }

    const rows = troopers ?? [];
    const reasonOf = (id) => reasons?.get(id);
    const reasonText = (id) => (reasonOf(id) ? `Reason: ${reasonLabels[reasonOf(id)].toLowerCase()}.` : 'No reason recorded yet.');

    rows.filter((t) => t.atRisk).forEach((t) =>
        list.push({ id: `risk-${t.id}`, icon: 'warning', tone: 'bad', text: `${t.name} has missed ${t.missed} practices in 30 days and is at-risk. ${reasonText(t.id)}` }),
    );
    rows.filter((t) => t.status === 'missed' && !t.atRisk).forEach((t) =>
        list.push({ id: `miss-${t.id}`, icon: 'miss', tone: 'bad', text: `${t.name} missed practice with no makeup. ${reasonText(t.id)}` }),
    );

    const unmarked = rows.filter((t) => practiceOf(t) === null && !isLoa(t));
    if (unmarked.length && rows.length) {
        list.push({ id: 'unmarked', icon: 'question', tone: 'warn', text: `${plural(unmarked.length, 'trooper')} not marked for this week's practice yet.` });
    }

    if (rows.length) {
        const signed = rows.filter(signedOrOut).length;
        const rc = percent(kpis.rc);
        list.push({
            id: 'rc',
            icon: 'signature',
            tone: signed === rows.length ? 'good' : 'neutral',
            text: `Roll call signed by ${signed} of ${rows.length}${rc === null ? '' : `, RC ${rc}% over 30 days`}.`,
        });
    }

    rows.filter(owesMakeup).forEach((t) =>
        list.push({ id: `owed-${t.id}`, icon: 'makeup', tone: 'warn', text: `${t.name} asked for a make-up and has none recorded yet.` }),
    );
    rows.filter((t) => !isLoa(t) && practiceOf(t) === 'excused').forEach((t) =>
        list.push({ id: `exc-${t.id}`, icon: 'excused', tone: 'neutral', text: `${t.name} is excused this week${t.excused && t.status === null ? ', automatically in their first week' : ''}.` }),
    );

    return list;
}

// A sent report's highlights: only what the frozen snapshot and its notes hold (AD-12).
export function frozenHighlights({ kpis, delta, unaccounted, names, reasonLabels, missingChildren }) {
    const list = highlights({ kpis, delta, troopers: [], isLeaf: true });
    (unaccounted ?? []).forEach((row) =>
        list.push({
            id: `ua-${row.trooper_id}`,
            icon: 'miss',
            tone: 'bad',
            text: `${names[row.trooper_id] ?? 'A trooper no longer in the section'} missed practice. Reason: ${reasonLabels[row.reason]?.toLowerCase() ?? row.reason}.`,
        }),
    );
    if (missingChildren?.length) {
        list.push({ id: 'missing', icon: 'stack', tone: 'warn', text: `Sent without ${plural(missingChildren.length, 'unit')} that had not reported.` });
    }

    return list;
}

// Sentence-level diff of two notes, for History's "changes vs previous".
export function sentenceDiff(before, after) {
    const split = (text) =>
        (text ?? '')
            .split(/(?<=[.!?])\s+/)
            .map((s) => s.trim())
            .filter(Boolean);
    const a = split(before);
    const b = split(after);

    return [
        ...b.map((text) => ({ kind: a.includes(text) ? 'same' : 'added', text })),
        ...a.filter((text) => !b.includes(text)).map((text) => ({ kind: 'removed', text })),
    ];
}
