import { describe, expect, it } from 'vitest';
import { defaultTypes, pickerScopeText, typesPhrase } from '@/lib/staffReport';

describe('staff report accountability copy', () => {
    it('words the default types as before', () => {
        expect(typesPhrase(defaultTypes('battalion'), true)).toBe('a section practice, an operation, a class or another official event');
        expect(typesPhrase(defaultTypes('battalion'))).toBe('a section practice, an operation, a class or an event');
        expect(typesPhrase(defaultTypes('company'))).toBe('a section practice or an FTX');
        expect(typesPhrase(['ftx'])).toBe('an FTX');
    });

    it('says how far the picker reaches', () => {
        expect(pickerScopeText('company', null)).toBe('Sections in your company that play its game.');
        expect(pickerScopeText('platoon', { own: true, battalion: false, otherGames: false })).toBe('Sections in your platoon that play its game.');
        expect(pickerScopeText('platoon', { own: false, battalion: true, otherGames: false })).toBe('Sections elsewhere in your battalion that play its game.');
        expect(pickerScopeText('company', { own: true, battalion: true, otherGames: true })).toBe('Sections in your battalion.');
    });
});
