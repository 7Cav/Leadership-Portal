<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;

/**
 * The leader's part of the regiment's form (7CAV-R-042 for a DCS, 7CAV-R-043 for an LOR, #565): what they fill in on
 * the draft, field by field. Section 1 (the date, the trooper and the authority) is not here: the portal fills it from
 * milpac, live on a draft and snapshotted when shared, so nobody can type another trooper or issuer into it. The
 * trooper's part is {@see CounselingResponse}.
 *
 * Every field is plain text with a length cap, printed as text wherever it shows. A draft may be saved with any of it
 * empty; {@see missing()} says what share still needs. The LOR fields (the recommended consequence, the removal and the
 * special punishments) are dropped from a DCS, whose form has none.
 */
final readonly class CounselingForm
{
    public const LONG_MAX = 3000;

    public const PLAN_MAX = 2000;

    public const SHORT_MAX = 500;

    public const COMMENT_MAX = 1000;

    /** The LOR's recommended consequences, in the form's order (its `Check Box1` to `Check Box4`). */
    public const CONSEQUENCES = ['nfa', 'removal', 'article_15', 'special_punishment'];

    /** What the 2nd-instance removal is from (the form's `Group2`). */
    public const REMOVALS = ['section_leader', 'assistant_section_leader'];

    /** The leader's fields, as stored in `form_data`. */
    public const FIELDS = ['infraction', 'regulation', 'discussion', 'plan_authority', 'plan_other', 'comments_authority', 'comments_other', 'consequences', 'removal', 'special_punishments'];

    /**
     * @param  list<string>  $consequences
     */
    public function __construct(
        public string $infraction = '',
        public string $regulation = '',
        public string $discussion = '',
        public string $planAuthority = '',
        public string $planOther = '',
        public string $commentsAuthority = '',
        public string $commentsOther = '',
        public array $consequences = [],
        public ?string $removal = null,
        public string $specialPunishments = '',
    ) {}

    /**
     * The request's rules for the leader's fields, under `form.`. Nothing is required: a draft saves as it stands.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'form' => ['nullable', 'array'],
            'form.infraction' => ['nullable', 'string', 'max:'.self::LONG_MAX],
            'form.regulation' => ['nullable', 'string', 'max:'.self::SHORT_MAX],
            'form.discussion' => ['nullable', 'string', 'max:'.self::LONG_MAX],
            'form.plan_authority' => ['nullable', 'string', 'max:'.self::PLAN_MAX],
            'form.plan_other' => ['nullable', 'string', 'max:'.self::PLAN_MAX],
            'form.comments_authority' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
            'form.comments_other' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
            'form.consequences' => ['nullable', 'array', 'max:'.count(self::CONSEQUENCES)],
            'form.consequences.*' => ['string', 'distinct', 'in:'.implode(',', self::CONSEQUENCES)],
            'form.removal' => ['nullable', 'string', 'in:'.implode(',', self::REMOVALS)],
            'form.special_punishments' => ['nullable', 'string', 'max:'.self::LONG_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'form.*.max' => 'Keep this to :max characters.',
            'form.consequences.max' => 'Choose a consequence from the list.',
            'form.consequences.*.in' => 'Choose a consequence from the list.',
            'form.consequences.*.distinct' => 'Choose a consequence from the list.',
            'form.removal.in' => 'Choose which position the removal is from.',
        ];
    }

    /**
     * Build the form from validated input (or from a stored `form_data`). Text is trimmed; for a DCS the LOR fields are
     * dropped; a removal is kept only with the removal consequence.
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
            planOther: $text('plan_other'),
            commentsAuthority: $text('comments_authority'),
            commentsOther: $text('comments_other'),
            consequences: $consequences,
            removal: in_array('removal', $consequences, true) && in_array($input['removal'] ?? null, self::REMOVALS, true) ? $input['removal'] : null,
            specialPunishments: $lor ? $text('special_punishments') : '',
        );
    }

    /**
     * The length caps the handler checks again, whatever the request already checked.
     *
     * @throws InvalidCounselingRecordException
     */
    public function check(): void
    {
        $caps = [
            'infraction' => [$this->infraction, self::LONG_MAX],
            'regulation' => [$this->regulation, self::SHORT_MAX],
            'discussion' => [$this->discussion, self::LONG_MAX],
            'plan_authority' => [$this->planAuthority, self::PLAN_MAX],
            'plan_other' => [$this->planOther, self::PLAN_MAX],
            'comments_authority' => [$this->commentsAuthority, self::COMMENT_MAX],
            'comments_other' => [$this->commentsOther, self::COMMENT_MAX],
            'special_punishments' => [$this->specialPunishments, self::LONG_MAX],
        ];

        foreach ($caps as $field => [$value, $max]) {
            if (mb_strlen($value) > $max) {
                throw new InvalidCounselingRecordException("form.{$field}", "Keep this to {$max} characters.");
            }
        }
    }

    /**
     * What share still needs of the leader's fields, as `field => message`, in the form's order: the infraction and
     * its regulation, the notes of discussion and the leader's plan of action, and on an LOR a recommended consequence
     * (with the position, for a removal).
     *
     * @return array<string, string>
     */
    public function missing(CounselingType $type): array
    {
        $missing = [];
        $required = [
            'infraction' => [$this->infraction, 'Give the infraction and its details.'],
            'regulation' => [$this->regulation, 'Give the regulation, order or SOP.'],
            'discussion' => [$this->discussion, 'Write the notes of discussion.'],
            'plan_authority' => [$this->planAuthority, 'Write your plan of action.'],
        ];

        foreach ($required as $field => [$value, $message]) {
            if ($value === '') {
                $missing[$field] = $message;
            }
        }

        if ($type === CounselingType::Lor) {
            if ($this->consequences === []) {
                $missing['consequences'] = 'Choose at least one recommended consequence.';
            } elseif (in_array('removal', $this->consequences, true) && $this->removal === null) {
                $missing['removal'] = 'Choose which position the removal is from.';
            }
        }

        return $missing;
    }

    /**
     * The leader's fields as stored in `form_data`, beside the trooper's ({@see CounselingResponse::toArray()}).
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
            'plan_other' => $this->planOther,
            'comments_authority' => $this->commentsAuthority,
            'comments_other' => $this->commentsOther,
            'consequences' => $this->consequences,
            'removal' => $this->removal,
            'special_punishments' => $this->specialPunishments,
        ];
    }
}
