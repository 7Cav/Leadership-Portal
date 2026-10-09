// A small roster as RosterController shapes it: a platoon with two sections, 20 Cav weeks ending
// Saturday 2026-10-03.
export const WEEKS = Array.from({ length: 20 }, (_, i) => {
    const d = new Date(Date.UTC(2026, 9, 3));
    d.setUTCDate(d.getUTCDate() - 7 * (19 - i));

    return d.toISOString().slice(0, 10);
});
export const CURRENT = '2026-10-03';

const kpis = (over = {}) => ({ strength: 3, rc: [10, 12], sp: [8, 10], atRisk: 0, onLoa: 0, ...over });
const present = { rollcall: 'will_attend', status: 'present', source: 'manual', held: true };
const missed = { rollcall: 'unsigned', status: 'missed', source: 'manual', held: true };

export function trooper(id, sectionId, over = {}) {
    const t = {
        id,
        cavId: 4000 + id,
        name: `Trooper${id}.T`,
        rank: 'PFC',
        rankFull: 'Private First Class',
        billet: 'Rifleman',
        status: 'combat',
        departed: false,
        sectionId,
        role: null,
        joined: '2024-01-01',
        kpis: { rc: [4, 4], sp: [4, 4], missed: 0, atRisk: false, onLoa: false },
        weeks: WEEKS.map(() => present),
        ...over,
    };

    return { displayName: `${t.rank} ${t.name}`, ...t };
}

export function roster() {
    return {
        units: [
            { id: 1, type: 'platoon', name: '1st Platoon', callsign: '1/A/1-7', parentId: 9, leader: { id: 50, cavId: 50, rank: '1LT', name: 'Brennan.J', displayName: '1LT Brennan.J' }, kpis: kpis({ strength: 4, atRisk: 1 }) },
            { id: 2, type: 'section', name: '1st Section', callsign: '1/1/A/1-7', parentId: 1, leader: { id: 10, cavId: 10, rank: 'SGT', name: 'Abernathy.L', displayName: 'SGT Abernathy.L' }, kpis: kpis({ strength: 2 }) },
            { id: 3, type: 'section', name: '2nd Section', callsign: '2/1/A/1-7', parentId: 1, leader: null, kpis: kpis({ strength: 2, atRisk: 1 }) },
        ],
        troopers: [
            trooper(10, 2, { name: 'Abernathy.L', rank: 'SGT', role: 'SL', billet: 'Section Leader' }),
            trooper(11, 2, { name: 'Ferreira.D', billet: 'Combat Medic' }),
            trooper(12, 3, { name: 'Reyes.M', rank: 'PVT', kpis: { rc: [1, 4], sp: [1, 4], missed: 3, atRisk: true, onLoa: false }, weeks: WEEKS.map((w, i) => (i > 16 ? missed : present)) }),
            trooper(13, 3, { name: 'Tanaka.H', kpis: { rc: [3, 3], sp: [3, 3], missed: 0, atRisk: false, onLoa: true } }),
        ],
    };
}
