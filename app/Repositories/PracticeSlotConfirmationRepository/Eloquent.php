<?php

namespace App\Repositories\PracticeSlotConfirmationRepository;

use App\Models\PracticeSlotConfirmation;
use App\Models\User;
use App\Repositories\PracticeSlotConfirmationRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class Eloquent implements PracticeSlotConfirmationRepository
{
    public function find(int $sectionId, CarbonInterface $changeAt): ?PracticeSlotConfirmation
    {
        return PracticeSlotConfirmation::query()
            ->with('confirmedBy')
            ->where('section_id', $sectionId)
            ->where('change_at', CarbonImmutable::instance($changeAt)->utc())
            ->first();
    }

    public function record(int $sectionId, CarbonInterface $changeAt, int $weekday, int $startMinute, bool $moved, int $userId): PracticeSlotConfirmation
    {
        $changeAt = CarbonImmutable::instance($changeAt)->utc();

        return PracticeSlotConfirmation::query()->firstOrCreate(
            ['section_id' => $sectionId, 'change_at' => $changeAt],
            ['weekday' => $weekday, 'start_minute' => $startMinute, 'moved' => $moved, 'confirmed_by' => $userId],
        )->load('confirmedBy');
    }

    public function setTimezone(int $userId, string $timezone): void
    {
        User::query()->whereKey($userId)->where(fn ($q) => $q->whereNull('timezone')->orWhere('timezone', '!=', $timezone))->update(['timezone' => $timezone]);
    }

    public function timezoneOf(int $userId): ?string
    {
        $timezone = User::query()->whereKey($userId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    public function knownTimezones(): array
    {
        return User::query()->whereNotNull('timezone')->distinct()->orderBy('timezone')->pluck('timezone')->filter()->values()->all();
    }
}
