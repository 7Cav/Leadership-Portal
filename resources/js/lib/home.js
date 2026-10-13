import { KPIS, kpiValue } from '@/lib/report';

// Presentation rules for the Home dashboard tiles (Story 5.9). The numbers come from the server
// (KpiService and sent snapshots, AD-21); this only turns them into a series per KPI.

// The tile labels of dashboard-v1-tiles.html. `KPIS` carries the order, polarity and delta keys.
const LABELS = {
    strength: 'Strength',
    rc: 'RC % · 30d',
    sp: 'SP % · 30d',
    atRisk: 'At-risk',
    onLoa: 'On LOA',
    makeups: 'Makeups',
};

export const TILES = KPIS.map((kpi) => ({ ...kpi, label: LABELS[kpi.key] }));

// One KPI over the trailing whole weeks (oldest first) and now. A week that has no sent snapshot
// for the whole scope is a gap (`value: null`), never an estimate.
export function tileSeries(kpi, current, history) {
    return [
        ...history.map((point) => ({ week: point.week, value: kpiValue(point.kpis, kpi), live: false })),
        { week: null, value: kpiValue(current, kpi), live: true },
    ];
}

// A sparkline needs at least one sent week behind the live point, and two points to draw a line.
export function hasTrend(series) {
    return series.filter((point) => point.value !== null).length >= 2 && series.some((point) => !point.live && point.value !== null);
}

// The change since the latest sent week, in the shape `DeltaBadge` takes; null without a baseline.
export function tileChange(kpi, series) {
    const now = series[series.length - 1].value;
    const before = [...series.slice(0, -1)].reverse().find((point) => point.value !== null)?.value;
    if (now === null || before === undefined) return null;

    const value = now - before;
    const tone = value === 0 ? 'neutral' : (value > 0) === (kpi.polarity > 0) ? 'good' : 'bad';

    return {
        value,
        text: `${value > 0 ? '+' : ''}${value}`,
        direction: value === 0 ? 'flat' : value > 0 ? 'up' : 'down',
        tone,
        unit: kpi.rate ? 'points' : '',
    };
}

// The week's step matrix and lanes. Every state here is "done" or "not yet": the portal defines no
// per-step deadline, so a step is never shown as overdue on a guess.

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export const STEPS = ['Rollcall posted', 'Rollcall signed', 'SP held', 'Attendance marked', 'Report sent'];

const weekday = (value) => WEEKDAYS[new Date(value).getUTCDay()];

// One section's five cells: `{ state: ok | wait | none, text }`, and for the signed step a
// `percent` (null when the section has no troopers, so there is nothing to sign).
export function stepCells(step) {
    const hasTroopers = step.strength > 0;

    return {
        posted: step.postedAt ? { state: 'ok', text: weekday(step.postedAt) } : { state: 'wait', text: 'Not posted' },
        signed: {
            state: !hasTroopers ? 'none' : step.signed === step.strength ? 'ok' : 'wait',
            text: !hasTroopers ? 'No troopers' : `${step.signed} of ${step.strength} signed`,
            percent: hasTroopers ? Math.round((step.signed / step.strength) * 100) : null,
        },
        held: step.held ? { state: 'ok', text: 'Held' } : { state: 'wait', text: 'Not held' },
        marked: !hasTroopers
            ? { state: 'none', text: 'No troopers' }
            : { state: step.marked === step.strength ? 'ok' : 'wait', text: `${step.marked} of ${step.strength} marked` },
        report: {
            sent: { state: 'ok', text: step.sentAt ? `Sent ${weekday(step.sentAt)}` : 'Sent' },
            draft: { state: 'warn', text: 'Draft' },
            not_started: { state: 'wait', text: 'Not started' },
        }[step.report],
    };
}

// The week bar's five lanes: the same steps added up across every section in scope.
export function laneTotals(steps) {
    const count = (predicate) => steps.filter(predicate).length;
    const sum = (key) => steps.reduce((total, step) => total + step[key], 0);
    const lane = (label, done, of, text) => ({ label, done, of, text, fill: of ? done / of : 0, complete: of > 0 && done === of });

    const sent = count((step) => step.report === 'sent');
    const draft = count((step) => step.report === 'draft');

    return [
        lane('Rollcall posted', count((step) => step.postedAt), steps.length, `${count((step) => step.postedAt)} of ${steps.length} posted`),
        lane('Rollcall signed', sum('signed'), sum('strength'), `${sum('signed')} of ${sum('strength')} signed`),
        lane('SP held', count((step) => step.held), steps.length, `${count((step) => step.held)} of ${steps.length} held`),
        lane('Attendance marked', sum('marked'), sum('strength'), `${sum('marked')} of ${sum('strength')} marked`),
        lane('Report sent', sent, steps.length, `${sent} sent, ${draft} draft, ${steps.length - sent - draft} not started`),
    ];
}
