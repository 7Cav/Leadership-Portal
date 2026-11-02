import { describe, expect, it } from 'vitest';
import { emphasisHtml, plainText } from '../inlineEmphasis';

describe('inline emphasis', () => {
    it('renders italic and bold', () => {
        expect(emphasisHtml('Security: Fixed checks where *Act as* meets the milpac page')).toBe('Security: Fixed checks where <em>Act as</em> meets the milpac page');
        expect(emphasisHtml('The _Roll call_ page')).toBe('The <em>Roll call</em> page');
        expect(emphasisHtml('A **big** one')).toBe('A <strong>big</strong> one');
    });

    it('keeps HTML escaped and leaves links and lone markers as text', () => {
        expect(emphasisHtml('<script>x</script> *<b>hi</b>*')).toBe('&lt;script&gt;x&lt;/script&gt; <em>&lt;b&gt;hi&lt;/b&gt;</em>');
        expect(emphasisHtml('[link](javascript:alert(1))')).toBe('[link](javascript:alert(1))');
        expect(emphasisHtml('2 * 3 and snake_case_name')).toBe('2 * 3 and snake_case_name');
    });

    it('strips the markers for plain text', () => {
        expect(plainText('Fixed *Act as* and **bold** and _Roll call_')).toBe('Fixed Act as and bold and Roll call');
    });
});
