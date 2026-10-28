import { describe, expect, it } from 'vitest';
import { currentUnitId, scopeLabel, unitHref } from '../scopeSwitch';

const section = { id: 5, type: 'section', line: true };
const platoon = { id: 4, type: 'platoon', line: true };
const staff = { id: 9, type: 'group', line: false };

describe('unitHref', () => {
    it('opens the roster for the unit, keeping a known view', () => {
        expect(unitHref(platoon, '/roster/3?view=matrix')).toBe('/roster/4?view=matrix');
        expect(unitHref(platoon, '/roster')).toBe('/roster/4');
        expect(unitHref(platoon, '/roster/3?view=<script>')).toBe('/roster/4');
    });

    it('opens a section\'s rollcall for the same week, and the roster for a unit that is not a section', () => {
        expect(unitHref(section, '/rollcall/6?week=2026-10-17&step=2')).toBe('/rollcall/5?week=2026-10-17');
        expect(unitHref(section, '/rollcall')).toBe('/rollcall/5');
        expect(unitHref(platoon, '/rollcall/6')).toBe('/roster/4');
    });

    it('opens a line unit\'s report or roll-up for the same week, and the roster for a group', () => {
        expect(unitHref(platoon, '/reports/3/2026-10-17')).toBe('/reports/4/2026-10-17');
        expect(unitHref(platoon, '/reports?week=2026-10-17')).toBe('/reports/4?week=2026-10-17');
        expect(unitHref(platoon, '/reports/3')).toBe('/reports/4');
        expect(unitHref(staff, '/reports/3/2026-10-17')).toBe('/roster/9');
    });

    it('opens the roster from any other page', () => {
        expect(unitHref(section, '/home')).toBe('/roster/5');
        expect(unitHref(section, '/troopers/12')).toBe('/roster/5');
        expect(unitHref(section, '/settings/org-structure')).toBe('/roster/5');
        expect(unitHref(section, '/reports/3/plans/2026-11')).toBe('/roster/5');
    });

    it('drops a week that is not a date', () => {
        expect(unitHref(section, '/rollcall/6?week=soon')).toBe('/rollcall/5');
    });
});

describe('scopeLabel', () => {
    it('names the top unit, the first with how many more, or the regiment with none', () => {
        expect(scopeLabel([{ designation: '1-7' }])).toBe('1-7');
        expect(scopeLabel([{ designation: '1-7' }, { designation: 'E/2-7' }, { designation: 'S1' }])).toBe('1-7 +2');
        expect(scopeLabel([])).toBe('7CAV');
        expect(scopeLabel(undefined)).toBe('7CAV');
    });
});

describe('currentUnitId', () => {
    it('reads the roster\'s unit or the rollcall\'s section', () => {
        expect(currentUnitId({ unit: { id: 3 } })).toBe(3);
        expect(currentUnitId({ section: { id: 5 } })).toBe(5);
        expect(currentUnitId({})).toBeNull();
    });
});
