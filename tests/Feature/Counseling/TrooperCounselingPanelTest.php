<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CounselingRecord;
use App\Models\DriveUploadStatus;
use App\Models\TrooperStatus;
use App\Models\Unit;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// #381: the trooper page lists the counseling records the viewer may see, and warns when the 90-day rules apply.

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->record = fn (string $type, int $daysAgo, array $attributes = []) => CounselingRecord::factory()->create([
        'trooper_id' => $this->rifleman->id,
        'type' => $type,
        'counseled_on' => now('UTC')->subDays($daysAgo)->toDateString(),
        'issued_by' => $this->tree['users']['pl']->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        ...$attributes,
    ]);
});

function counselingProps(string $who, mixed $test): array
{
    return $test->flushHeaders()->actingAs($test->tree['users'][$who])->get(route('troopers.show', $test->rifleman->id))->assertOk()->viewData('page')['props']['counseling'];
}

it('lists every record, newest first, with its issuer, document and Drive status, for a platoon leader', function () {
    ($this->record)('dcs', 20, ['reason' => 'Missed practice.', 'drive_status' => DriveUploadStatus::Uploaded]);
    ($this->record)('lor', 5, ['reason' => 'Disrespect toward an NCO.', 'document_name' => 'lor.pdf', 'document_size' => 2048]);

    $props = counselingProps('pl', $this);

    expect($props['records'])->toHaveCount(2)
        ->and($props['records'][0])->toMatchArray([
            'type' => 'lor',
            'typeLabel' => 'Letter of Reprimand',
            'date' => now('UTC')->subDays(5)->toDateString(),
            'reason' => 'Disrespect toward an NCO.',
            'issuer' => 'Mercer.J',
            'document' => ['name' => 'lor.pdf', 'size' => 2048, 'demo' => false],
            'driveStatus' => 'skipped',
            'kind' => null,
            'kindLabel' => null,
            'kindAssumed' => false,
            'flags' => [],
            'canChange' => true,
        ])
        ->and($props['records'][1]['typeLabel'])->toBe('Developmental Counseling Statement')
        ->and($props['records'][1]['driveStatus'])->toBe('uploaded')
        ->and($props['canFile'])->toBe(['dcs' => true, 'lor' => true])
        ->and($props['departed'])->toBeFalse();
});

it('keeps a DCS out of the page entirely for someone who may only see LORs', function () {
    ($this->record)('dcs', 3, ['reason' => 'Secret developmental counseling text.', 'document_name' => 'secret-dcs.pdf']);
    ($this->record)('lor', 2, ['reason' => 'Public reprimand text.']);
    ($this->record)('dcs', 1, ['reason' => 'Another private counseling.']);

    $body = $this->actingAs($this->tree['users']['grant'])
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'])
        ->get(route('troopers.show', $this->rifleman->id))->assertOk()->getContent();

    expect($body)->toContain('Public reprimand text.')
        ->not->toContain('Secret developmental counseling text.')
        ->not->toContain('secret-dcs.pdf')
        ->not->toContain('Another private counseling.')
        // Two DCSs in 90 days would be flagged; the flag must not give them away either.
        ->not->toContain('dcs-repeat');

    $props = counselingProps('grant', $this);
    expect(collect($props['records'])->pluck('type')->all())->toBe(['lor'])
        ->and($props['records'][0]['flags'])->toBe([])
        ->and($props['forecast'])->toBe([])
        ->and($props['canFile'])->toBe(['dcs' => false, 'lor' => false])
        ->and($props['records'][0]['canChange'])->toBeFalse();
});

it('shows the trooper\'s own Section Leader their DCSs, and lets them file a DCS but not an LOR', function () {
    ($this->record)('dcs', 3, ['issued_by' => $this->tree['users']['sl']->id, 'issuer_unit_id' => $this->tree['units']['section']->id]);
    ($this->record)('lor', 3);

    $props = counselingProps('sl', $this);

    expect(collect($props['records'])->pluck('type')->sort()->values()->all())->toBe(['dcs', 'lor'])
        ->and($props['canFile'])->toBe(['dcs' => true, 'lor' => false])
        // Their own DCS they may change; the platoon's LOR they may not.
        ->and(collect($props['records'])->firstWhere('type', 'dcs')['canChange'])->toBeTrue()
        ->and(collect($props['records'])->firstWhere('type', 'lor')['canChange'])->toBeFalse();
});

it('flags a DCS dated within 90 days of an earlier one, by their dates, and keeps the flag as today moves on', function () {
    ($this->record)('dcs', 80, ['kind' => 'conduct', 'reason' => 'First.']);
    ($this->record)('dcs', 2, ['kind' => 'conduct', 'reason' => 'Second.']);

    $flags = fn () => collect(counselingProps('pl', $this)['records'])->pluck('flags', 'reason')->all();
    $expected = ['Second.' => [['key' => 'dcs-repeat', 'message' => 'Repeat within 90 days of a DCS: an LOR is the next step if it was for the same issue.']], 'First.' => []];

    expect($flags())->toBe($expected);

    // A year on, both have expired: the Platoon Leader no longer sees them, but an Admin still does, and the flag
    // stays, since the records were still 78 days apart.
    $this->travel(365)->days();
    expect($flags())->toBe([])
        ->and(collect(counselingProps('admin', $this)['records'])->pluck('flags', 'reason')->all())->toBe($expected);
});

it('weighs a record filed late with an earlier date by its date, and shows what each record is for', function () {
    ($this->record)('dcs', 10, ['kind' => 'missed_practice', 'reason' => 'Filed first, dated later.']);
    ($this->record)('dcs', 25, ['kind' => 'missed_practice', 'reason' => 'Filed second, dated earlier.']);

    $records = collect(counselingProps('pl', $this)['records'])->keyBy('reason');

    expect($records['Filed first, dated later.']['flags'][0]['key'])->toBe('missed-lor')
        ->and($records['Filed second, dated earlier.']['flags'])->toBe([])
        ->and($records['Filed first, dated later.']['kind'])->toBe('missed_practice')
        ->and($records['Filed first, dated later.']['kindLabel'])->toBe('Missed section practice or roll call');
});

it('shows no favorable action for 30 days after the latest LOR, and not on the 31st', function () {
    ($this->record)('lor', 0);
    $given = now('UTC');
    $until = now('UTC')->addDays(30);

    $this->travel(30)->days();
    expect(counselingProps('pl', $this)['warnings'])->toBe([[
        'key' => 'no-favorable-action',
        'message' => 'No favorable action until '.$until->format('dMy').', 30 days after the LOR of '.$given->format('dMy').'.',
        'until' => $until->toDateString(),
    ]]);

    $this->travel(1)->days();
    expect(counselingProps('pl', $this)['warnings'])->toBe([]);
});

it('sends the filing form a forecast for each type the viewer may file and each kind, from the records they see', function () {
    ($this->record)('dcs', 10, ['kind' => 'conduct']);

    $sl = counselingProps('sl', $this);
    expect(array_keys($sl['forecast']))->toBe(['dcs'])
        ->and(array_keys($sl['forecast']['dcs']))->toBe(['missed_practice', 'conduct'])
        ->and($sl['forecast']['dcs']['conduct'])->toBe([
            ['from' => '1970-01-01', 'flags' => []],
            ['from' => now('UTC')->subDays(10)->toDateString(), 'flags' => [['key' => 'dcs-repeat', 'message' => 'Repeat within 90 days of a DCS: an LOR is the next step if it was for the same issue.']]],
        ])
        ->and($sl['forecast']['dcs']['missed_practice'])->toBe([['from' => '1970-01-01', 'flags' => []]]);

    expect(array_keys(counselingProps('pl', $this)['forecast']))->toBe(['dcs', 'lor']);
});

it('says a record from before the portal asked what it is for was ruled as other conduct, only where that changes a flag', function () {
    ($this->record)('dcs', 20, ['kind' => null, 'reason' => 'Legacy, near a miss.']);
    ($this->record)('dcs', 10, ['kind' => 'missed_practice', 'reason' => 'A miss.']);
    ($this->record)('dcs', 300, ['kind' => null, 'reason' => 'Legacy, alone.']);

    // An Admin, who still sees the 300-day-old DCS after it expired.
    $records = collect(counselingProps('admin', $this)['records'])->keyBy('reason');

    expect($records['Legacy, near a miss.']['kindAssumed'])->toBeTrue()
        ->and($records['Legacy, alone.']['kindAssumed'])->toBeFalse()
        ->and($records['A miss.']['kindAssumed'])->toBeFalse();
});

it('shows a departed trooper\'s records read-only: listed as before, nothing to file or change, Admins included', function () {
    ($this->record)('lor', 3);
    ($this->record)('dcs', 5);
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();

    foreach (['admin', 'pl'] as $who) {
        $props = counselingProps($who, $this);

        expect($props['departed'])->toBeTrue()
            ->and($props['records'])->toHaveCount(2)
            ->and(collect($props['records'])->pluck('canChange')->unique()->all())->toBe([false])
            ->and($props['canFile'])->toBe(['dcs' => false, 'lor' => false])
            ->and($props['forecast'])->toBe([]);
    }
});

it('offers no filing or changes during a view-as preview, while still showing what the target sees', function () {
    ($this->record)('lor', 3);
    $admin = $this->tree['users']['admin'];
    app(ViewAs::class)->enter($admin, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['platoon']->id));

    $props = counselingProps('admin', $this);

    expect($props['records'])->toHaveCount(1)
        ->and($props['records'][0]['canChange'])->toBeFalse()
        ->and($props['canFile'])->toBe(['dcs' => false, 'lor' => false]);
});

it('reads the records in a fixed number of queries, however many there are', function () {
    foreach (range(1, 3) as $i) {
        ($this->record)($i % 2 ? 'dcs' : 'lor', $i, ['issued_by_admin' => $this->tree['users']['admin']->id, 'issued_as_unit' => Unit::query()->first()->id]);
    }
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        counselingProps('pl', $this);

        return count(DB::getQueryLog());
    };
    $few = $count();

    foreach (range(1, 6) as $i) {
        ($this->record)($i % 2 ? 'dcs' : 'lor', $i, ['issued_by_admin' => $this->tree['users']['admin']->id, 'issued_as_unit' => Unit::query()->first()->id]);
    }

    expect($count())->toBe($few);
});
