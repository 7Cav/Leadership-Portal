import { PhArrowUUpLeft, PhBroadcast, PhCircleDashed, PhLockSimple, PhUserCheck } from '@phosphor-icons/vue';

// The shared attendance-provenance vocabulary (Story 3.9, AD-20): how a Section Practice mark came
// to be, read only from `attendance.source` and — for makeup — `attendance.status`, never
// re-derived from anything else. The rollcall, section-overview and report screens all render it
// through AttendanceSourceIndicator / AttendanceSourceLegend, so one legend means one thing.
export const SOURCES = {
    auto: { label: 'Auto', detail: 'Captured automatically from the game server.', icon: PhBroadcast, tone: 'text-info' },
    manual: { label: 'Manual', detail: 'Marked by hand by a leader.', icon: PhUserCheck, tone: 'text-foreground' },
    makeup: { label: 'Makeup', detail: 'Made up at another practice, marked by hand.', icon: PhArrowUUpLeft, tone: 'text-info' },
    loa: { label: 'LOA', detail: 'On leave of absence, from LOA records. Nothing to mark.', icon: PhLockSimple, tone: 'text-muted-foreground' },
    unmarked: { label: 'Not marked', detail: 'No practice mark recorded yet.', icon: PhCircleDashed, tone: 'text-muted-foreground' },
};

// Which provenance a row shows. LOA wins (it is derived, not marked), then a hand-marked makeup (from
// `status`), then the stored `source`. An automatic makeup (`source = auto`, not written by the capture
// until practice time slots exist) reads as auto: the status buttons already say makeup, and "marked by hand"
// would be wrong. A row with a status but no source cannot be attributed, so it reads as manual only
// when the source says so.
export function provenanceOf({ status, source }) {
    if (status === 'loa') return 'loa';
    if (status === null || status === undefined) return 'unmarked';
    if (status === 'makeup' && source !== 'auto') return 'makeup';
    if (source === 'auto') return 'auto';
    if (source === 'manual') return 'manual';

    return 'unmarked';
}
