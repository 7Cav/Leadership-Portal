<?php

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\Trooper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 4.1 / AD-4, AD-22: the `loas` cache. These tests assert the constraints themselves; raw
// `DB::table()` writes deliberately bypass the model's casts, because the criteria are about the
// data layer refusing a bad row.

function rawLoaRow(array $overrides = []): array
{
    return array_merge([
        'trooper_id' => Trooper::factory()->create()->id,
        'forum_thread_id' => 4001,
        'forum_node_id' => 180,
        'type' => 'loa',
        'starts_at' => '2026-10-10',
        'ends_at' => '2026-10-20',
        'parse_status' => 'ok',
        'source_thread' => 'https://7cav.us/threads/loa-request.4001/',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('creates loas with the key, type, dates, parse status, source and raw capture columns', function () {
    expect(Schema::hasColumns('loas', [
        'id', 'trooper_id', 'forum_thread_id', 'forum_node_id', 'type', 'starts_at', 'ends_at', 'expected_return_at', 'parse_status',
        'source_thread', 'raw_username', 'raw_start_date', 'raw_end_date', 'raw_excerpt',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('makes forum_thread_id unique, the key a re-sync upserts on', function () {
    $uniques = collect(Schema::getIndexes('loas'))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index) => $index['columns'])
        ->values()
        ->all();

    expect($uniques)->toContain(['forum_thread_id']);

    DB::table('loas')->insert(rawLoaRow());

    expect(fn () => DB::table('loas')->insert(rawLoaRow(['trooper_id' => null])))->toThrow(QueryException::class);
    expect(DB::table('loas')->count())->toBe(1);
});

it('points trooper_id at troopers', function () {
    $targets = collect(Schema::getForeignKeys('loas'))
        ->map(fn (array $fk) => [$fk['columns'], $fk['foreign_table']])
        ->all();

    expect($targets)->toContain([['trooper_id'], 'troopers']);
});

it('refuses a row for a trooper that does not exist', function () {
    expect(fn () => DB::table('loas')->insert(rawLoaRow(['trooper_id' => 999999])))->toThrow(QueryException::class);
});

it('refuses a type or a parse status it does not know, reserve included', function (string $column, string $value) {
    expect(fn () => DB::table('loas')->insert(rawLoaRow([$column => $value])))->toThrow(QueryException::class);
})->with([
    'type' => ['type', 'vacation'],
    'reserve, which is a roster status and not leave' => ['type', 'reserve'],
    'parse status' => ['parse_status', 'maybe'],
]);

it('accepts every leave type', function (LoaType $type) {
    DB::table('loas')->insert(rawLoaRow(['type' => $type->value]));

    expect(Loa::first()->type)->toBe($type);
})->with(LoaType::cases());

it('lets both dates be null, so an open-ended or unreadable thread asserts nothing it cannot', function () {
    DB::table('loas')->insert(rawLoaRow(['starts_at' => '2026-10-10', 'ends_at' => null, 'forum_thread_id' => 1]));
    DB::table('loas')->insert(rawLoaRow(['starts_at' => null, 'ends_at' => null, 'parse_status' => 'needs_review', 'forum_thread_id' => 2]));

    expect(Loa::find(DB::table('loas')->where('forum_thread_id', 1)->value('id'))->ends_at)->toBeNull();

    $review = Loa::where('forum_thread_id', 2)->sole();
    expect($review->starts_at)->toBeNull()
        ->and($review->ends_at)->toBeNull()
        ->and($review->parse_status)->toBe(LoaParseStatus::NeedsReview);
});

it('keeps a row whose username matched no trooper, so a person can review it', function () {
    DB::table('loas')->insert(rawLoaRow(['trooper_id' => null, 'parse_status' => 'needs_review', 'raw_username' => 'Perry.JW']));

    $loa = Loa::sole();
    expect($loa->trooper_id)->toBeNull()
        ->and($loa->trooper)->toBeNull()
        ->and($loa->raw_username)->toBe('Perry.JW');
});

it('stores the raw text the post carried, for re-parsing', function () {
    DB::table('loas')->insert(rawLoaRow([
        'raw_username' => 'Perry.J', 'raw_start_date' => 'Oct 10, 2026', 'raw_end_date' => 'Oct 20 2026',
        'raw_excerpt' => "Username: Perry.J\nStart Date\nOct 10, 2026",
    ]));

    $loa = Loa::sole();
    expect($loa->raw_start_date)->toBe('Oct 10, 2026')
        ->and($loa->raw_end_date)->toBe('Oct 20 2026')
        ->and($loa->raw_excerpt)->toContain('Start Date');
});

it('casts the dates to calendar days and the factory builds a valid row', function () {
    $loa = Loa::factory()->create();

    expect($loa->fresh()->starts_at->toDateString())->toBe($loa->starts_at->toDateString())
        ->and($loa->type)->toBe(LoaType::Loa)
        ->and($loa->trooper)->toBeInstanceOf(Trooper::class);
});

it('has factory states for an unreadable thread and an open-ended one', function () {
    $review = Loa::factory()->needsReview()->create();
    $open = Loa::factory()->openEnded()->create();

    expect($review->starts_at)->toBeNull()
        ->and($review->parse_status)->toBe(LoaParseStatus::NeedsReview)
        ->and($open->starts_at)->not->toBeNull()
        ->and($open->ends_at)->toBeNull();
});

it('gives every leave type a label', function () {
    foreach (LoaType::cases() as $type) {
        expect($type->label())->toBeString()->not->toBeEmpty();
    }

    expect(LoaType::Eloa->label())->toBe('ELOA');
});

it('has a return_thread_id that is unique, so one return ends one ELOA, and a review_reason', function () {
    expect(Schema::hasColumns('loas', ['return_thread_id', 'review_reason']))->toBeTrue();

    $uniques = collect(Schema::getIndexes('loas'))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index) => $index['columns'])
        ->all();
    expect($uniques)->toContain(['return_thread_id']);

    DB::table('loas')->insert(rawLoaRow(['forum_thread_id' => 1, 'return_thread_id' => 900]));

    expect(fn () => DB::table('loas')->insert(rawLoaRow(['forum_thread_id' => 2, 'return_thread_id' => 900])))->toThrow(QueryException::class);
});

it('lets many rows have no return thread', function () {
    DB::table('loas')->insert(rawLoaRow(['forum_thread_id' => 1]));
    DB::table('loas')->insert(rawLoaRow(['forum_thread_id' => 2]));

    expect(Loa::whereNull('return_thread_id')->count())->toBe(2);
});
