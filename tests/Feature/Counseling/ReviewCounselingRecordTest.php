<?php

use App\Models\AuditLogEntry;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingSource;
use App\Models\CounselingType;
use App\Models\TrooperStatus;
use Carbon\CarbonImmutable;

require_once __DIR__.'/fixture.php';

// #565: `POST /counseling/{id}/review` fills in the fields of a record kept with its source PDF (an upload from before
// #565, or an import) and clears its review flag. The issuer, their chain and Admins may; it is audited.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->imported = fn (array $attributes = []) => CounselingRecord::factory()->sharedDaysAgo(5)->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['pl']->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'source' => CounselingSource::Import,
        'needs_review' => true,
        'import_key' => 'drive-file-id-123456',
        ...$attributes,
    ]);
});

function reviewBody(array $override = []): array
{
    return [
        'type' => 'lor',
        'kind' => 'conduct',
        'counseled_on' => '2026-10-20',
        'form' => [
            'infraction' => 'Missed three section practices.',
            'regulation' => 'Platoon SOP, attendance.',
            'discussion' => 'Talked it through.',
            'plan_authority' => 'Weekly check-in.',
            'consequences' => ['nfa'],
            'plan_trooper' => 'I will post an LOA next time.',
            'comments_trooper' => 'Understood.',
            'appeal' => 'do_not',
        ],
        ...$override,
    ];
}

it('fills in an imported record\'s fields, clears its review flag and audits it', function () {
    $record = ($this->imported)();
    $before = $record->version;

    $this->actingAs($this->tree['users']['pl'])->from("/counseling/{$record->id}")
        ->post("/counseling/{$record->id}/review", reviewBody())
        ->assertRedirect("/counseling/{$record->id}")->assertSessionHasNoErrors();

    $record->refresh();
    expect($record->needs_review)->toBeFalse()
        ->and($record->type)->toBe(CounselingType::Lor)
        ->and($record->kind)->toBe(CounselingKind::Conduct)
        ->and($record->counseled_on->toDateString())->toBe('2026-10-20')
        ->and($record->form_data)->toMatchArray([
            'infraction' => 'Missed three section practices.',
            'consequences' => ['nfa'],
            'plan_trooper' => 'I will post an LOA next time.',
            'comments_trooper' => 'Understood.',
            'appeal' => 'do_not',
        ])
        ->and($record->version)->toBe($before + 1)
        ->and($record->document_path)->not->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.reviewed')->sole()->actor_id)->toBe($this->tree['users']['pl']->id);
});

it('lets an Admin fill in a record filed before #565, and drops the LOR fields from a DCS', function () {
    $record = ($this->imported)(['source' => CounselingSource::Upload, 'needs_review' => false, 'import_key' => null]);

    $this->actingAs($this->tree['users']['admin'])->post("/counseling/{$record->id}/review", reviewBody(['type' => 'dcs']))->assertSessionHasNoErrors();

    expect($record->refresh()->form_data)->toMatchArray(['consequences' => [], 'special_punishments' => '', 'appeal' => null]);
});

it('refuses who may not fill it in, and a record without a source document', function () {
    $record = ($this->imported)();

    $this->actingAs($this->tree['users']['otherSl'])->post("/counseling/{$record->id}/review", reviewBody())->assertNotFound();
    // A staff grant sees an LOR on the trooper, but is not in its issuer's chain.
    $lor = ($this->imported)(['type' => CounselingType::Lor, 'import_key' => 'another-drive-file-id']);
    $this->actingAs($this->tree['users']['grant'])->post("/counseling/{$lor->id}/review", reviewBody())->assertForbidden();

    $written = CounselingRecord::factory()->written()->create(['trooper_id' => $this->rifleman->id, 'issued_by' => $this->tree['users']['pl']->id]);
    $this->actingAs($this->tree['users']['admin'])->post("/counseling/{$written->id}/review", reviewBody())->assertForbidden();

    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();
    $this->actingAs($this->tree['users']['admin'])->post("/counseling/{$record->id}/review", reviewBody())->assertForbidden();

    expect($record->refresh()->needs_review)->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'counseling.reviewed')->count())->toBe(0);
});

it('checks the date and the fields', function (array $override, string $field) {
    $record = ($this->imported)();

    $this->actingAs($this->tree['users']['admin'])->post("/counseling/{$record->id}/review", reviewBody($override))->assertSessionHasErrors($field);
    expect($record->refresh()->needs_review)->toBeTrue();
})->with([
    'no date' => [['counseled_on' => null], 'counseled_on'],
    'future date' => [['counseled_on' => '2026-11-30'], 'counseled_on'],
    'bad kind' => [['kind' => 'other'], 'kind'],
    'long discussion' => [['form' => ['discussion' => str_repeat('x', 3001)]], 'form.discussion'],
    'bad appeal' => [['form' => ['appeal' => 'maybe']], 'form.appeal'],
]);

it('answers 404 for a record that does not exist', function () {
    $this->actingAs($this->tree['users']['admin'])->post('/counseling/999999/review', reviewBody())->assertNotFound();
});
