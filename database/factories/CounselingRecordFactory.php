<?php

namespace Database\Factories;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use App\Models\Trooper;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A counseling record with no file behind it on the disk: tests that download one put a file at `document_path`.
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
            'counseled_on' => now()->subDays(10)->toDateString(),
            'reason' => 'Missed two section practices without notice.',
            'issued_by' => User::factory(),
            'document_path' => Str::uuid()->toString().'.pdf',
            'document_name' => 'counseling.pdf',
            'document_size' => 1024,
            'document_sha256' => str_repeat('a', 64),
            'drive_status' => DriveUploadStatus::Skipped,
        ];
    }

    public function lor(): static
    {
        return $this->state(['type' => CounselingType::Lor]);
    }
}
