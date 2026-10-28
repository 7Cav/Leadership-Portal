<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Http\Middleware\HandleInertiaRequests;
use App\Milpac\CounselingHistory;
use App\Models\CounselingRecord;
use App\Models\DriveUploadStatus;
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
            'document' => ['name' => 'lor.pdf', 'size' => 2048],
            'driveStatus' => 'skipped',
            'counts' => true,
            'canChange' => true,
        ])
        ->and($props['records'][1]['typeLabel'])->toBe('Developmental Counseling Statement')
        ->and($props['records'][1]['driveStatus'])->toBe('uploaded')
        ->and($props['canFile'])->toBe(['dcs' => true, 'lor' => true])
        ->and($props['windowDays'])->toBe(90);
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
        // Two DCSs in 90 days would warn; the warning must not give them away either.
        ->not->toContain('lor-due');

    $props = counselingProps('grant', $this);
    expect(collect($props['records'])->pluck('type')->all())->toBe(['lor'])
        ->and($props['counts'])->toBe(['dcs' => 0, 'lor' => 1])
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

it('warns when a second DCS falls within 90 days of one: the next step is an LOR', function () {
    ($this->record)('dcs', 80);
    ($this->record)('dcs', 2);

    $props = counselingProps('pl', $this);

    expect($props['counts'])->toBe(['dcs' => 2, 'lor' => 0])
        ->and($props['warnings'])->toBe([['key' => 'lor-due', 'message' => '2 Developmental Counseling Statements in 90 days. A repeat within 90 days of a DCS calls for a Letter of Reprimand.']]);
});

it('keeps a DCS older than 90 days on the record, but no longer counts it toward the warning', function () {
    ($this->record)('dcs', 91, ['reason' => 'An old counseling.']);
    ($this->record)('dcs', 2);

    $props = counselingProps('pl', $this);
    $old = collect($props['records'])->firstWhere('reason', 'An old counseling.');

    expect($props['records'])->toHaveCount(2)
        ->and($old['counts'])->toBeFalse()
        ->and($old['countsUntil'])->toBe(now('UTC')->subDays(1)->toDateString())
        ->and($props['counts'])->toBe(['dcs' => 1, 'lor' => 0])
        ->and($props['warnings'])->toBe([]);
});

it('warns at a second LOR in 90 days that the trooper may be removed as SL or ASL, and at a third of an Article 15', function () {
    ($this->record)('lor', 60);
    ($this->record)('lor', 10);

    expect(counselingProps('pl', $this)['warnings'])->toBe([['key' => 'removal', 'message' => '2 Letters of Reprimand in 90 days. The trooper may be removed as Section Leader or Assistant Section Leader.']]);

    ($this->record)('lor', 1);

    expect(counselingProps('pl', $this)['warnings'])->toBe([['key' => 'article-15', 'message' => '3 Letters of Reprimand in 90 days. The regulations call for an Article 15.']]);
});

it('counts the 90th day back and not the 91st', function () {
    expect(CounselingHistory::windowStart()->toDateString())->toBe(now('UTC')->subDays(90)->toDateString());

    ($this->record)('lor', 90);
    ($this->record)('lor', 1);
    expect(counselingProps('pl', $this)['counts']['lor'])->toBe(2);
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
