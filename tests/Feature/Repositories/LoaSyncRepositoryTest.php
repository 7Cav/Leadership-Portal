<?php

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\Trooper;
use App\Repositories\LoaRepository;
use App\Repositories\LoaReturn;
use App\Repositories\LoaWrite;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

// Story 4.2: the one transaction a leave sync run is applied in.

function loaWrite(array $overrides = []): LoaWrite
{
    $values = [
        'forumThreadId' => 5001,
        'forumNodeId' => 180,
        'trooperId' => Trooper::factory()->create()->id,
        'type' => LoaType::Loa,
        'startsAt' => CarbonImmutable::parse('2026-10-06', 'UTC'),
        'endsAt' => CarbonImmutable::parse('2026-10-20', 'UTC'),
        'parseStatus' => LoaParseStatus::Ok,
        'reviewReason' => null,
        'sourceThread' => 'https://7cav.us/threads/5001/',
        'rawUsername' => 'Smith.J',
        'rawStartDate' => 'Oct 6, 2026',
        'rawEndDate' => 'Oct 20, 2026',
        'rawExcerpt' => "Username: Smith.J\nStart Date: Oct 6, 2026",
        ...$overrides,
    ];

    return new LoaWrite(...$values);
}

function eloaWrite(Trooper $trooper, int $threadId, string $startsAt): LoaWrite
{
    return loaWrite([
        'forumThreadId' => $threadId, 'forumNodeId' => 341, 'trooperId' => $trooper->id, 'type' => LoaType::Eloa,
        'startsAt' => CarbonImmutable::parse($startsAt, 'UTC'), 'endsAt' => null,
    ]);
}

describe('upserting a thread', function () {
    it('creates a row for a thread it has not seen, with every column', function () {
        $write = loaWrite();

        $counts = app(LoaRepository::class)->sync([$write], []);

        $loa = Loa::sole();
        expect($counts)->toBe(['created' => 1, 'updated' => 0, 'closed' => 0, 'unmatchedReturns' => 0])
            ->and($loa->forum_thread_id)->toBe(5001)
            ->and($loa->forum_node_id)->toBe(180)
            ->and($loa->trooper_id)->toBe($write->trooperId)
            ->and($loa->type)->toBe(LoaType::Loa)
            ->and($loa->starts_at->toDateString())->toBe('2026-10-06')
            ->and($loa->ends_at->toDateString())->toBe('2026-10-20')
            ->and($loa->parse_status)->toBe(LoaParseStatus::Ok)
            ->and($loa->review_reason)->toBeNull()
            ->and($loa->raw_username)->toBe('Smith.J')
            ->and($loa->raw_start_date)->toBe('Oct 6, 2026')
            ->and($loa->raw_excerpt)->toContain('Smith.J');
    });

    it('updates the same row in place, keeping its id, when the thread changes', function () {
        $trooper = Trooper::factory()->create();
        app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id])], []);
        $id = Loa::sole()->id;

        $counts = app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id, 'endsAt' => CarbonImmutable::parse('2026-10-25', 'UTC')])], []);

        expect($counts['created'])->toBe(0)->and($counts['updated'])->toBe(1)
            ->and(Loa::count())->toBe(1)
            ->and(Loa::sole()->id)->toBe($id)
            ->and(Loa::sole()->ends_at->toDateString())->toBe('2026-10-25');
    });

    it('counts nothing as updated when a thread comes back the same', function () {
        $trooper = Trooper::factory()->create();
        app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id])], []);

        $counts = app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id])], []);

        expect($counts)->toBe(['created' => 0, 'updated' => 0, 'closed' => 0, 'unmatchedReturns' => 0])
            ->and(Loa::count())->toBe(1);
    });

    it('flips a row that read cleanly before to needs_review, clearing its dates instead of keeping stale ones', function () {
        $trooper = Trooper::factory()->create();
        app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id])], []);

        app(LoaRepository::class)->sync([loaWrite([
            'trooperId' => $trooper->id, 'startsAt' => null, 'endsAt' => null,
            'parseStatus' => LoaParseStatus::NeedsReview, 'reviewReason' => 'The End Date "soon" is not a date the portal can read.',
        ])], []);

        $loa = Loa::sole();
        expect($loa->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($loa->starts_at)->toBeNull()
            ->and($loa->ends_at)->toBeNull()
            ->and($loa->review_reason)->toContain('End Date');
    });

    it('flips a needs_review row back to ok once the post reads cleanly', function () {
        $trooper = Trooper::factory()->create();
        app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id, 'startsAt' => null, 'endsAt' => null, 'parseStatus' => LoaParseStatus::NeedsReview, 'reviewReason' => 'x'])], []);

        app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id])], []);

        expect(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok)->and(Loa::sole()->review_reason)->toBeNull();
    });

    it('keeps a row with no trooper, for a person to review', function () {
        app(LoaRepository::class)->sync([loaWrite(['trooperId' => null, 'startsAt' => null, 'endsAt' => null, 'parseStatus' => LoaParseStatus::NeedsReview, 'reviewReason' => 'No trooper is named Perry.JW.', 'rawUsername' => 'Perry.JW'])], []);

        expect(Loa::sole()->trooper_id)->toBeNull()->and(Loa::sole()->raw_username)->toBe('Perry.JW');
    });

    it('is one transaction: a failure part-way leaves the cache exactly as it was', function () {
        $trooper = Trooper::factory()->create();
        app(LoaRepository::class)->sync([loaWrite(['trooperId' => $trooper->id])], []);
        $before = Loa::all()->map->getAttributes()->all();

        // The second write names a trooper that does not exist, so the database refuses it after the first
        // write in the same run has already been applied.
        expect(fn () => app(LoaRepository::class)->sync([
            loaWrite(['trooperId' => $trooper->id, 'endsAt' => CarbonImmutable::parse('2026-10-30', 'UTC')]),
            loaWrite(['forumThreadId' => 5002, 'trooperId' => 999999]),
        ], []))->toThrow(QueryException::class);

        expect(Loa::all()->map->getAttributes()->all())->toBe($before)
            ->and(Loa::count())->toBe(1);
    });
});

describe('an ELOA return', function () {
    it('ends the trooper\'s open ELOA on the return date, remembering the return', function () {
        $trooper = Trooper::factory()->create();
        app(LoaRepository::class)->sync([eloaWrite($trooper, 6001, '2026-09-22')], []);

        $counts = app(LoaRepository::class)->sync([], [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))]);

        $loa = Loa::sole();
        expect($counts)->toBe(['created' => 0, 'updated' => 0, 'closed' => 1, 'unmatchedReturns' => 0])
            ->and($loa->ends_at->toDateString())->toBe('2026-10-02')
            ->and($loa->return_thread_id)->toBe(6500);
    });

    it('does not reopen the ELOA when its own thread is read again, which still says there is no end', function () {
        $trooper = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $repo->sync([eloaWrite($trooper, 6001, '2026-09-22')], [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))]);

        $counts = $repo->sync([eloaWrite($trooper, 6001, '2026-09-22')], []);

        expect($counts['updated'])->toBe(0)
            ->and(Loa::sole()->ends_at->toDateString())->toBe('2026-10-02')
            ->and(Loa::sole()->return_thread_id)->toBe(6500);
    });

    it('applies a return once: seeing it again changes nothing', function () {
        $trooper = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $return = new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'));
        $repo->sync([eloaWrite($trooper, 6001, '2026-09-22')], [$return]);

        $counts = $repo->sync([], [$return]);

        expect($counts)->toBe(['created' => 0, 'updated' => 0, 'closed' => 0, 'unmatchedReturns' => 0])
            ->and(Loa::sole()->ends_at->toDateString())->toBe('2026-10-02');
    });

    it('ends the latest ELOA that had started, not an older one, and not one that starts after the return', function () {
        $trooper = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $repo->sync([eloaWrite($trooper, 6001, '2026-03-01'), eloaWrite($trooper, 6002, '2026-09-22'), eloaWrite($trooper, 6003, '2026-11-20')], []);

        $repo->sync([], [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))]);

        expect(Loa::where('forum_thread_id', 6002)->sole()->return_thread_id)->toBe(6500)
            ->and(Loa::where('forum_thread_id', 6001)->sole()->return_thread_id)->toBeNull()
            ->and(Loa::where('forum_thread_id', 6003)->sole()->return_thread_id)->toBeNull();
    });

    it('is counted and stored nowhere when there is no open ELOA to end', function () {
        $trooper = Trooper::factory()->create();

        $counts = app(LoaRepository::class)->sync([], [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))]);

        expect($counts['unmatchedReturns'])->toBe(1)->and($counts['closed'])->toBe(0)->and(Loa::count())->toBe(0);
    });

    it('never ends an ordinary LOA, another trooper\'s ELOA, an ELOA that is needs_review, or one that has a set end', function () {
        $trooper = Trooper::factory()->create();
        $other = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $repo->sync([
            loaWrite(['forumThreadId' => 7001, 'trooperId' => $trooper->id]),
            eloaWrite($other, 7002, '2026-09-22'),
            loaWrite(['forumThreadId' => 7003, 'forumNodeId' => 341, 'trooperId' => $trooper->id, 'type' => LoaType::Eloa, 'startsAt' => null, 'endsAt' => null, 'parseStatus' => LoaParseStatus::NeedsReview, 'reviewReason' => 'x']),
            loaWrite(['forumThreadId' => 7004, 'forumNodeId' => 341, 'trooperId' => $trooper->id, 'type' => LoaType::Eloa, 'startsAt' => CarbonImmutable::parse('2026-09-01', 'UTC'), 'endsAt' => CarbonImmutable::parse('2026-09-20', 'UTC')]),
        ], []);

        $counts = $repo->sync([], [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))]);

        expect($counts['unmatchedReturns'])->toBe(1)->and(Loa::whereNotNull('return_thread_id')->count())->toBe(0);
    });

    it('closes an ELOA created in the same run, since writes are applied before returns', function () {
        $trooper = Trooper::factory()->create();

        $counts = app(LoaRepository::class)->sync(
            [eloaWrite($trooper, 6001, '2026-09-22')],
            [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))],
        );

        expect($counts['created'])->toBe(1)->and($counts['closed'])->toBe(1)
            ->and(Loa::sole()->ends_at->toDateString())->toBe('2026-10-02');
    });

    it('lets a thread that now needs review clear even an ended ELOA\'s dates, while keeping the return on record', function () {
        $trooper = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $repo->sync([eloaWrite($trooper, 6001, '2026-09-22')], [new LoaReturn(6500, $trooper->id, CarbonImmutable::parse('2026-10-02', 'UTC'))]);

        $repo->sync([loaWrite(['forumThreadId' => 6001, 'forumNodeId' => 341, 'trooperId' => $trooper->id, 'type' => LoaType::Eloa, 'startsAt' => null, 'endsAt' => null, 'parseStatus' => LoaParseStatus::NeedsReview, 'reviewReason' => 'x'])], []);

        expect(Loa::sole()->ends_at)->toBeNull()
            ->and(Loa::sole()->starts_at)->toBeNull()
            ->and(Loa::sole()->return_thread_id)->toBe(6500);
    });
});

describe('settled history', function () {
    it('lists only rows that read cleanly and whose leave ended before the date', function () {
        $trooper = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $ended = fn (int $id, string $end) => loaWrite(['forumThreadId' => $id, 'trooperId' => $trooper->id, 'startsAt' => CarbonImmutable::parse('2026-01-01', 'UTC'), 'endsAt' => CarbonImmutable::parse($end, 'UTC')]);
        $repo->sync([
            $ended(8001, '2026-01-10'),
            $ended(8002, '2026-09-20'),
            $ended(8003, '2026-09-24'),
            eloaWrite($trooper, 8004, '2026-01-01'),
            loaWrite(['forumThreadId' => 8005, 'trooperId' => $trooper->id, 'startsAt' => null, 'endsAt' => null, 'parseStatus' => LoaParseStatus::NeedsReview, 'reviewReason' => 'x']),
        ], []);

        $settled = $repo->settledThreadIds([8001, 8002, 8003, 8004, 8005, 8006], CarbonImmutable::parse('2026-09-24', 'UTC'));

        // 8003 ended on the date itself, so it is not before it; 8004 is open-ended; 8005 needs review; 8006 has no row.
        expect($settled)->toEqualCanonicalizing([8001, 8002]);
    });

    it('treats an ELOA that a return ended long ago as settled', function () {
        $trooper = Trooper::factory()->create();
        $repo = app(LoaRepository::class);
        $repo->sync([eloaWrite($trooper, 8010, '2026-03-01')], [new LoaReturn(8500, $trooper->id, CarbonImmutable::parse('2026-04-01', 'UTC'))]);

        expect($repo->settledThreadIds([8010], CarbonImmutable::parse('2026-09-24', 'UTC')))->toBe([8010]);
    });

    it('returns nothing for no threads', function () {
        expect(app(LoaRepository::class)->settledThreadIds([], CarbonImmutable::parse('2026-09-24', 'UTC')))->toBe([]);
    });
});
