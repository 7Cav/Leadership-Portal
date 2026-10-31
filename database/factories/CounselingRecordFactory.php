<?php

namespace Database\Factories;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingSource;
use App\Models\CounselingStatus;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * By default a record as one filed before #565 became: shared and acknowledged now, kept with an uploaded source document
 * that has no file behind it on the disk (tests that download one put a file at `document_path`). It has no kind, like
 * a record filed before the portal asked; `missedPractice()` and `conduct()` set one. `draft()` and `written()` give a
 * record written in the portal; `sharedDaysAgo()` and `sharedHoursAgo()` move its windows.
 *
 * @extends Factory<CounselingRecord>
 */
class CounselingRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trooper_id' => Trooper::factory(),
            'type' => CounselingType::Dcs,
            'status' => CounselingStatus::Shared,
            'source' => CounselingSource::Upload,
            'version' => 1,
            'counseled_on' => now()->subDays(10)->toDateString(),
            'reason' => 'Missed two section practices without notice.',
            'issued_by' => User::factory(),
            'document_path' => Str::uuid()->toString().'.pdf',
            'document_name' => 'counseling.pdf',
            'document_size' => 1024,
            'document_sha256' => str_repeat('a', 64),
            'meeting_confirmed' => true,
            'shared_at' => now(),
            'responded_at' => now(),
        ];
    }

    public function missedPractice(): static
    {
        return $this->state(['kind' => CounselingKind::MissedPractice]);
    }

    public function conduct(): static
    {
        return $this->state(['kind' => CounselingKind::Conduct]);
    }

    public function lor(): static
    {
        return $this->state(['type' => CounselingType::Lor]);
    }

    /**
     * Written in the portal: no source document, the leader's fields filled in, shared now and not yet answered.
     */
    public function written(): static
    {
        return $this->state([
            'source' => CounselingSource::Portal,
            'kind' => CounselingKind::Conduct,
            'reason' => null,
            'document_path' => null,
            'document_name' => null,
            'document_size' => null,
            'document_sha256' => null,
            'responded_at' => null,
            'form_data' => [
                'infraction' => 'Missed section practice on 10, 17 and 24 Oct without an LOA.',
                'regulation' => '2/B/1-7 Platoon SOP, attendance.',
                'discussion' => 'Work shifts changed; went through the LOA process together.',
                'plan_authority' => 'Check in weekly before practice for four weeks.',
                'plan_other' => '',
                'comments_authority' => '',
                'comments_other' => '',
                'consequences' => [],
                'removal' => null,
                'special_punishments' => '',
            ],
        ]);
    }

    /** A draft written in the portal, not yet shared, with no date. */
    public function draft(): static
    {
        return $this->written()->state([
            'status' => CounselingStatus::Draft,
            'counseled_on' => null,
            'meeting_confirmed' => false,
            'shared_at' => null,
        ]);
    }

    /** Answered by the trooper when it was shared. */
    public function responded(): static
    {
        return $this->state(fn (array $attributes) => ['responded_at' => $attributes['shared_at'] ?? now()]);
    }

    /** Shared `$days` days ago (UTC), and filed then. */
    public function sharedDaysAgo(int $days): static
    {
        return $this->sharedHoursAgo($days * 24);
    }

    /** Shared and filed `$hours` hours ago: 73 by default, past the 72 hours to its lock, so it is frozen. */
    public function sharedHoursAgo(int $hours = 73): static
    {
        return $this->state(function (array $attributes) use ($hours) {
            $at = now()->subHours($hours);

            return [
                'created_at' => $at,
                'updated_at' => $at,
                'shared_at' => $at,
                'responded_at' => ($attributes['responded_at'] ?? null) === null ? null : $at,
            ];
        });
    }

    /** The old name of {@see sharedHoursAgo()}: before #565 a record was filed and shared in one step. */
    public function filedHoursAgo(int $hours = 73): static
    {
        return $this->sharedHoursAgo($hours);
    }
}
