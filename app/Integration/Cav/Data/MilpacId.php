<?php

namespace App\Integration\Cav\Data;

/**
 * The strict parse for a milpac relation id, shared by every path that reads one off the wire
 * (a roster map key, or the body's `user.userId`).
 *
 * Only digits are acceptable — `is_numeric` would admit `'4.9'` and `'1e3'`, and the `(int)`
 * cast would then quietly truncate either into a *different, valid* trooper's id. For a value
 * that decides which human being's record is used, "close enough" is the whole failure mode, so
 * this is the one predicate every caller shares rather than each reimplementing its own.
 */
final class MilpacId
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
