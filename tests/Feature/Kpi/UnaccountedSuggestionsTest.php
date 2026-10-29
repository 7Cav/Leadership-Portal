<?php

use App\Kpi\UnaccountedSuggestions;
use App\Models\Loa;
use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperPlacementRepository;
use Carbon\CarbonImmutable;

// The reason the weekly report pre-selects for an unaccounted trooper when the portal's own data says why
// (2026-10-29): departed, moved out of the section during the week, or a leave post that needs review. The week under
// test runs Saturday 03Oct26 to Friday 09Oct26.

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-08 12:00:00');
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'callsign' => '2/1/A/1-7']);
    $this->other = Unit::factory()->create(['type' => UnitType::Section, 'callsign' => '3/1/A/1-7']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function suggest(Unit $section, CarbonImmutable $week, array $troopers): array
{
    return app(UnaccountedSuggestions::class)->forSection($section, $week, collect($troopers));
}

function moveOut(Trooper $trooper, Unit $from, Unit $to, string $at): void
{
    $placements = app(TrooperPlacementRepository::class);
    $placements->record([$trooper->id => [null, $from->id]], PlacementSource::Sync, CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'));
    $placements->record([$trooper->id => [$from->id, $to->id]], PlacementSource::Sync, CarbonImmutable::parse($at, 'UTC'));
}

it('suggests each reason only where the data says why', function () {
    $departed = Trooper::factory()->create(['status' => TrooperStatus::Inactive, 'primary_section_id' => $this->section->id]);
    $moved = Trooper::factory()->create(['primary_section_id' => $this->other->id]);
    moveOut($moved, $this->section, $this->other, '2026-10-06 18:00:00');
    $review = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    Loa::factory()->needsReview()->create(['trooper_id' => $review->id, 'posted_at' => '2026-10-04 12:00:00']);
    $plain = Trooper::factory()->create(['primary_section_id' => $this->section->id]);

    expect(suggest($this->section, $this->week, [$departed, $moved, $review, $plain]))->toBe([
        $departed->id => 'departed',
        $moved->id => 'moved_out',
        $review->id => 'loa_needs_review',
    ]);
});

it('does not call a move before or after the week, or into the section, a move out this week', function () {
    $before = Trooper::factory()->create();
    moveOut($before, $this->section, $this->other, '2026-10-02 18:00:00');
    $after = Trooper::factory()->create();
    moveOut($after, $this->section, $this->other, '2026-10-10 00:00:00');
    $joined = Trooper::factory()->create();
    moveOut($joined, $this->other, $this->section, '2026-10-06 18:00:00');

    expect(suggest($this->section, $this->week, [$before, $after, $joined]))->toBe([]);
});

it('prefers the firmest fact: departed, then moved out, then leave that needs review', function () {
    $both = Trooper::factory()->create(['status' => TrooperStatus::Inactive]);
    moveOut($both, $this->section, $this->other, '2026-10-06 18:00:00');
    Loa::factory()->needsReview()->create(['trooper_id' => $both->id, 'posted_at' => '2026-10-04 12:00:00']);
    $movedOnLeave = Trooper::factory()->create();
    moveOut($movedOnLeave, $this->section, $this->other, '2026-10-06 18:00:00');
    Loa::factory()->needsReview()->create(['trooper_id' => $movedOnLeave->id, 'posted_at' => '2026-10-04 12:00:00']);

    expect(suggest($this->section, $this->week, [$both, $movedOnLeave]))->toBe([$both->id => 'departed', $movedOnLeave->id => 'moved_out']);
});

it('suggests nothing for a cleanly read leave, which the rollcall already excuses', function () {
    $away = Trooper::factory()->create();
    Loa::factory()->create(['trooper_id' => $away->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-20']);

    expect(suggest($this->section, $this->week, [$away]))->toBe([])
        ->and(suggest($this->section, $this->week, []))->toBe([]);
});
