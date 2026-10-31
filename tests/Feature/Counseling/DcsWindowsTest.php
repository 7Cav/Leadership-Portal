<?php

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// #565: a DCS is active for 30 days after it was shared (the trooper and the chain see it), inactive from day 31 to day
// 90 (Platoon Leader and up and Admins only, still counting), and expired from day 91 (Admins only, no longer counting,
// kept forever). An LOR stays visible to the trooper and everyone who can see them, permanently.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->self = User::factory()->create(['cav_id' => $this->rifleman->cav_id, 'username' => 'Brandt.L']);
    $this->record = function (string $type, int $daysAgo, array $attributes = []) {
        $record = CounselingRecord::factory()->sharedDaysAgo($daysAgo)->create([
            'trooper_id' => $this->rifleman->id,
            'type' => $type,
            'counseled_on' => now('UTC')->subDays($daysAgo)->toDateString(),
            'issued_by' => $this->tree['users']['pl']->id,
            'issuer_unit_id' => $this->tree['units']['platoon']->id,
            ...$attributes,
        ]);
        Storage::disk('counseling')->put($record->document_path, counselingPdfBytes("record-{$record->id}"));

        return $record;
    };
    $this->props = fn (User $as) => $this->flushHeaders()->actingAs($as)->get(route('troopers.show', $this->rifleman->id))->assertOk()->viewData('page')['props']['counseling'];
    $this->ids = fn (User $as) => collect(($this->props)($as)['records'])->pluck('id')->all();
});

it('keeps a DCS active to day 30, inactive from day 31 to day 90, and expired from day 91, counted from when it was shared', function () {
    $day30 = ($this->record)('dcs', 30);
    $day31 = ($this->record)('dcs', 31);
    $day90 = ($this->record)('dcs', 90);
    $day91 = ($this->record)('dcs', 91);

    expect([$day30->dcsPhase(), $day31->dcsPhase(), $day90->dcsPhase(), $day91->dcsPhase()])->toBe(['active', 'inactive', 'inactive', 'expired'])
        ->and($day31->inactiveOn()->toDateString())->toBe(now('UTC')->toDateString())
        ->and($day91->expiresOn()->toDateString())->toBe(now('UTC')->toDateString());
});

it('judges a DCS written in the portal from the day it was shared, never from the date it carries', function () {
    $record = CounselingRecord::factory()->written()->sharedDaysAgo(5)->create([
        'trooper_id' => $this->rifleman->id, 'counseled_on' => now('UTC')->subDays(200)->toDateString(),
    ]);

    expect($record->dcsPhase())->toBe('active');
});

it('judges a DCS kept with its PDF (filed before #565, or imported) from the date it carries, as before', function () {
    // Counseled 100 days ago, uploaded 5 days ago: it had expired before #565, and stays expired.
    $record = ($this->record)('dcs', 5, ['counseled_on' => now('UTC')->subDays(100)->toDateString()]);

    expect($record->dcsPhase())->toBe('expired');
});

it('shows an active DCS to the trooper and the whole chain, the Section Leader included', function () {
    $active = ($this->record)('dcs', 10);

    foreach ([$this->self, $this->tree['users']['sl'], $this->tree['users']['pl'], $this->tree['users']['co'], $this->tree['users']['admin']] as $viewer) {
        expect(($this->ids)($viewer))->toBe([$active->id]);
    }
});

it('hides an inactive DCS from the trooper and their Section Leader, link included, and keeps it for Platoon Leader and up', function () {
    $inactive = ($this->record)('dcs', 45);

    foreach ([$this->self, $this->tree['users']['sl']] as $viewer) {
        expect(($this->ids)($viewer))->toBe([]);
        $this->actingAs($viewer)->get(route('counseling.show', $inactive->id))->assertNotFound();
        $this->actingAs($viewer)->get(route('counseling.document', $inactive->id))->assertNotFound();
    }

    foreach ([$this->tree['users']['pl'], $this->tree['users']['co'], $this->tree['users']['admin']] as $viewer) {
        expect(($this->ids)($viewer))->toBe([$inactive->id]);
        $this->actingAs($viewer)->get(route('counseling.show', $inactive->id))->assertOk();
    }
});

it('keeps counting an inactive DCS toward the standing for those who see it', function () {
    ($this->record)('dcs', 60, ['kind' => 'conduct']);
    $repeat = ($this->record)('dcs', 5, ['kind' => 'conduct']);

    $records = collect(($this->props)($this->tree['users']['pl'])['records'])->keyBy('id');

    expect(collect($records[$repeat->id]['flags'])->pluck('key')->all())->toBe(['dcs-repeat']);
});

it('never lets a DCS hidden from the trooper show through a flag on their own page', function () {
    ($this->record)('dcs', 60, ['kind' => 'conduct']);
    $repeat = ($this->record)('dcs', 5, ['kind' => 'conduct']);

    $records = collect(($this->props)($this->self)['records'])->keyBy('id');

    expect($records->keys()->all())->toBe([$repeat->id])
        ->and($records[$repeat->id]['flags'])->toBe([]);
});

it('shows an expired DCS to an Admin only, marked with its phase and the day it expired, its PDF still downloadable', function () {
    $expired = ($this->record)('dcs', 120);
    $active = ($this->record)('dcs', 10);
    $admin = $this->tree['users']['admin'];

    $records = collect(($this->props)($admin)['records'])->keyBy('id');

    expect($records)->toHaveCount(2)
        ->and($records[$expired->id]['dcsPhase'])->toBe('expired')
        ->and($records[$expired->id]['expiresOn'])->toBe(now('UTC')->subDays(29)->toDateString())
        ->and($records[$active->id]['dcsPhase'])->toBe('active');

    $this->actingAs($admin)->get(route('counseling.document', $expired->id))->assertOk()->assertDownload();

    foreach (['sl', 'pl', 'co'] as $who) {
        expect(($this->ids)($this->tree['users'][$who]))->toBe([$active->id]);
        $this->actingAs($this->tree['users'][$who])->get(route('counseling.document', $expired->id))->assertNotFound();
    }
});

it('keeps every row and file of an expired DCS: reading the page deletes nothing', function () {
    $expired = ($this->record)('dcs', 400);

    ($this->props)($this->tree['users']['pl']);
    ($this->props)($this->tree['users']['admin']);
    ($this->props)($this->self);

    expect(CounselingRecord::query()->count())->toBe(1);
    Storage::disk('counseling')->assertExists($expired->document_path);
});

it('stops counting an expired DCS: no warning, nothing in the forecast of a record dated today', function () {
    ($this->record)('dcs', 91, ['kind' => 'conduct']);

    $props = ($this->props)($this->tree['users']['pl']);
    $today = collect($props['forecast']['dcs']['conduct'])->last(fn (array $range) => $range['from'] <= now('UTC')->toDateString());

    expect($today['flags'])->toBe([])
        ->and($props['warnings'])->toBe([]);
});

it('keeps an expired DCS counting for a newer record dated within 90 days of it: the repeat DCS flag stays', function () {
    ($this->record)('dcs', 100, ['kind' => 'conduct']);
    $repeat = ($this->record)('dcs', 20, ['kind' => 'conduct']);

    $records = collect(($this->props)($this->tree['users']['pl'])['records'])->keyBy('id');

    expect($records)->toHaveCount(1)
        ->and(collect($records[$repeat->id]['flags'])->pluck('key')->all())->toBe(['dcs-repeat']);
});

it('leaves an LOR alone: listed for leaders and the trooper long after 90 days', function () {
    $lor = ($this->record)('lor', 300);

    expect($lor->isExpired())->toBeFalse()->and($lor->expiresOn())->toBeNull()->and($lor->dcsPhase())->toBeNull();

    foreach ([$this->tree['users']['pl'], $this->tree['users']['sl'], $this->self] as $viewer) {
        $records = ($this->props)($viewer)['records'];
        expect($records)->toHaveCount(1)
            ->and($records[0]['type'])->toBe(CounselingType::Lor->value);
    }
});
