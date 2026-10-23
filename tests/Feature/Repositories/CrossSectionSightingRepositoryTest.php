<?php

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\User;
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

it('records a leader\'s hand entry with no capture window, and never over an existing pair', function () {
    [$guest, $seen] = Trooper::factory()->count(2)->create();
    $leader = User::factory()->create();
    $this->repo->record($this->practice, [$seen->id], $this->from, $this->until);

    $row = $this->repo->recordManual($this->practice, $guest->id, $leader->id);

    expect($row->source)->toBe(CrossSectionSighting::SOURCE_MANUAL)
        ->and($row->fresh()->recorded_by)->toBe($leader->id)
        ->and($row->fresh()->seen_from)->toBeNull()
        ->and($row->fresh()->week_start->toIso8601String())->toBe('2026-10-03T00:00:00+00:00')
        // The capture already saw them, and a second hand entry adds nothing either.
        ->and($this->repo->recordManual($this->practice, $seen->id, $leader->id))->toBeNull()
        ->and($this->repo->recordManual($this->practice, $guest->id, $leader->id))->toBeNull()
        ->and(CrossSectionSighting::query()->where('trooper_id', $seen->id)->sole()->source)->toBe(CrossSectionSighting::SOURCE_CAPTURE);
});

// Review M1: a hand entry the server then sees must not stay undoable, or an Undo would take the server's evidence
// (and the makeup it supports) with it.
it('turns a hand entry the capture then sees into the capture sighting, keeping who added it', function () {
    $guest = Trooper::factory()->create();
    $leader = User::factory()->create();
    $this->repo->recordManual($this->practice, $guest->id, $leader->id);

    expect($this->repo->record($this->practice, [$guest->id], $this->from, $this->until))->toBe(0);

    $row = CrossSectionSighting::query()->sole();
    expect($row->source)->toBe(CrossSectionSighting::SOURCE_CAPTURE)
        ->and($row->recorded_by)->toBe($leader->id)
        ->and($row->seen_from->toIso8601String())->toBe('2026-10-08T18:00:00+00:00')
        ->and($row->seen_until->toIso8601String())->toBe('2026-10-08T19:30:00+00:00')
        ->and($this->repo->removeManual($this->practice->id, $guest->id))->toBeFalse()
        ->and(CrossSectionSighting::query()->count())->toBe(1);
});

it('says which troopers are still recorded at any practice of a Cav week', function () {
    [$here, $elsewhere, $earlier, $never] = Trooper::factory()->count(4)->create();
    $other = SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00']);
    $before = SectionPractice::factory()->create(['week_start' => '2026-09-26 00:00:00']);
    $this->repo->record($this->practice, [$here->id], $this->from, $this->until);
    $this->repo->recordManual($other, $elsewhere->id, User::factory()->create()->id);
    $this->repo->recordManual($before, $earlier->id, User::factory()->create()->id);

    $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

    expect($this->repo->trooperIdsSeenInWeek([$never->id, $earlier->id, $elsewhere->id, $here->id], $week))
        ->toEqualCanonicalizing([$here->id, $elsewhere->id])
        ->and($this->repo->trooperIdsSeenInWeek([], $week))->toBe([]);
});

it('removes only a hand entry, never a capture sighting', function () {
    [$guest, $seen] = Trooper::factory()->count(2)->create();
    $this->repo->record($this->practice, [$seen->id], $this->from, $this->until);
    $this->repo->recordManual($this->practice, $guest->id, User::factory()->create()->id);

    expect($this->repo->removeManual($this->practice->id, $seen->id))->toBeFalse()
        ->and($this->repo->removeManual($this->practice->id, $guest->id))->toBeTrue()
        ->and($this->repo->removeManual($this->practice->id, $guest->id))->toBeFalse()
        ->and(CrossSectionSighting::query()->pluck('trooper_id')->all())->toBe([$seen->id]);
});

it('says whether a trooper is recorded at any practice of a week', function () {
    $trooper = Trooper::factory()->create();
    $this->repo->record($this->practice, [$trooper->id], $this->from, $this->until);

    expect($this->repo->existsForTrooperInWeek($trooper->id, CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')))->toBeTrue()
        ->and($this->repo->existsForTrooperInWeek($trooper->id, CarbonImmutable::parse('2026-10-10 00:00:00', 'UTC')))->toBeFalse()
        ->and($this->repo->existsForTrooperInWeek(Trooper::factory()->create()->id, CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')))->toBeFalse();
});

it('lists a host practice\'s guests by username with who added each', function () {
    $leader = User::factory()->create(['username' => 'Host.L']);
    $zulu = Trooper::factory()->create(['username' => 'Zulu.Z']);
    $alpha = Trooper::factory()->create(['username' => 'alpha.A']);
    $this->repo->record($this->practice, [$zulu->id], $this->from, $this->until);
    $this->repo->recordManual($this->practice, $alpha->id, $leader->id);
    $this->repo->record(SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00']), [Trooper::factory()->create()->id], $this->from, $this->until);

    $rows = $this->repo->forHostPractice($this->practice->id);

    expect($rows->pluck('trooper.username')->all())->toBe(['alpha.A', 'Zulu.Z'])
        ->and($rows->first()->recordedBy->username)->toBe('Host.L')
        ->and($rows->last()->recordedBy)->toBeNull();
});
