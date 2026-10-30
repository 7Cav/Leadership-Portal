<?php

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// A DCS expires 90 days after it was counseled: on day 90 it is still active, from day 91 it has expired. An expired DCS
// stays on file (row, PDF and Drive copy), seen by Admins only, marked expired. An LOR never expires.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->self = User::factory()->create(['cav_id' => $this->rifleman->cav_id, 'username' => 'Brandt.L']);
    $this->record = function (string $type, int $daysAgo, array $attributes = []) {
        $record = CounselingRecord::factory()->create([
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
});

it('keeps a DCS active on day 90 and expires it on day 91', function () {
    $day90 = ($this->record)('dcs', 90);
    $day91 = ($this->record)('dcs', 91);

    expect($day90->isExpired())->toBeFalse()
        ->and($day91->isExpired())->toBeTrue()
        ->and($day91->expiresOn()->toDateString())->toBe(now('UTC')->toDateString());

    expect(collect(($this->props)($this->tree['users']['pl'])['records'])->pluck('id')->all())->toBe([$day90->id]);
});

it('hides an expired DCS from leaders: off the page, and its document and changes answer 404', function () {
    $expired = ($this->record)('dcs', 120, ['reason' => 'Old developmental counseling text.']);

    foreach (['sl', 'pl', 'co'] as $who) {
        $user = $this->tree['users'][$who];
        expect(($this->props)($user)['records'])->toBe([]);

        $this->actingAs($user)->get(route('counseling.document', $expired->id))->assertNotFound();
        $this->actingAs($user)->patch(route('counseling.update', $expired->id), [
            'counseled_on' => now('UTC')->subDays(5)->toDateString(), 'reason' => 'Changed.', 'audit_reason' => 'x',
        ])->assertNotFound();
        $this->actingAs($user)->delete(route('counseling.destroy', $expired->id), ['audit_reason' => 'x'])->assertNotFound();
    }

    $this->flushHeaders()->actingAs($this->tree['users']['pl'])->get(route('troopers.show', $this->rifleman->id))->assertDontSee('Old developmental counseling text.');
    expect(CounselingRecord::query()->find($expired->id)->reason)->toBe('Old developmental counseling text.');
});

it('hides an expired DCS from the trooper\'s own page and its document', function () {
    $expired = ($this->record)('dcs', 120);
    $active = ($this->record)('dcs', 10);

    expect(collect(($this->props)($this->self)['records'])->pluck('id')->all())->toBe([$active->id]);
    $this->actingAs($this->self)->get(route('counseling.document', $expired->id))->assertNotFound();
    $this->actingAs($this->self)->get(route('counseling.document', $active->id))->assertOk();
});

it('shows an expired DCS to an Admin, marked expired with the date, its PDF still downloadable', function () {
    $expired = ($this->record)('dcs', 120);
    $active = ($this->record)('dcs', 10);
    $admin = $this->tree['users']['admin'];

    $records = collect(($this->props)($admin)['records'])->keyBy('id');

    expect($records)->toHaveCount(2)
        ->and($records[$expired->id]['expired'])->toBe(['date' => now('UTC')->subDays(29)->toDateString()])
        ->and($records[$active->id]['expired'])->toBeNull();

    $this->actingAs($admin)->get(route('counseling.document', $expired->id))->assertOk()->assertDownload();
});

it('keeps an Admin\'s right to correct an expired DCS', function () {
    $expired = ($this->record)('dcs', 120);

    $this->actingAs($this->tree['users']['admin'])->patch(route('counseling.update', $expired->id), [
        'counseled_on' => $expired->counseled_on->toDateString(), 'reason' => 'Corrected by an Admin.', 'audit_reason' => 'Typo.',
    ])->assertRedirect();

    expect($expired->fresh()->reason)->toBe('Corrected by an Admin.');
});

it('keeps every row and file of an expired DCS: reading the page deletes nothing', function () {
    $expired = ($this->record)('dcs', 400, ['drive_status' => 'uploaded']);

    ($this->props)($this->tree['users']['pl']);
    ($this->props)($this->tree['users']['admin']);
    ($this->props)($this->self);

    expect(CounselingRecord::query()->count())->toBe(1)
        ->and($expired->fresh()->drive_status->value)->toBe('uploaded');
    Storage::disk('counseling')->assertExists($expired->document_path);
});

it('drops an expired DCS from the warnings and from the forecast of a record dated today', function () {
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

    expect($lor->isExpired())->toBeFalse()->and($lor->expiresOn())->toBeNull();

    foreach ([$this->tree['users']['pl'], $this->self] as $viewer) {
        $records = ($this->props)($viewer)['records'];
        expect($records)->toHaveCount(1)
            ->and($records[0]['type'])->toBe(CounselingType::Lor->value)
            ->and($records[0]['expired'])->toBeNull();
    }
});
