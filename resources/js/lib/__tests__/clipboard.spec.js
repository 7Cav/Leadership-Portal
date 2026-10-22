import { beforeEach, describe, expect, it, vi } from 'vitest';
import { copyText } from '../clipboard';

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { success: (...a) => toastSuccess(...a), error: (...a) => toastError(...a) } }));

function clipboard(value) {
    Object.defineProperty(navigator, 'clipboard', { value, configurable: true });
}

describe('copyText', () => {
    beforeEach(() => {
        toastSuccess.mockClear();
        toastError.mockClear();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('copies the text and confirms with a plain "Copied" by default', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        clipboard({ writeText });

        expect(await copyText('{{name}}')).toBe(true);

        expect(writeText).toHaveBeenCalledWith('{{name}}');
        expect(toastSuccess).toHaveBeenCalledWith('Copied', { description: undefined });
        expect(toastError).not.toHaveBeenCalled();
    });

    it('confirms with the label and description it is given', async () => {
        clipboard({ writeText: vi.fn().mockResolvedValue(undefined) });

        await copyText('text', 'Reminder copied', { description: 'Paste it to the leader.' });

        expect(toastSuccess).toHaveBeenCalledWith('Reminder copied', { description: 'Paste it to the leader.' });
    });

    it('says the browser blocked it when clipboard access is denied', async () => {
        const denied = Object.assign(new Error('denied'), { name: 'NotAllowedError' });
        clipboard({ writeText: vi.fn().mockRejectedValue(denied) });

        expect(await copyText('text', 'Copied', { failed: 'Could not copy the BBCode', fallback: 'Copy it by hand.' })).toBe(false);

        expect(toastError).toHaveBeenCalledWith('Could not copy the BBCode', {
            description: 'The browser blocked clipboard access. Allow it for this site and try again.',
        });
        expect(toastSuccess).not.toHaveBeenCalled();
        expect(console.error).toHaveBeenCalled();
    });

    it('points to the fallback when there is no clipboard at all (plain HTTP)', async () => {
        clipboard(undefined);

        expect(await copyText('text', 'Copied', { fallback: 'Open the report and send its link instead.' })).toBe(false);

        expect(toastError).toHaveBeenCalledWith('Could not copy', {
            description: 'This browser cannot copy from this page. Open the report and send its link instead.',
        });
    });

    it('still says why without a fallback', async () => {
        clipboard({ writeText: vi.fn().mockRejectedValue(new Error('boom')) });

        await copyText('text');

        expect(toastError).toHaveBeenCalledWith('Could not copy', { description: 'This browser cannot copy from this page.' });
    });
});
