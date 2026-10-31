<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;

/**
 * The trooper's part of the regiment's form (#565), submitted once on a shared record: their plan of action and their
 * comments, and on an LOR whether they DO or DO NOT wish to appeal, with their reason when they do. Plain text with
 * length caps, printed as text wherever it shows.
 */
final readonly class CounselingResponse
{
    public const APPEALS = ['do', 'do_not'];

    public const REASON_MAX = 2000;

    public function __construct(
        public string $planTrooper = '',
        public string $commentsTrooper = '',
        public ?string $appeal = null,
        public string $appealReason = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?CounselingType $type): array
    {
        $rules = [
            'plan_trooper' => ['nullable', 'string', 'max:'.CounselingForm::PLAN_MAX],
            'comments_trooper' => ['nullable', 'string', 'max:'.CounselingForm::COMMENT_MAX],
        ];

        if ($type === CounselingType::Lor) {
            $rules += [
                'appeal' => ['required', 'string', 'in:'.implode(',', self::APPEALS)],
                'appeal_reason' => ['nullable', 'required_if:appeal,do', 'string', 'max:'.self::REASON_MAX],
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
            'plan_trooper.max' => 'Keep this to :max characters.',
            'comments_trooper.max' => 'Keep this to :max characters.',
            'appeal.required' => 'Say whether you wish to appeal.',
            'appeal.in' => 'Say whether you wish to appeal.',
            'appeal_reason.required_if' => 'Say why you appeal.',
            'appeal_reason.max' => 'Keep your reason to :max characters.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, CounselingType $type): self
    {
        $text = fn (string $key): string => trim(is_string($input[$key] ?? null) ? $input[$key] : '');
        $appeal = $type === CounselingType::Lor && in_array($input['appeal'] ?? null, self::APPEALS, true) ? $input['appeal'] : null;

        return new self(
            planTrooper: $text('plan_trooper'),
            commentsTrooper: $text('comments_trooper'),
            appeal: $appeal,
            appealReason: $appeal === 'do' ? $text('appeal_reason') : '',
        );
    }

    /**
     * The checks the handler makes again, whatever the request already checked.
     *
     * @throws InvalidCounselingRecordException
     */
    public function check(CounselingType $type): void
    {
        foreach (['plan_trooper' => [$this->planTrooper, CounselingForm::PLAN_MAX], 'comments_trooper' => [$this->commentsTrooper, CounselingForm::COMMENT_MAX], 'appeal_reason' => [$this->appealReason, self::REASON_MAX]] as $field => [$value, $max]) {
            if (mb_strlen($value) > $max) {
                throw new InvalidCounselingRecordException($field, "Keep this to {$max} characters.");
            }
        }

        if ($type !== CounselingType::Lor) {
            return;
        }

        if ($this->appeal === null) {
            throw new InvalidCounselingRecordException('appeal', 'Say whether you wish to appeal.');
        }

        if ($this->appeal === 'do' && $this->appealReason === '') {
            throw new InvalidCounselingRecordException('appeal_reason', 'Say why you appeal.');
        }
    }

    /** This response as `$type`'s form takes it: a DCS has no appeal, so its choice and reason are dropped. */
    public function forType(CounselingType $type): self
    {
        return $type === CounselingType::Lor ? $this : new self($this->planTrooper, $this->commentsTrooper);
    }

    public function appeals(): bool
    {
        return $this->appeal === 'do';
    }

    /**
     * The trooper's fields as stored in `form_data`, beside the leader's. The appeal reason is kept on the record's own
     * column (`appeal_statement`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_trooper' => $this->planTrooper,
            'comments_trooper' => $this->commentsTrooper,
            'appeal' => $this->appeal,
        ];
    }
}
