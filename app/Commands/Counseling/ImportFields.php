<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;

/**
 * What the Admin's browser read from one uploaded PDF's AcroForm before the counseling import's check (#565,
 * `resources/js/lib/counselingImport.js`): which form it is, the Date of Letter as written, the trooper's and the
 * authority's Last.F, and the leader's and the trooper's fields keyed as `form_data` keys them.
 *
 * It comes from the browser, so it is treated as what it is: a hint the Admin's machine produced from a file they
 * chose. Every value is kept only if it has the expected shape, cut to a sane length, and the form fields go through
 * {@see CounselingForm} and {@see CounselingResponse}'s own rules before they are stored. A PDF with no fillable fields
 * (scanned or flattened) has no ImportFields at all: {@see fromInput()} answers null. A PDF the browser could not open
 * at all comes as `{ error: 'unreadable' }`, which is `$unreadable`, so the row says so instead of "scanned or flattened".
 */
final readonly class ImportFields
{
    /** How long a Section 1 value (a date or a name) may be. */
    private const SHORT = 100;

    /**
     * @param  array<string, mixed>  $form
     */
    public function __construct(
        public ?CounselingType $type = null,
        public string $date = '',
        public string $trooper = '',
        public string $issuer = '',
        public array $form = [],
        public bool $unreadable = false,
    ) {}

    /** The fields read from one file, or null when the browser read none (no AcroForm, or not given). */
    public static function fromInput(mixed $input): ?self
    {
        if (! is_array($input)) {
            return null;
        }

        if (($input['error'] ?? null) === 'unreadable') {
            return new self(unreadable: true);
        }

        $short = fn (string $key): string => is_string($input[$key] ?? null) ? mb_substr(trim($input[$key]), 0, self::SHORT) : '';
        $raw = is_array($input['form'] ?? null) ? $input['form'] : [];
        $form = [];

        foreach ([...CounselingForm::FIELDS, 'plan_trooper', 'comments_trooper', 'appeal'] as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value)) {
                // Longer than any field allows; the form's own check refuses what is still too long.
                $form[$key] = mb_substr($value, 0, CounselingForm::LONG_MAX + 1);
            } elseif ($key === 'consequences' && is_array($value)) {
                $form[$key] = array_values(array_filter($value, 'is_string'));
            }
        }

        return new self(
            type: CounselingType::tryFrom(strtolower($short('type'))),
            date: $short('date'),
            trooper: $short('trooper'),
            issuer: $short('issuer'),
            form: $form,
        );
    }
}
