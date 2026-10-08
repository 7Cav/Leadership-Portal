<?php

use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// 3.1 / AD-14, AD-11: one practice row per (section, week_start), and week_start is always a
// Saturday 00:00 UTC — these tests exercise the find-or-create idempotency 3.3's writer relies on
// and the week-boundary guard that keeps a non-Saturday from ever becoming a practice key.

beforeEach(function () {
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->saturday = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
});

it('creates the practice row for a section and week when none exists', function () {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, $this->saturday);

    expect($practice->exists)->toBeTrue()
        ->and($practice->section_id)->toBe($this->section->id)
        ->and($practice->week_start->equalTo($this->saturday))->toBeTrue()
        ->and($practice->server_ref)->toBeNull()
        ->and($practice->captured_at)->toBeNull()
        ->and($practice->capture_source)->toBeNull();
});

it('reuses the existing practice row on a second call for the same section and week', function () {
    $repo = app(SectionPracticeRepository::class);

    $first = $repo->findOrCreate($this->section->id, $this->saturday);
    $second = $repo->findOrCreate($this->section->id, $this->saturday);

    expect($second->id)->toBe($first->id)
        ->and(SectionPractice::query()->count())->toBe(1);
});

it('keeps separate practice rows per section and per week', function () {
    $repo = app(SectionPracticeRepository::class);
    $otherSection = Unit::factory()->create(['type' => UnitType::Section]);

    $repo->findOrCreate($this->section->id, $this->saturday);
    $repo->findOrCreate($this->section->id, $this->saturday->addWeek());
    $repo->findOrCreate($otherSection->id, $this->saturday);

    expect(SectionPractice::query()->count())->toBe(3);
});

it('finds an existing practice row without creating one', function () {
    $repo = app(SectionPracticeRepository::class);
    $created = $repo->findOrCreate($this->section->id, $this->saturday);

    expect($repo->find($this->section->id, $this->saturday)?->id)->toBe($created->id)
        ->and($repo->find($this->section->id, $this->saturday->addWeek()))->toBeNull()
        ->and(SectionPractice::query()->count())->toBe(1);
});

it('accepts a Saturday 00:00 UTC given in another timezone', function () {
    // Friday 20:00 in New York (EDT, UTC-4) is Saturday 00:00 UTC.
    $sameInstant = CarbonImmutable::parse('2026-10-02 20:00:00', 'America/New_York');

    $repo = app(SectionPracticeRepository::class);
    $practice = $repo->findOrCreate($this->section->id, $sameInstant);

    expect($practice->week_start->equalTo($this->saturday))->toBeTrue()
        ->and($practice->getRawOriginal('week_start'))->toBe('2026-10-03 00:00:00')
        ->and($repo->find($this->section->id, $sameInstant)?->id)->toBe($practice->id);
});

it('refuses to create a practice for a unit that is not a section', function (UnitType $type) {
    $unit = Unit::factory()->create(['type' => $type]);

    expect(fn () => app(SectionPracticeRepository::class)->findOrCreate($unit->id, $this->saturday))
        ->toThrow(InvalidArgumentException::class)
        ->and(SectionPractice::query()->count())->toBe(0);
})->with([UnitType::Regiment, UnitType::Battalion, UnitType::Company, UnitType::Platoon]);

it('refuses to create a practice for a unit id that does not exist', function () {
    expect(fn () => app(SectionPracticeRepository::class)->findOrCreate(999999, $this->saturday))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a week_start that is not a Saturday 00:00 UTC', function (string $time, string $zone) {
    $repo = app(SectionPracticeRepository::class);

    expect(fn () => $repo->findOrCreate($this->section->id, CarbonImmutable::parse($time, $zone)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $repo->find($this->section->id, CarbonImmutable::parse($time, $zone)))
        ->toThrow(InvalidArgumentException::class)
        ->and(SectionPractice::query()->count())->toBe(0);
})->with([
    'a Friday' => ['2026-10-02 00:00:00', 'UTC'],
    'a Sunday' => ['2026-10-04 00:00:00', 'UTC'],
    'Saturday, one second past midnight' => ['2026-10-03 00:00:01', 'UTC'],
    'Saturday midday' => ['2026-10-03 12:00:00', 'UTC'],
    'Saturday 00:00 local but not UTC' => ['2026-10-03 00:00:00', 'America/New_York'],
]);

// The MySQL REPEATABLE READ race (a row committed after this transaction's snapshot) cannot be
// reproduced on sqlite, so the locking re-read fallback is not reached here; it was verified by
// hand against MySQL 8.4. This pins the no-race path inside the audited writer's transaction.
it('finds the existing practice row when called inside an already-open transaction (3.3)', function () {
    $repo = app(SectionPracticeRepository::class);
    $existing = $repo->findOrCreate($this->section->id, $this->saturday);

    $found = DB::transaction(function () use ($repo) {
        SectionPractice::query()->count(); // a read first, as the audited writer's transaction does

        return $repo->findOrCreate($this->section->id, $this->saturday);
    });

    expect($found->id)->toBe($existing->id)
        ->and(SectionPractice::query()->count())->toBe(1);
});

it('stamps a practice posted by a user, overwriting an earlier stamp, and find() loads who (3.10)', function () {
    $repo = app(SectionPracticeRepository::class);
    $practice = $repo->findOrCreate($this->section->id, $this->saturday);
    $first = User::factory()->create();
    $second = User::factory()->create();

    $repo->markRollcallPosted($practice, $first->id);
    $stamped = $repo->markRollcallPosted($practice, $second->id);

    expect($stamped->rollcall_posted_by)->toBe($second->id)
        ->and($stamped->rollcall_posted_at)->not->toBeNull();

    $found = $repo->find($this->section->id, $this->saturday);
    expect($found->relationLoaded('rollcallPostedBy'))->toBeTrue()
        ->and($found->rollcallPostedBy->id)->toBe($second->id);
});
