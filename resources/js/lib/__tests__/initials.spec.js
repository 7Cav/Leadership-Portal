import { describe, expect, it } from 'vitest';
import { initialsOf } from '../initials';

describe('initialsOf', () => {
    it.each([
        ['Hale.S', 'HS'],
        ['Perry.JW', 'PJ'],
        ['Milkovic.D', 'MD'],
        ['Vasquez R', 'VR'],
        ['a_b_c', 'AB'],
        ['Madonna', 'M'],
        ['  .  ', ''],
        ['', ''],
        [null, ''],
        [undefined, ''],
    ])('draws %j as %j', (name, letters) => {
        expect(initialsOf(name)).toBe(letters);
    });
});
