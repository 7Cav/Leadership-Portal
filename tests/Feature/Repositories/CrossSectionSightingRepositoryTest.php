<?php

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Repositories\CrossSectionSightingRepository;
use Carbon\CarbonImmutable;

// 2026-10-23: every time a capture saw a trooper at another section's practice, once per trooper and practice.

beforeEach(function () {
    $this->repo = app(CrossSectionSightingRepository::class);
    $this->practice = SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00']);
    $this->from = CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC');
    $this->until = CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC');
});

it('records each trooper once with the host practice, its week, the window and the capture source', function () {
    $trooper = Trooper::factory()->create();

    expect($this->repo->record($this->practice, [$trooper->id, $trooper->id], $this->from, $this->until))->toBe(1);

    $row = CrossSectionSighting::query()->sole();
    expect($row->trooper_id)->toBe($trooper->id)
        ->and($row->section_practice_id)->toBe($this->practice->id)
        ->and($row->week_start->toIso8601String())->toBe('2026-10-03T00:00:00+00:00')
        ->and($row->seen_from->toIso8601String())->toBe('2026-10-08T18:00:00+00:00')
        ->and($row->seen_until->toIso8601String())->toBe('2026-10-08T19:30:00+00:00')
        ->and($row->source)->toBe(CrossSectionSighting::SOURCE_CAPTURE);
});

it('adds nothing for a pair already recorded and keeps the first sighting', function () {
    [$first, $second] = Trooper::factory()->count(2)->create();
    $this->repo->record($this->practice, [$first->id], $this->from, $this->until);

    $added = $this->repo->record($this->practice, [$first->id, $second->id], $this->from->addHour(), $this->until->addHour());

    expect($added)->toBe(1)
        ->and(CrossSectionSighting::query()->count())->toBe(2)
        ->and(CrossSectionSighting::query()->where('trooper_id', $first->id)->sole()->seen_from->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');
});

it('keeps a sighting per practice, so the same trooper at two practices has two', function () {
    $trooper = Trooper::factory()->create();
    $other = SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00']);

    $this->repo->record($this->practice, [$trooper->id], $this->from, $this->until);
    $this->repo->record($other, [$trooper->id], $this->from, $this->until);

    expect(CrossSectionSighting::query()->count())->toBe(2);
});

it('records nothing for no troopers', function () {
    expect($this->repo->record($this->practice, [], $this->from, $this->until))->toBe(0)
        ->and(CrossSectionSighting::query()->count())->toBe(0);
});
