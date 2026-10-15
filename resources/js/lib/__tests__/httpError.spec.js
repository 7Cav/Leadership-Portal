import { describe, expect, it, vi } from 'vitest';
import { httpErrorMessage, onHttpException } from '../httpError';

describe('httpError', () => {
    it.each([
        [403, 'The unit was not saved. You are not allowed to do that; reload the page, or exit view as first.'],
        [419, 'The unit was not saved. Your session expired; reload the page and try again.'],
        [429, 'The unit was not saved. Too many requests; wait a minute and try again.'],
        [500, 'The unit was not saved (error 500). Try again.'],
    ])('says what to do after a %i', (status, expected) => {
        expect(httpErrorMessage({ status }, 'The unit was not saved')).toBe(expected);
    });

    it('hands the message on and stops Inertia showing its own error dialog', () => {
        const show = vi.fn();

        expect(onHttpException('The unit was not saved', show)({ status: 503 })).toBe(false);
        expect(show).toHaveBeenCalledWith('The unit was not saved (error 503). Try again.');
    });
});
