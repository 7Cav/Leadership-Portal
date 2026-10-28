<?php

use App\Milpac\CounselingStanding;
use App\Models\CounselingKind;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

// 2026-10-28: where counseling stands under the regulations, measured between records by their dates as the 7Cav wiki
// does, on the ladder each record is for. Dates are days after 01Jan26 here.

const STANDING_BASE = '2026-01-01';

function standingDate(int $day): string
{
    return CarbonImmutable::parse(STANDING_BASE, 'UTC')->addDays($day)->toDateString();
}

/** @return array{id: int, day: int, type: CounselingType, kind: CounselingKind} */
function standingEntry(int $id, int $day, string $type, ?string $kind = 'conduct'): array
{
    return CounselingStanding::entry($id, standingDate($day), CounselingType::from($type), $kind === null ? null : CounselingKind::from($kind));
}

/** @return array<int, list<string>> the flag keys, by record id */
function standingKeys(array $entries): array
{
    return array_map(fn (array $flags) => array_column($flags, 'key'), CounselingStanding::flags($entries));
}

it('flags a DCS for other conduct within 90 days of another, the 90th day included, and not on the 91st', function () {
    expect(standingKeys([standingEntry(1, 0, 'dcs'), standingEntry(2, 90, 'dcs')]))->toBe([1 => [], 2 => ['dcs-repeat']])
        ->and(standingKeys([standingEntry(1, 0, 'dcs'), standingEntry(2, 91, 'dcs')]))->toBe([1 => [], 2 => []]);

    expect(CounselingStanding::flags([standingEntry(1, 0, 'dcs'), standingEntry(2, 5, 'dcs')])[2][0]['message'])
        ->toBe('Repeat within 90 days of a DCS: an LOR is the next step if it was for the same issue.');
});

it('orders records on the same day by when they were filed', function () {
    expect(standingKeys([standingEntry(8, 10, 'dcs'), standingEntry(3, 10, 'dcs')]))->toBe([3 => [], 8 => ['dcs-repeat']]);
});

it('weighs a record filed late with an earlier date by its date: it moves before the records dated after it', function () {
    // Record 2 was filed after record 1 but carries an earlier date.
    expect(standingKeys([standingEntry(1, 50, 'dcs'), standingEntry(2, 0, 'dcs')]))->toBe([2 => [], 1 => ['dcs-repeat']]);
});

it('rules a record with no kind, filed before the portal asked, as other conduct', function () {
    expect(standingKeys([standingEntry(1, 0, 'dcs', null), standingEntry(2, 30, 'dcs')]))->toBe([1 => [], 2 => ['dcs-repeat']]);
});

it('keeps the two ladders apart for DCSs: a missed practice is not a repeat of a conduct issue', function () {
    expect(standingKeys([standingEntry(1, 0, 'dcs', 'missed_practice'), standingEntry(2, 10, 'dcs')]))->toBe([1 => [], 2 => []])
        ->and(standingKeys([standingEntry(1, 0, 'dcs'), standingEntry(2, 10, 'dcs', 'missed_practice')]))->toBe([1 => [], 2 => []]);
});

it('calls for an LOR at a second missed practice within 30 days of the last, and another DCS from 31 days on', function () {
    expect(standingKeys([standingEntry(1, 0, 'dcs', 'missed_practice'), standingEntry(2, 30, 'dcs', 'missed_practice')]))->toBe([1 => [], 2 => ['missed-lor']])
        ->and(standingKeys([standingEntry(1, 0, 'dcs', 'missed_practice'), standingEntry(2, 31, 'dcs', 'missed_practice')]))->toBe([1 => [], 2 => []])
        // Measured from the last miss: 31 days after the first, then 20 after that.
        ->and(standingKeys([standingEntry(1, 0, 'dcs', 'missed_practice'), standingEntry(2, 31, 'dcs', 'missed_practice'), standingEntry(3, 51, 'dcs', 'missed_practice')]))
        ->toBe([1 => [], 2 => [], 3 => ['missed-lor']]);
});

it('calls for a second LOR at a missed practice within 60 days of an LOR for one, and not on the 61st day', function () {
    expect(standingKeys([standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 60, 'dcs', 'missed_practice')]))->toBe([1 => [], 2 => ['missed-second-lor']])
        ->and(standingKeys([standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 61, 'dcs', 'missed_practice')]))->toBe([1 => [], 2 => []]);
});

it('sends a second LOR for missed practice within 60 days of the first to an Article 15, and counts it as a 2nd LOR in 90 days', function () {
    expect(standingKeys([standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 60, 'lor', 'missed_practice')]))->toBe([1 => [], 2 => ['missed-article-15', 'lor-removal']])
        ->and(standingKeys([standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 61, 'lor', 'missed_practice')]))->toBe([1 => [], 2 => ['lor-removal']]);

    expect(CounselingStanding::flags([standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 1, 'lor', 'missed_practice')])[2][0]['message'])
        ->toBe('2nd LOR for a missed section practice or rollcall within 60 days of the first: Article 15, company first, then battalion.');
});

it('flags an LOR for other conduct within 30 days of another as the possible repeat LOR, and a 2nd LOR in 90 days for removal', function () {
    expect(standingKeys([standingEntry(1, 0, 'lor'), standingEntry(2, 30, 'lor')]))->toBe([1 => [], 2 => ['lor-repeat', 'lor-removal']])
        ->and(standingKeys([standingEntry(1, 0, 'lor'), standingEntry(2, 31, 'lor')]))->toBe([1 => [], 2 => ['lor-removal']])
        ->and(standingKeys([standingEntry(1, 0, 'lor'), standingEntry(2, 90, 'lor')]))->toBe([1 => [], 2 => ['lor-removal']])
        ->and(standingKeys([standingEntry(1, 0, 'lor'), standingEntry(2, 91, 'lor')]))->toBe([1 => [], 2 => []]);

    $flags = CounselingStanding::flags([standingEntry(1, 0, 'lor'), standingEntry(2, 0, 'lor')])[2];
    expect(array_column($flags, 'message'))->toBe([
        'Within 30 days of an LOR: if it was for the same issue, this is the repeat LOR, which goes to an Article 15.',
        '2nd LOR within 90 days: removal as SL or ASL is authorized.',
    ]);
});

it('sends a third LOR within 90 days on any matters to an Article 15', function () {
    $entries = [standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 40, 'lor'), standingEntry(3, 90, 'lor')];

    expect(standingKeys($entries))->toBe([1 => [], 2 => ['lor-removal'], 3 => ['lor-article-15']])
        ->and(CounselingStanding::flags($entries)[3][0]['message'])->toBe('3 LORs within 90 days: Article 15.')
        // The first LOR falls out of the third's 90 days on the 91st day.
        ->and(standingKeys([standingEntry(1, 0, 'lor', 'missed_practice'), standingEntry(2, 40, 'lor'), standingEntry(3, 91, 'lor')])[3])->toBe(['lor-removal']);
});

it('forecasts a new record\'s flags by the date it is given, as date ranges up to today', function () {
    $entries = [standingEntry(1, 0, 'dcs')];
    $ranges = CounselingStanding::forecast($entries, CounselingType::Dcs, CounselingKind::Conduct, CarbonImmutable::parse(standingDate(200), 'UTC'));

    expect(array_map(fn (array $range) => [$range['from'], array_column($range['flags'], 'key')], $ranges))->toBe([
        ['1970-01-01', []],
        [standingDate(0), ['dcs-repeat']],
        [standingDate(91), []],
    ]);
});

it('forecasts from the records dated before the new one only, so a back-dated filing ignores what came after', function () {
    $entries = [standingEntry(1, 100, 'lor', 'missed_practice')];
    $ranges = CounselingStanding::forecast($entries, CounselingType::Lor, CounselingKind::MissedPractice, CarbonImmutable::parse(standingDate(130), 'UTC'));

    expect(array_map(fn (array $range) => [$range['from'], array_column($range['flags'], 'key')], $ranges))->toBe([
        ['1970-01-01', []],
        [standingDate(100), ['missed-article-15', 'lor-removal']],
    ]);
});

it('forecasts nothing but one empty range for a trooper with no records', function () {
    expect(CounselingStanding::forecast([], CounselingType::Lor, CounselingKind::Conduct, CarbonImmutable::parse('2026-10-28', 'UTC')))
        ->toBe([['from' => '1970-01-01', 'flags' => []]]);
});

it('holds no favorable action for 30 days after the latest LOR, the 30th day included', function () {
    $entries = [standingEntry(1, 0, 'lor'), standingEntry(2, 10, 'lor'), standingEntry(3, 20, 'dcs')];

    expect(CounselingStanding::noFavorableAction($entries, CarbonImmutable::parse(standingDate(40), 'UTC')))->toBe([
        'key' => 'no-favorable-action',
        'message' => 'No favorable action until 10Feb26, 30 days after the LOR of 11Jan26.',
        'until' => standingDate(40),
    ])
        ->and(CounselingStanding::noFavorableAction($entries, CarbonImmutable::parse(standingDate(41), 'UTC')))->toBeNull()
        ->and(CounselingStanding::noFavorableAction([standingEntry(3, 20, 'dcs')], CarbonImmutable::parse(standingDate(21), 'UTC')))->toBeNull();
});

it('says a record with no kind was ruled as other conduct only where that changes a flag', function () {
    // As a missed practice, the DCS 10 days later would be a second miss within 30 days.
    expect(CounselingStanding::kindMatters([standingEntry(1, 0, 'dcs', null), standingEntry(2, 10, 'dcs', 'missed_practice')], 1))->toBeTrue()
        ->and(CounselingStanding::kindMatters([standingEntry(1, 0, 'dcs', null)], 1))->toBeFalse()
        ->and(CounselingStanding::kindMatters([standingEntry(1, 0, 'dcs', null), standingEntry(2, 200, 'dcs', 'missed_practice')], 1))->toBeFalse();
});
