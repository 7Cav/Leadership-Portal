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
