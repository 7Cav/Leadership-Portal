<?php

use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Loa\LoaCoverageState;
use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\Trooper;
use App\Repositories\LoaRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Story 4.4: the one definition of LOA coverage, in whole Cav weeks (AD-11), fail-safe for needs_review (AD-4).
// The week under test runs Saturday 2026-10-03 to Friday 2026-10-09 (UTC).

function coverageWeek(string $saturday = '2026-10-03'): CarbonImmutable
{
    return CarbonImmutable::parse($saturday.' 00:00:00', 'UTC');
}

function coverageLeave(Trooper $trooper, ?string $start, ?string $end, array $state = []): Loa
{
    return Loa::factory()->create(array_merge([
        'trooper_id' => $trooper->id,
        'starts_at' => $start,
        'ends_at' => $end,
    ], $state));
}

function coverageOf(Trooper $trooper, ?CarbonImmutable $week = null)
{
    return app(LoaCoverage::class)->forTrooper($trooper->id, $week ?? coverageWeek());
}

describe('a cleanly read leave', function () {
    it('covers the week when its window sits inside it', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-05', '2026-10-07', ['type' => LoaType::Loa]);

        $cover = coverageOf($trooper);

        expect($cover->state)->toBe(LoaCoverageState::Covered)
            ->and($cover->isCovered())->toBeTrue()
            ->and($cover->type)->toBe(LoaType::Loa)
            ->and($cover->startsAt?->toDateString())->toBe('2026-10-05')
            ->and($cover->endsAt?->toDateString())->toBe('2026-10-07')
            ->and($cover->ongoing())->toBeFalse();
    });

    it('covers the whole week when it touches only part of it', function (string $start, string $end) {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, $start, $end);

        expect(coverageOf($trooper)->isCovered())->toBeTrue();
    })->with([
        'ends on the opening Saturday' => ['2026-09-20', '2026-10-03'],
        'starts on the closing Friday' => ['2026-10-09', '2026-10-30'],
        'a single day mid-week' => ['2026-10-06', '2026-10-06'],
        'spans the whole week and more' => ['2026-09-01', '2026-11-30'],
    ]);

    it('does not cover a week it ends before or starts after', function (string $start, string $end) {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, $start, $end);

        $cover = coverageOf($trooper);

        expect($cover->state)->toBe(LoaCoverageState::None)
            ->and($cover->suppressesAtRisk())->toBeFalse();
    })->with([
        'ended the Friday before' => ['2026-09-20', '2026-10-02'],
        'starts the Saturday after' => ['2026-10-10', '2026-10-20'],
    ]);

    it('is judged by the Cav week of the week_start it is given', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-10', '2026-10-12');

        expect(coverageOf($trooper, coverageWeek('2026-10-03'))->isCovered())->toBeFalse()
            ->and(coverageOf($trooper, coverageWeek('2026-10-10'))->isCovered())->toBeTrue();
    });

    it('covers every week from its start when it has no end, and says it is ongoing', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-09-28', null, ['type' => LoaType::Eloa]);

        $cover = coverageOf($trooper);
        $later = coverageOf($trooper, coverageWeek('2027-03-06'));

        expect($cover->isCovered())->toBeTrue()
            ->and($cover->ongoing())->toBeTrue()
            ->and($cover->endsAt)->toBeNull()
            ->and($cover->type)->toBe(LoaType::Eloa)
            ->and($later->isCovered())->toBeTrue()
            ->and($later->ongoing())->toBeTrue();
    });

    it('keeps an ELOA covered past its expected return, which is only an expectation, and passes the date on', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-09-20', null, ['type' => LoaType::Eloa, 'expected_return_at' => '2026-10-01']);

        $cover = coverageOf($trooper);

        expect($cover->isCovered())->toBeTrue()
            ->and($cover->ongoing())->toBeTrue()
            ->and($cover->endsAt)->toBeNull()
            ->and($cover->expectedReturnAt?->toDateString())->toBe('2026-10-01')
            ->and($cover->toArray()['expectedReturnAt'])->toBe('2026-10-01');
    });

    it('flags an open ELOA overdue once the UTC day on the server is past its expected return', function (string $now, bool $overdue) {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-09-20', null, ['type' => LoaType::Eloa, 'expected_return_at' => '2026-10-08']);

        $cover = coverageOf($trooper);

        expect($cover->isCovered())->toBeTrue()
            ->and($cover->overdue)->toBe($overdue)
            ->and($cover->toArray()['overdue'])->toBe($overdue);
    })->with([
        'the day before' => ['2026-10-07 23:59:59', false],
        'the expected day, late in the day' => ['2026-10-08 23:59:59', false],
        'the next UTC day, just after midnight' => ['2026-10-09 00:00:01', true],
    ]);

    it('does not describe today for a week that does not contain it, so a past week never reads as overdue', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-09-20', null, ['type' => LoaType::Eloa, 'expected_return_at' => '2026-10-08']);
        $ending = Trooper::factory()->create();
        coverageLeave($ending, '2026-10-01', '2026-10-08');

        // The week of Oct 3 is long past: the ELOA is covered there and was not overdue then; Oct 20 is today.
        expect(coverageOf($trooper, coverageWeek('2026-10-03'))->overdue)->toBeFalse()
            ->and(coverageOf($trooper, coverageWeek('2026-10-17'))->overdue)->toBeTrue()
            ->and(coverageOf($ending, coverageWeek('2026-10-03'))->endsInDays)->toBeNull();
    });

    it('takes the reference day as a parameter, so a past week can be judged as of a given day', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-09-20', null, ['type' => LoaType::Eloa, 'expected_return_at' => '2026-10-08']);
        $coverage = app(LoaCoverage::class);

        expect($coverage->forTrooper($trooper->id, coverageWeek(), CarbonImmutable::parse('2026-10-09 08:00:00', 'UTC'))->overdue)->toBeTrue()
            ->and($coverage->forTrooper($trooper->id, coverageWeek(), CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'))->overdue)->toBeFalse()
            ->and($coverage->forTrooper($trooper->id, coverageWeek(), CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'))->overdue)->toBeFalse();
    });

    it('says a leave has ended once its last day is before the reference day, for any week asked about', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-01', '2026-10-06');
        $open = Trooper::factory()->create();
        coverageLeave($open, '2026-10-01', '2026-10-30');

        expect(coverageOf($trooper, coverageWeek('2026-10-03'))->ended)->toBeTrue()
            ->and(coverageOf($trooper, coverageWeek('2026-10-03'))->toArray()['ended'])->toBeTrue()
            ->and(coverageOf($open, coverageWeek('2026-10-17'))->ended)->toBeFalse()
            ->and(app(LoaCoverage::class)->forTrooper($trooper->id, coverageWeek('2026-10-03'), CarbonImmutable::parse('2026-10-06 08:00:00', 'UTC'))->ended)->toBeFalse();
    });

    it('cannot be built into an impossible state: only the named constructors are public', function () {
        expect((new ReflectionClass(LoaCover::class))->getConstructor()->isPrivate())->toBeTrue();
    });

    it('is not overdue once a return has ended the ELOA, or when no return date was given', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 12:00:00', 'UTC'));
        $returned = Trooper::factory()->create();
        $undated = Trooper::factory()->create();
        coverageLeave($returned, '2026-09-20', '2026-10-06', ['type' => LoaType::Eloa, 'expected_return_at' => '2026-10-01']);
        coverageLeave($undated, '2026-09-20', null, ['type' => LoaType::Eloa]);

        expect(coverageOf($returned, coverageWeek('2026-10-03'))->overdue)->toBeFalse()
            ->and(coverageOf($undated, coverageWeek('2026-11-28'))->overdue)->toBeFalse();
    });

    it('says how many days a leave has left, counting from the UTC day on the server', function (string $now, string $ends, ?int $days) {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-01', $ends);

        $cover = coverageOf($trooper);

        expect($cover->endsInDays)->toBe($days)
            ->and($cover->toArray()['endsInDays'])->toBe($days);
    })->with([
        'two days left' => ['2026-10-06 09:00:00', '2026-10-08', 2],
        'ends today, late in the day' => ['2026-10-06 23:59:59', '2026-10-06', 0],
        'ended earlier in the week' => ['2026-10-08 00:00:01', '2026-10-06', null],
        'a long way off' => ['2026-10-04 12:00:00', '2026-10-30', 26],
    ]);

    it('has no days left for an open ELOA, however far past its expected return', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC'));
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-09-20', null, ['type' => LoaType::Eloa, 'expected_return_at' => '2026-10-08']);

        expect(coverageOf($trooper)->endsInDays)->toBeNull();
    });

    it('does not cover a week before an open-ended leave starts', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-10', null, ['type' => LoaType::Eloa]);

        expect(coverageOf($trooper)->state)->toBe(LoaCoverageState::None);
    });

    it('prefers an open-ended leave, then the one that ends last, when several touch the week', function () {
        $trooper = Trooper::factory()->create();
        $short = coverageLeave($trooper, '2026-10-05', '2026-10-06', ['source_thread' => 'https://7cav.us/threads/short.1/']);
        $long = coverageLeave($trooper, '2026-10-05', '2026-10-20', ['source_thread' => 'https://7cav.us/threads/long.2/']);

        expect(coverageOf($trooper)->sourceThread)->toBe($long->source_thread);

        $open = coverageLeave($trooper, '2026-10-01', null, ['type' => LoaType::Eloa, 'source_thread' => 'https://7cav.us/threads/open.3/']);

        expect(coverageOf($trooper)->sourceThread)->toBe($open->source_thread)
            ->and($short->exists)->toBeTrue();
    });
});

describe('a leave that needs review', function () {
    it('suppresses at-risk without covering the trooper or asserting any dates', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->needsReview()->create([
            'trooper_id' => $trooper->id,
            'type' => LoaType::Eloa,
            'review_reason' => 'The end date could not be read.',
            'created_at' => '2026-10-06 09:00:00',
        ]);

        $cover = coverageOf($trooper);

        expect($cover->state)->toBe(LoaCoverageState::NeedsReview)
            ->and($cover->isCovered())->toBeFalse()
            ->and($cover->suppressesAtRisk())->toBeTrue()
            ->and($cover->startsAt)->toBeNull()
            ->and($cover->endsAt)->toBeNull()
            ->and($cover->ongoing())->toBeFalse()
            ->and($cover->reviewReason)->toBe('The end date could not be read.')
            ->and($cover->type)->toBe(LoaType::Eloa);
    });

    it('shields the trooper in the Cav week it was posted and the week after, then stops', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => '2026-10-06 09:00:00', 'created_at' => '2026-10-06 09:05:00']);

        $twoWeeksLater = coverageOf($trooper, coverageWeek('2026-10-17'));

        expect(coverageOf($trooper, coverageWeek('2026-09-26'))->state)->toBe(LoaCoverageState::None)
            ->and(coverageOf($trooper, coverageWeek('2026-10-03'))->suppressesAtRisk())->toBeTrue()
            ->and(coverageOf($trooper, coverageWeek('2026-10-10'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($trooper, coverageWeek('2026-10-10'))->suppressesAtRisk())->toBeTrue()
            ->and($twoWeeksLater->state)->toBe(LoaCoverageState::None)
            ->and($twoWeeksLater->suppressesAtRisk())->toBeFalse()
            ->and(coverageOf($trooper, coverageWeek('2026-11-14'))->state)->toBe(LoaCoverageState::None);
    });

    it('is placed by the forum post date, so an old broken post the first sync backfills shields no one', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => '2026-03-02 09:00:00', 'created_at' => '2026-10-04 12:00:00']);

        expect(coverageOf($trooper, coverageWeek('2026-10-03'))->state)->toBe(LoaCoverageState::None)
            ->and(coverageOf($trooper, coverageWeek('2026-10-10'))->state)->toBe(LoaCoverageState::None)
            ->and(coverageOf($trooper, coverageWeek('2026-02-28'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($trooper, coverageWeek('2026-03-07'))->state)->toBe(LoaCoverageState::NeedsReview);
    });

    it('counts a post on the closing Friday into that week, and one the next Saturday into the next', function () {
        $friday = Trooper::factory()->create();
        $saturday = Trooper::factory()->create();
        Loa::factory()->needsReview()->create(['trooper_id' => $friday->id, 'posted_at' => '2026-10-09 23:59:59']);
        Loa::factory()->needsReview()->create(['trooper_id' => $saturday->id, 'posted_at' => '2026-10-10 00:00:00']);

        expect(coverageOf($friday, coverageWeek('2026-10-03'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($friday, coverageWeek('2026-10-10'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($friday, coverageWeek('2026-10-17'))->state)->toBe(LoaCoverageState::None)
            ->and(coverageOf($saturday, coverageWeek('2026-10-03'))->state)->toBe(LoaCoverageState::None)
            ->and(coverageOf($saturday, coverageWeek('2026-10-10'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($saturday, coverageWeek('2026-10-17'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($saturday, coverageWeek('2026-10-24'))->state)->toBe(LoaCoverageState::None);
    });

    it('falls back to when it was first stored, for the same two weeks, while it has no post date', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => null, 'created_at' => '2026-10-06 09:00:00']);

        expect(coverageOf($trooper, coverageWeek('2026-09-26'))->state)->toBe(LoaCoverageState::None)
            ->and(coverageOf($trooper, coverageWeek('2026-10-03'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($trooper, coverageWeek('2026-10-10'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($trooper, coverageWeek('2026-10-17'))->state)->toBe(LoaCoverageState::None);
    });

    it('bounds a clean row with no start date the same way', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => null, 'ends_at' => null, 'posted_at' => '2026-10-06 09:00:00']);

        expect(coverageOf($trooper, coverageWeek('2026-10-10'))->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(coverageOf($trooper, coverageWeek('2026-10-17'))->state)->toBe(LoaCoverageState::None);
    });

    it('yields to a cleanly read leave that covers the week', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'created_at' => '2026-10-01 09:00:00']);
        coverageLeave($trooper, '2026-10-05', '2026-10-07');

        expect(coverageOf($trooper)->state)->toBe(LoaCoverageState::Covered);
    });

    it('is not covered by a clean leave that does not touch the week', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'created_at' => '2026-10-01 09:00:00']);
        coverageLeave($trooper, '2026-09-01', '2026-09-10');

        expect(coverageOf($trooper)->state)->toBe(LoaCoverageState::NeedsReview);
    });

    it('reads a clean row that somehow has no start date as needing review, never as covered', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->create([
            'trooper_id' => $trooper->id,
            'parse_status' => LoaParseStatus::Ok,
            'starts_at' => null,
            'ends_at' => null,
            'created_at' => '2026-10-02 09:00:00',
        ]);

        expect(coverageOf($trooper)->state)->toBe(LoaCoverageState::NeedsReview);
    });

    it('ignores dates on a row that needs review rather than trusting them', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->create([
            'trooper_id' => $trooper->id,
            'parse_status' => LoaParseStatus::NeedsReview,
            'starts_at' => '2026-10-05',
            'ends_at' => '2026-10-07',
            'created_at' => '2026-10-02 09:00:00',
        ]);

        $cover = coverageOf($trooper);

        expect($cover->state)->toBe(LoaCoverageState::NeedsReview)
            ->and($cover->startsAt)->toBeNull();
    });
});

describe('who a leave belongs to', function () {
    it('reports no coverage for a trooper with no leave', function () {
        $trooper = Trooper::factory()->create();

        expect(coverageOf($trooper)->state)->toBe(LoaCoverageState::None);
    });

    it('never gives one trooper another trooper\'s leave', function () {
        $away = Trooper::factory()->create();
        $present = Trooper::factory()->create();
        coverageLeave($away, '2026-10-05', '2026-10-07');
        Loa::factory()->needsReview()->create(['trooper_id' => $away->id, 'created_at' => '2026-10-01 09:00:00']);

        expect(coverageOf($present)->state)->toBe(LoaCoverageState::None);
    });

    it('ignores a thread that matched no trooper', function () {
        $trooper = Trooper::factory()->create();
        Loa::factory()->create(['trooper_id' => null, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-07']);
        Loa::factory()->needsReview()->create(['trooper_id' => null, 'created_at' => '2026-10-01 09:00:00']);

        expect(coverageOf($trooper)->state)->toBe(LoaCoverageState::None);
    });
});

describe('the week it is asked about', function () {
    it('refuses a week_start that is not a Saturday 00:00 UTC', function (string $moment) {
        $trooper = Trooper::factory()->create();

        app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse($moment, 'UTC'));
    })->with([
        'a Monday' => ['2026-10-05 00:00:00'],
        'a Saturday afternoon' => ['2026-10-03 12:00:00'],
    ])->throws(InvalidArgumentException::class, 'week_start');

    it('resolves many troopers in one query, with an entry for every one asked about', function () {
        $troopers = Trooper::factory()->count(5)->create();
        coverageLeave($troopers[0], '2026-10-05', '2026-10-06');
        coverageLeave($troopers[1], '2026-10-01', null, ['type' => LoaType::Eloa]);
        Loa::factory()->needsReview()->create(['trooper_id' => $troopers[2]->id, 'created_at' => '2026-10-02 09:00:00']);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $covers = app(LoaCoverage::class)->forTroopers($troopers->pluck('id'), coverageWeek());

        expect($queries)->toBe(1)
            ->and(array_keys($covers))->toBe($troopers->pluck('id')->all())
            ->and($covers[$troopers[0]->id]->state)->toBe(LoaCoverageState::Covered)
            ->and($covers[$troopers[1]->id]->ongoing())->toBeTrue()
            ->and($covers[$troopers[2]->id]->state)->toBe(LoaCoverageState::NeedsReview)
            ->and($covers[$troopers[3]->id]->state)->toBe(LoaCoverageState::None)
            ->and($covers[$troopers[4]->id]->state)->toBe(LoaCoverageState::None);
    });

    it('answers for an empty list without touching the database', function () {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        expect(app(LoaCoverage::class)->forTroopers([], coverageWeek()))->toBe([])
            ->and($queries)->toBe(0);
    });
});

it('never writes while it resolves', function () {
    $trooper = Trooper::factory()->create();
    coverageLeave($trooper, '2026-10-05', '2026-10-07');
    Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id]);

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    coverageOf($trooper);

    expect($writes)->toBe([]);
});

it('gives the badge its props: a covering leave with dates, a review with none', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $away = Trooper::factory()->create();
    $unclear = Trooper::factory()->create();
    coverageLeave($away, '2026-10-05', '2026-10-07', ['type' => LoaType::Loa, 'source_thread' => 'https://7cav.us/threads/a.1/']);
    Loa::factory()->needsReview()->create([
        'trooper_id' => $unclear->id,
        'type' => LoaType::Eloa,
        'source_thread' => 'https://7cav.us/threads/b.2/',
        'review_reason' => 'No end date.',
        'created_at' => '2026-10-02 09:00:00',
    ]);

    expect(coverageOf($away)->toArray())->toBe([
        'state' => 'covered',
        'type' => 'loa',
        'typeLabel' => 'LOA',
        'startsAt' => '2026-10-05',
        'endsAt' => '2026-10-07',
        'expectedReturnAt' => null,
        'ongoing' => false,
        'overdue' => false,
        'endsInDays' => 1,
        'ended' => false,
        'sourceThread' => 'https://7cav.us/threads/a.1/',
        'reviewReason' => null,
    ])->and(coverageOf($unclear)->toArray())->toBe([
        'state' => 'needs_review',
        'type' => 'eloa',
        'typeLabel' => 'ELOA',
        'startsAt' => null,
        'endsAt' => null,
        'expectedReturnAt' => null,
        'ongoing' => false,
        'overdue' => false,
        'endsInDays' => null,
        'ended' => false,
        'sourceThread' => 'https://7cav.us/threads/b.2/',
        'reviewReason' => 'No end date.',
    ]);
});

describe('a window of weeks (Story 4.8)', function () {
    it('resolves each week by the same rule as a single week, in one query', function () {
        $trooper = Trooper::factory()->create();
        $other = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-12', '2026-10-14');
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'created_at' => '2026-10-20 09:00:00']);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $covers = app(LoaCoverage::class)->forTroopersOverWeeks([$trooper->id, $other->id], coverageWeek('2026-10-03'), 4);

        expect($queries)->toBe(1)
            ->and(array_keys($covers[$trooper->id]))->toBe(['2026-10-03', '2026-10-10', '2026-10-17', '2026-10-24'])
            ->and($covers[$trooper->id]['2026-10-03']->state)->toBe(LoaCoverageState::None)
            ->and($covers[$trooper->id]['2026-10-10']->state)->toBe(LoaCoverageState::Covered)
            ->and($covers[$trooper->id]['2026-10-17']->state)->toBe(LoaCoverageState::NeedsReview)
            ->and($covers[$trooper->id]['2026-10-24']->state)->toBe(LoaCoverageState::NeedsReview)
            ->and(collect($covers[$other->id])->every(fn ($cover) => $cover->state === LoaCoverageState::None))->toBeTrue();
    });

    it('agrees, week for week, with asking one week at a time', function () {
        $trooper = Trooper::factory()->create();
        coverageLeave($trooper, '2026-10-09', '2026-10-17');
        coverageLeave($trooper, '2026-11-01', null, ['type' => LoaType::Eloa, 'expected_return_at' => '2026-11-20']);

        $window = app(LoaCoverage::class)->forTroopersOverWeeks([$trooper->id], coverageWeek('2026-10-03'), 8)[$trooper->id];

        foreach ($window as $weekStart => $cover) {
            expect($cover)->toEqual(coverageOf($trooper, coverageWeek($weekStart)));
        }
    });

    it('refuses a window of no weeks, or one that does not start on a Saturday', function () {
        $coverage = app(LoaCoverage::class);

        expect(fn () => $coverage->forTroopersOverWeeks([1], coverageWeek(), 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $coverage->forTroopersOverWeeks([1], coverageWeek()->addDay(), 2))->toThrow(InvalidArgumentException::class);
    });
});

it('prefilters in SQL to a superset of what the one week rule accepts, so the two cannot drift apart', function () {
    $trooper = Trooper::factory()->create();
    $first = coverageWeek('2026-10-03');
    $weeks = 3;

    foreach ([
        ['2026-09-01', '2026-09-25'], ['2026-09-20', '2026-10-03'], ['2026-10-09', '2026-10-09'], ['2026-10-17', '2026-10-24'],
        ['2026-10-23', '2026-11-01'], ['2026-10-24', '2026-10-24'], ['2026-10-25', null], ['2026-12-01', null], ['2026-08-01', null],
    ] as [$start, $end]) {
        coverageLeave($trooper, $start, $end);
    }

    foreach (['2026-09-18 23:59:59', '2026-09-26 00:00:00', '2026-10-03 00:00:00', '2026-10-23 23:59:59', '2026-10-24 00:00:00', '2026-11-30 00:00:00'] as $posted) {
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => $posted]);
        Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => null, 'ends_at' => null, 'posted_at' => $posted]);
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => null, 'created_at' => $posted]);
    }

    $returned = app(LoaRepository::class)->touchingWeeks([$trooper->id], $first, $weeks)->pluck('id')->all();
    $starts = array_map(fn (int $i) => $first->addWeeks($i), range(0, $weeks - 1));

    foreach (Loa::all() as $loa) {
        $bearsOnAWeek = collect($starts)->contains(fn ($start) => LoaCoverage::touchesWeek($loa, $start));

        if ($bearsOnAWeek) {
            expect($returned)->toContain($loa->id);
        }
    }

    expect(Loa::all()->filter(fn ($loa) => collect($starts)->contains(fn ($start) => LoaCoverage::touchesWeek($loa, $start)))->count())->toBeGreaterThan(5);
});
