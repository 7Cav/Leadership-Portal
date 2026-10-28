<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Repositories\CounselingDocuments;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// #381: correcting a record's date or reason and deleting it, both audited with a required reason, by the issuer's
// chain of command or an Admin only; and the document download, only for someone who may see the record.

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->dcs = CounselingRecord::factory()->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['sl']->id,
        'issuer_unit_id' => $this->tree['units']['section']->id,
        'counseled_on' => now()->subDays(5)->toDateString(),
        'reason' => 'Late to practice twice.',
    ]);
    $this->lor = CounselingRecord::factory()->lor()->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['pl']->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'reason' => 'Disrespect toward an NCO.',
    ]);
    Storage::disk('counseling')->put($this->dcs->document_path, counselingPdfBytes('dcs-file'));
    Storage::disk('counseling')->put($this->lor->document_path, counselingPdfBytes('lor-file'));
    $this->correction = fn (array $overrides = []) => [
        'counseled_on' => now()->subDays(6)->toDateString(),
        'reason' => 'Late to practice three times.',
        'audit_reason' => 'Wrong date and count on the first filing.',
        ...$overrides,
    ];
});

it('lets the issuer correct the date and reason, audited with the reason given, and never the type', function () {
    $this->actingAs($this->tree['users']['sl'])
        ->patch(route('counseling.update', $this->dcs->id), ($this->correction)(['type' => 'lor']))
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    $record = $this->dcs->fresh();
    expect($record->counseled_on->toDateString())->toBe(now()->subDays(6)->toDateString())
        ->and($record->reason)->toBe('Late to practice three times.')
        ->and($record->type)->toBe(CounselingType::Dcs);

    expect(AuditLogEntry::query()->where('action', 'counseling.corrected')->sole())
        ->actor_id->toBe($this->tree['users']['sl']->id)
        ->target_id->toBe((string) $this->dcs->id)
        ->reason->toBe('Wrong date and count on the first filing.');
});

it('lets the chain above the issuer correct a record too', function () {
    $this->actingAs($this->tree['users']['co'])
        ->patch(route('counseling.update', $this->lor->id), ($this->correction)())
        ->assertRedirect();

    expect($this->lor->fresh()->reason)->toBe('Late to practice three times.');
});

it('requires a reason for a correction and for a delete', function () {
    $this->actingAs($this->tree['users']['sl'])
        ->patch(route('counseling.update', $this->dcs->id), ($this->correction)(['audit_reason' => ' ']))
        ->assertSessionHasErrors('audit_reason');
    $this->actingAs($this->tree['users']['sl'])
        ->delete(route('counseling.destroy', $this->dcs->id), [])
        ->assertSessionHasErrors('audit_reason');

    expect($this->dcs->fresh()->reason)->toBe('Late to practice twice.')
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('records nothing when a correction changes nothing', function () {
    $this->actingAs($this->tree['users']['sl'])
        ->patch(route('counseling.update', $this->dcs->id), ($this->correction)(['counseled_on' => $this->dcs->counseled_on->toDateString(), 'reason' => 'Late to practice twice.']))
        ->assertRedirect();

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses a leader outside the issuer\'s chain: 403 when they see the record, 404 when they do not', function () {
    // The Section Leader sees the platoon's LOR but did not file it and does not command the platoon.
    $this->actingAs($this->tree['users']['sl'])->patch(route('counseling.update', $this->lor->id), ($this->correction)())->assertForbidden();
    $this->actingAs($this->tree['users']['sl'])->delete(route('counseling.destroy', $this->lor->id), ['audit_reason' => 'x'])->assertForbidden();
    // A staff grant sees the LOR, never changes it, and does not see the DCS at all.
    $this->actingAs($this->tree['users']['grant'])->delete(route('counseling.destroy', $this->lor->id), ['audit_reason' => 'x'])->assertForbidden();
    $this->actingAs($this->tree['users']['grant'])->delete(route('counseling.destroy', $this->dcs->id), ['audit_reason' => 'x'])->assertNotFound();
    // Another section's leader sees neither.
    $this->actingAs($this->tree['users']['otherSl'])->patch(route('counseling.update', $this->dcs->id), ($this->correction)())->assertNotFound();
    $this->actingAs($this->tree['users']['otherSl'])->delete(route('counseling.destroy', 999999), ['audit_reason' => 'x'])->assertNotFound();

    expect(CounselingRecord::query()->count())->toBe(2)
        ->and($this->lor->fresh()->reason)->toBe('Disrespect toward an NCO.')
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('deletes a record and its document, audited with the reason and a digest of the row as it was', function () {
    $this->actingAs($this->tree['users']['pl'])
        ->delete(route('counseling.destroy', $this->dcs->id), ['audit_reason' => 'Filed on the wrong trooper.'])
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    expect(CounselingRecord::query()->find($this->dcs->id))->toBeNull();
    Storage::disk('counseling')->assertMissing($this->dcs->document_path);
    Storage::disk('counseling')->assertExists($this->lor->document_path);

    $entry = AuditLogEntry::query()->where('action', 'counseling.deleted')->sole();
    expect($entry->actor_id)->toBe($this->tree['users']['pl']->id)
        ->and($entry->reason)->toBe('Filed on the wrong trooper.')
        ->and($entry->content_digest)->toMatch('/^[0-9a-f]{64}$/')
        ->and(json_encode($entry->toArray()))->not->toContain('dcs-file');
});

it('lets an Admin change any record, and refuses everyone during a view-as preview', function () {
    $admin = $this->tree['users']['admin'];
    $this->actingAs($admin)->patch(route('counseling.update', $this->lor->id), ($this->correction)())->assertRedirect();

    app(ViewAs::class)->enter($admin, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['platoon']->id));
    $this->actingAs($admin)->delete(route('counseling.destroy', $this->lor->id), ['audit_reason' => 'x'])->assertForbidden();

    expect(CounselingRecord::query()->count())->toBe(2);
});

it('serves the document as a PDF attachment named after the record, to someone who may see it', function () {
    $response = $this->actingAs($this->tree['users']['pl'])->get(route('counseling.document', $this->dcs->id))->assertOk();

    $date = $this->dcs->counseled_on->toDateString();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=\"DCS {$date} Brandt.L.pdf\"")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->streamedContent())->toBe(counselingPdfBytes('dcs-file'));
});

it('answers 404 for the document to anyone who may not see the record, as for one that does not exist', function () {
    // The staff grant sees the LOR's document, not the DCS's.
    $this->actingAs($this->tree['users']['grant'])->get(route('counseling.document', $this->lor->id))->assertOk();
    $this->actingAs($this->tree['users']['grant'])->get(route('counseling.document', $this->dcs->id))->assertNotFound();
    $this->actingAs($this->tree['users']['otherSl'])->get(route('counseling.document', $this->lor->id))->assertNotFound();
    $this->actingAs($this->tree['users']['sl'])->get(route('counseling.document', 999999))->assertNotFound();

    // A record whose file is gone from the disk is a 404 too, never a 500.
    Storage::disk('counseling')->delete($this->lor->document_path);
    $this->actingAs($this->tree['users']['pl'])->get(route('counseling.document', $this->lor->id))->assertNotFound();
});

it('never reads a path from a stored record that the portal did not make', function () {
    $this->dcs->forceFill(['document_path' => '../../.env'])->save();

    $this->actingAs($this->tree['users']['pl'])->get(route('counseling.document', $this->dcs->id))->assertNotFound();
    expect(fn () => app(CounselingDocuments::class)->download('../../.env', 'x.pdf'))->toThrow(RuntimeException::class);
});

it('sends a signed-out visitor to sign in from every counseling route', function () {
    $this->get(route('counseling.document', $this->lor->id))->assertRedirect(route('login'));
    $this->post(route('counseling.store', $this->rifleman->id))->assertRedirect(route('login'));
    $this->patch(route('counseling.update', $this->lor->id))->assertRedirect(route('login'));
    $this->delete(route('counseling.destroy', $this->lor->id))->assertRedirect(route('login'));
});
