// The sidebar's unit switcher (#426): navigation only. It never changes scope; each page checks the unit again.

const WEEK = /^\d{4}-\d{2}-\d{2}$/;
const ROSTER_VIEWS = ['orbat', 'matrix'];

// Where choosing `unit` goes from the page at `url`: the same page for that unit when the page is about one (the
// roster, a section's rollcall, a report or roll-up for the same week), else that unit's roster. `unit` is a row of
// `/scope/units`: { id, type, line }.
export function unitHref(unit, url) {
    const current = new URL(url ?? '/', 'http://portal.invalid');
    const path = current.pathname;
    const week = WEEK.test(current.searchParams.get('week') ?? '') ? current.searchParams.get('week') : null;
    const weekQuery = week ? `?week=${week}` : '';
    const roster = (view) => `/roster/${unit.id}${ROSTER_VIEWS.includes(view) ? `?view=${view}` : ''}`;

    if (/^\/roster(\/\d+)?$/.test(path)) return roster(current.searchParams.get('view'));
    if (/^\/rollcall(\/\d+)?$/.test(path) && unit.type === 'section') return `/rollcall/${unit.id}${weekQuery}`;

    const report = path.match(/^\/reports\/\d+\/(\d{4}-\d{2}-\d{2})$/);
    if (report && unit.line) return `/reports/${unit.id}/${report[1]}`;
    if (/^\/reports(\/\d+)?$/.test(path) && unit.line) return `/reports/${unit.id}${weekQuery}`;

    return roster(null);
}

// What the shell calls the viewer's scope: its top unit, or the first with how many more ("1-7 +1"). With no unit in
// scope, the regiment's short name, as before.
export function scopeLabel(tops) {
    if (!tops?.length) return '7CAV';

    return tops.length === 1 ? tops[0].designation : `${tops[0].designation} +${tops.length - 1}`;
}

// The unit the page on screen is about, to mark in the switcher: the roster's unit or the rollcall's section.
export function currentUnitId(props) {
    return props?.unit?.id ?? props?.section?.id ?? null;
}
