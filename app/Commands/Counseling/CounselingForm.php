<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;

/**
 * What a leader fills in when the portal makes the DCS or LOR document from the regiment's own form (7CAV-R-042 or
 * 7CAV-R-043) instead of taking an uploaded PDF. Section 1 (the date, the trooper and the issuing authority) is not
 * here: the portal fills it from its own records when it renders ({@see CounselingFormParties}), so nobody can type
 * another trooper or issuer into it.
 *
 * Every field is plain text with a length cap. The text is only ever printed escaped by the Blade view
 * (`resources/views/pdf/counseling.blade.php`), so markup or Markdown in it stays text. The LOR fields (the recommended
 * consequence, the special punishments and the appeal choice) are dropped from a DCS, whose form covers them with fixed
 * text. The appeal outcome is a later step and is never filled in here.
 */
final readonly class CounselingForm
{
    public const LONG_MAX = 3000;

    public const PLAN_MAX = 2000;

    public const SHORT_MAX = 500;

    public const COMMENT_MAX = 1000;

    /** The LOR's recommended consequences, as on the form. */
    public const CONSEQUENCES = ['nfa', 'removal', 'article_15', 'special_punishment'];

    /** What the 2nd-instance removal is from. */
    public const REMOVALS = ['section_leader', 'assistant_section_leader'];

    /** Whether the trooper wishes to appeal. */
    public const APPEALS = ['do', 'do_not'];

    /**
     * @param  list<string>  $consequences
     */
    public function __construct(
        public string $infraction,
        public string $regulation,
        public string $discussion,
        public string $planAuthority,
        public string $planTrooper = '',
        public string $planOther = '',
        public string $commentsAuthority = '',
        public string $commentsTrooper = '',
        public string $commentsOther = '',
        public array $consequences = [],
        public ?string $removal = null,
        public string $specialPunishments = '',
        public ?string $appeal = null,
    ) {}

    /**
     * The request's rules for the form's fields, under `form.`. The type decides whether the LOR fields are asked for.
     * A removal without its position is caught by {@see check()}.
     *
     * @return array<string, mixed>
     */
    public static function rules(?CounselingType $type): array
    {
        $rules = [
            'form' => ['required', 'array'],
            'form.infraction' => ['required', 'string', 'max:'.self::LONG_MAX],
            'form.regulation' => ['required', 'string', 'max:'.self::SHORT_MAX],
            'form.discussion' => ['required', 'string', 'max:'.self::LONG_MAX],
            'form.plan_authority' => ['required', 'string', 'max:'.self::PLAN_MAX],
            'form.plan_trooper' => ['nullable', 'string', 'max:'.self::PLAN_MAX],
            'form.plan_other' => ['nullable', 'string', 'max:'.self::PLAN_MAX],
            'form.comments_authority' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
            'form.comments_trooper' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
            'form.comments_other' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
        ];

        if ($type === CounselingType::Lor) {
            $rules += [
                'form.consequences' => ['required', 'array', 'min:1', 'max:'.count(self::CONSEQUENCES)],
                'form.consequences.*' => ['string', 'distinct', 'in:'.implode(',', self::CONSEQUENCES)],
                'form.removal' => ['nullable', 'string', 'in:'.implode(',', self::REMOVALS)],
                'form.special_punishments' => ['nullable', 'string', 'max:'.self::LONG_MAX],
                'form.appeal' => ['required', 'string', 'in:'.implode(',', self::APPEALS)],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'form.required' => 'Fill in the form.',
            'form.infraction.required' => 'Give the infraction and its details.',
            'form.regulation.required' => 'Give the regulation, order or SOP.',
            'form.discussion.required' => 'Give the notes of the discussion.',
            'form.plan_authority.required' => 'Give the plan of action for the authority.',
            'form.*.max' => 'Keep this to :max characters.',
            'form.consequences.required' => 'Choose at least one recommended consequence.',
            'form.consequences.min' => 'Choose at least one recommended consequence.',
            'form.consequences.max' => 'Choose a consequence from the list.',
            'form.consequences.*.in' => 'Choose a consequence from the list.',
            'form.consequences.*.distinct' => 'Choose a consequence from the list.',
            'form.removal.in' => 'Choose which position the removal is from.',
            'form.appeal.required' => 'Say whether the trooper wishes to appeal.',
            'form.appeal.in' => 'Say whether the trooper wishes to appeal.',
        ];
    }

    /**
     * Build the form from validated input. Text is trimmed; for a DCS the LOR fields are dropped.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, CounselingType $type): self
    {
        $text = fn (string $key): string => trim(is_string($input[$key] ?? null) ? $input[$key] : '');
        $lor = $type === CounselingType::Lor;
        $picked = is_array($input['consequences'] ?? null) ? $input['consequences'] : [];
        $consequences = $lor ? array_values(array_filter(self::CONSEQUENCES, fn (string $c) => in_array($c, $picked, true))) : [];

        return new self(
            infraction: $text('infraction'),
            regulation: $text('regulation'),
            discussion: $text('discussion'),
            planAuthority: $text('plan_authority'),
            planTrooper: $text('plan_trooper'),
            planOther: $text('plan_other'),
            commentsAuthority: $text('comments_authority'),
            commentsTrooper: $text('comments_trooper'),
            commentsOther: $text('comments_other'),
            consequences: $consequences,
            removal: in_array('removal', $consequences, true) && in_array($input['removal'] ?? null, self::REMOVALS, true) ? $input['removal'] : null,
            specialPunishments: $lor ? $text('special_punishments') : '',
            appeal: $lor && in_array($input['appeal'] ?? null, self::APPEALS, true) ? $input['appeal'] : null,
        );
    }

    /**
     * The checks the handler makes again, whatever the request already checked.
     *
     * @throws InvalidCounselingRecordException
     */
    public function check(CounselingType $type): void
    {
        $required = [
            'infraction' => [$this->infraction, self::LONG_MAX, 'Give the infraction and its details.'],
            'regulation' => [$this->regulation, self::SHORT_MAX, 'Give the regulation, order or SOP.'],
            'discussion' => [$this->discussion, self::LONG_MAX, 'Give the notes of the discussion.'],
            'plan_authority' => [$this->planAuthority, self::PLAN_MAX, 'Give the plan of action for the authority.'],
        ];

        foreach ($required as $field => [$value, $max, $missing]) {
            if ($value === '') {
                throw new InvalidCounselingRecordException("form.{$field}", $missing);
            }
            self::cap($field, $value, $max);
        }

        $optional = [
            'plan_trooper' => [$this->planTrooper, self::PLAN_MAX],
            'plan_other' => [$this->planOther, self::PLAN_MAX],
            'comments_authority' => [$this->commentsAuthority, self::COMMENT_MAX],
            'comments_trooper' => [$this->commentsTrooper, self::COMMENT_MAX],
            'comments_other' => [$this->commentsOther, self::COMMENT_MAX],
            'special_punishments' => [$this->specialPunishments, self::LONG_MAX],
        ];

        foreach ($optional as $field => [$value, $max]) {
            self::cap($field, $value, $max);
        }

        if ($type !== CounselingType::Lor) {
            return;
        }

        if ($this->consequences === []) {
            throw new InvalidCounselingRecordException('form.consequences', 'Choose at least one recommended consequence.');
        }

        if (in_array('removal', $this->consequences, true) && $this->removal === null) {
            throw new InvalidCounselingRecordException('form.removal', 'Choose which position the removal is from.');
        }

        if ($this->appeal === null) {
            throw new InvalidCounselingRecordException('form.appeal', 'Say whether the trooper wishes to appeal.');
        }
    }

    /**
     * What is stored on the record (`form_data`) and printed: the leader's fields only, never section 1.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'infraction' => $this->infraction,
            'regulation' => $this->regulation,
            'discussion' => $this->discussion,
            'plan_authority' => $this->planAuthority,
            'plan_trooper' => $this->planTrooper,
            'plan_other' => $this->planOther,
            'comments_authority' => $this->commentsAuthority,
            'comments_trooper' => $this->commentsTrooper,
            'comments_other' => $this->commentsOther,
            'consequences' => $this->consequences,
            'removal' => $this->removal,
            'special_punishments' => $this->specialPunishments,
            'appeal' => $this->appeal,
        ];
    }

    private static function cap(string $field, string $value, int $max): void
    {
        if (mb_strlen($value) > $max) {
            throw new InvalidCounselingRecordException("form.{$field}", "Keep this to {$max} characters.");
        }
    }
}
