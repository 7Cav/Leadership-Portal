<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trooper_id' => Trooper::factory(),
            'section_practice_id' => SectionPractice::factory(),
            'status' => AttendanceStatus::Present,
            'source' => AttendanceSource::Manual,
            'rollcall' => RollcallSignature::WillAttend,
        ];
    }
}
