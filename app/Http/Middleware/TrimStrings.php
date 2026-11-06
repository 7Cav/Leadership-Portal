<?php

namespace App\Http\Middleware;

use App\Support\Text\FreeText;
use Illuminate\Foundation\Http\Middleware\TrimStrings as BaseTrimStrings;

/**
 * Laravel's trim, then FreeText's clean, on every request string. The except list (passwords) is honoured as before.
 */
class TrimStrings extends BaseTrimStrings
{
    protected function transform($key, $value)
    {
        $except = array_merge($this->except, static::$neverTrim);

        if ($this->shouldSkip($key, $except) || ! is_string($value)) {
            return $value;
        }

        return FreeText::clean(parent::transform($key, $value));
    }
}
