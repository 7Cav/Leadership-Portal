<?php

use App\Authorization\CounselingPermissions;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\Counseling\AppealCounselingRecord;
use App\Commands\Counseling\AppealCounselingRecordHandler;
use App\Commands\Counseling\CorrectCounselingRecord;
use App\Commands\Counseling\CorrectCounselingRecordHandler;
use App\Commands\Counseling\CounselingForbiddenException;
use App\Commands\Counseling\DeleteCounselingRecord;
use App\Commands\Counseling\DeleteCounselingRecordHandler;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\TrooperStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// A DCS freezes 72 hours after it is filed, like an LOR: from then on nobody corrects or deletes it, Admins included,
// and it has no overturn. Within those 72 hours the issuer's chain may still correct it, and the trooper, signed in as
// themselves, may file one appeal on an LOR or one comment or dispute on a DCS.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->self = User::factory()->create(['cav_id' => $this->rifleman->cav_id, 'username' => 'Brandt.L']);
    $this->dcs = CounselingRecord::factory()->filedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['sl']->id,
        'issuer_unit_id' => $this->tree['units']['section']->id,
        'counseled_on' => now()->subDays(3)->toDateString(),
        'reason' => 'Missed practice.',
    ]);
    $this->lor = CounselingRecord::factory()->lor()->filedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['pl']->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => now()->subDays(3)->toDateString(),
        'reason' => 'Disrespect toward an NCO.',
    ]);
    $this->correction = [
        'counseled_on' => now()->subDays(4)->toDateString(),
        'reason' => 'Missed practice, twice.',
        'audit_reason' => 'Count was wrong.',
    ];
    $this->props = fn (User $as) => collect($this->flushHeaders()->actingAs($as)->get(route('troopers.show', $this->rifleman->id))
        ->assertOk()->viewData('page')['props']['counseling']['records'])->keyBy('id');
});

it('freezes a DCS exactly 72 hours after it is filed', function () {
    $filed = CarbonImmutable::instance($this->dcs->created_at);

    expect(CounselingPermissions::frozen($this->dcs, $filed->addHours(72)->subSecond()))->toBeFalse()
        ->and(CounselingPermissions::frozen($this->dcs, $filed->addHours(72)))->toBeTrue()
        ->and($this->dcs->freezesAt()->equalTo($filed->addHours(72)))->toBeTrue();
});

it('refuses every correction and delete of a frozen DCS, the issuer\'s chain and the flag Admin included', function () {
    $this->travel(70)->hours();

    foreach (['sl', 'pl', 'co', 'admin'] as $who) {
        $user = $this->tree['users'][$who];
        $this->actingAs($user)->patch(route('counseling.update', $this->dcs->id), $this->correction)->assertForbidden();
        $this->actingAs($user)->delete(route('counseling.destroy', $this->dcs->id), ['audit_reason' => 'Wrong trooper.'])->assertForbidden();
    }

    $message = 'This DCS was filed more than 72 hours ago, so it can no longer be changed.';
    $admin = $this->tree['users']['admin'];
    expect(fn () => app(CorrectCounselingRecordHandler::class)->handle($admin, new CorrectCounselingRecord($this->dcs->id, CarbonImmutable::now()->subDay(), 'New.', 'Typo.')))
        ->toThrow(CounselingForbiddenException::class, $message)
        ->and(fn () => app(DeleteCounselingRecordHandler::class)->handle($admin, new DeleteCounselingRecord($this->dcs->id, 'Wrong trooper.')))
        ->toThrow(CounselingForbiddenException::class, $message);

    expect($this->dcs->fresh()->reason)->toBe('Missed practice.')
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('marks a frozen DCS frozen with the time it froze, and offers no overturn on it', function () {
    $this->travel(70)->hours();

    $records = ($this->props)($this->tree['users']['admin']);
    expect($records[$this->dcs->id])->toMatchArray(['frozen' => true, 'canChange' => false, 'canOverturn' => false, 'canAppeal' => false])
        ->and($records[$this->dcs->id]['freezesAt'])->toBe('2026-11-02T10:00:00Z');
});

it('still expires a frozen DCS 90 days after it was counseled', function () {
    $this->dcs->forceFill(['counseled_on' => now()->subDays(91)->toDateString()])->save();
    $this->travel(70)->hours();

    expect(CounselingPermissions::frozen($this->dcs->fresh()))->toBeTrue()
        ->and($this->dcs->fresh()->isExpired())->toBeTrue()
        ->and(($this->props)($this->tree['users']['pl'])->has($this->dcs->id))->toBeFalse();
});

it('leaves an LOR as it was: correctable within 72 hours, frozen after with the overturn message', function () {
    $this->actingAs($this->tree['users']['pl'])->patch(route('counseling.update', $this->lor->id), $this->correction)->assertRedirect();
    expect($this->lor->fresh()->reason)->toBe('Missed practice, twice.');

    $this->travel(70)->hours();
    $this->actingAs($this->tree['users']['pl'])->patch(route('counseling.update', $this->lor->id), $this->correction)
        ->assertForbidden()
        ->assertSee('The Regimental Executive Officer can overturn it.');
});

it('lets the issuer\'s chain correct a DCS within its 72 hours', function () {
    $this->travel(69)->hours();

    foreach (['sl', 'pl'] as $who) {
        $this->actingAs($this->tree['users'][$who])->patch(route('counseling.update', $this->dcs->id), $this->correction)->assertRedirect();
    }
    expect($this->dcs->fresh()->reason)->toBe('Missed practice, twice.')
        ->and(AuditLogEntry::query()->where('action', 'counseling.corrected')->count())->toBe(1);

    expect(($this->props)($this->tree['users']['sl'])[$this->dcs->id])->toMatchArray(['frozen' => false, 'canChange' => true, 'canAppeal' => false]);
});

it('lets the trooper comment on or dispute a DCS within 72 hours: on the record, audited, seen by the chain', function () {
    expect(($this->props)($this->self)[$this->dcs->id])->toMatchArray(['canAppeal' => true, 'canChange' => false, 'appeal' => null]);

    $this->actingAs($this->self)
        ->post(route('counseling.appeal', $this->dcs->id), ['statement' => 'I told my SL I would be late.'])
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    $record = $this->dcs->fresh();
    expect($record->appealed_at->toDateTimeString())->toBe('2026-10-30 12:00:00')
        ->and($record->appealed_by)->toBe($this->self->id)
        ->and($record->appeal_statement)->toBe('I told my SL I would be late.')
        ->and($record->reason)->toBe('Missed practice.')
        ->and($record->form_data)->toBeNull();

    expect(AuditLogEntry::query()->where('action', 'counseling.appealed')->sole())
        ->actor_id->toBe($this->self->id)
        ->on_behalf_of_id->toBeNull()
        ->target_id->toBe((string) $this->dcs->id)
        ->reason->toBe('I told my SL I would be late.');

    expect(($this->props)($this->tree['users']['sl'])[$this->dcs->id]['appeal'])->toBe(['at' => '2026-10-30T12:00:00Z', 'statement' => 'I told my SL I would be late.'])
        ->and(($this->props)($this->self)[$this->dcs->id]['canAppeal'])->toBeFalse();
});

it('sets the form\'s appeal answer on an LOR made from the form, and leaves the rest of the form as it was', function () {
    $this->lor->forceFill(['form_data' => ['infraction' => 'Disrespect.', 'appeal' => 'do_not']])->save();

    $this->actingAs($this->self)->post(route('counseling.appeal', $this->lor->id), ['statement' => 'It was a misunderstanding.'])->assertRedirect();

    expect($this->lor->fresh())
        ->form_data->toBe(['infraction' => 'Disrespect.', 'appeal' => 'do'])
        ->appeal_statement->toBe('It was a misunderstanding.');
    expect(AuditLogEntry::query()->where('action', 'counseling.appealed')->sole()->target_id)->toBe((string) $this->lor->id);
});

it('refuses the appeal without a statement, a second time, or after the 72 hours', function () {
    $this->actingAs($this->self)->post(route('counseling.appeal', $this->lor->id), ['statement' => ' '])->assertSessionHasErrors('statement');
    $this->actingAs($this->self)->post(route('counseling.appeal', $this->lor->id), ['statement' => str_repeat('x', 2001)])->assertSessionHasErrors('statement');

    $appeal = fn (int $id) => app(AppealCounselingRecordHandler::class)->handle($this->self, new AppealCounselingRecord($id, 'I appeal.'));
    $appeal($this->lor->id);
    expect(fn () => $appeal($this->lor->id))->toThrow(CounselingForbiddenException::class, 'You already appealed this LOR.');
    $this->actingAs($this->self)->post(route('counseling.appeal', $this->lor->id), ['statement' => 'Again.'])->assertForbidden();

    $this->travel(70)->hours();
    expect(fn () => $appeal($this->dcs->id))->toThrow(CounselingForbiddenException::class, 'This DCS was filed more than 72 hours ago, so it can no longer be commented on or disputed.');
    $this->actingAs($this->self)->post(route('counseling.appeal', $this->dcs->id), ['statement' => 'Late.'])->assertForbidden();

    expect($this->lor->fresh()->appeal_statement)->toBe('I appeal.')
        ->and($this->dcs->fresh()->appealed_at)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.appealed')->count())->toBe(1);
});

it('refuses anyone but the trooper: the chain and an Admin cannot appeal for them', function () {
    foreach (['sl', 'pl', 'admin'] as $who) {
        $this->actingAs($this->tree['users'][$who])->post(route('counseling.appeal', $this->dcs->id), ['statement' => 'For them.'])->assertForbidden();
    }
    $this->actingAs($this->tree['users']['otherSl'])->post(route('counseling.appeal', $this->lor->id), ['statement' => 'x'])->assertNotFound();

    expect(fn () => app(AppealCounselingRecordHandler::class)->handle($this->tree['users']['admin'], new AppealCounselingRecord($this->dcs->id, 'For them.')))
        ->toThrow(CounselingForbiddenException::class, 'Only the trooper, signed in as themselves, can appeal their own record.');
    expect(AuditLogEntry::query()->where('action', 'counseling.appealed')->count())->toBe(0);
});

it('refuses the appeal through act as', function () {
    $admin = $this->tree['users']['admin'];
    $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $this->self->id]);

    expect(($this->props)($admin)->get($this->dcs->id)['canAppeal'] ?? false)->toBeFalse();
    $this->actingAs($admin)->post(route('counseling.appeal', $this->dcs->id), ['statement' => 'As them.'])->assertForbidden();

    expect($this->dcs->fresh()->appealed_at)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.appealed')->count())->toBe(0);
});

it('refuses the appeal during a view-as preview, and offers none then', function () {
    // The trooper is an Admin too, so the preview is one they may open.
    $this->self->forceFill(['is_admin' => true])->save();
    app(ViewAs::class)->enter($this->self, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['section']->id));

    expect(($this->props)($this->self)->get($this->dcs->id)['canAppeal'] ?? false)->toBeFalse();
    $this->actingAs($this->self)->post(route('counseling.appeal', $this->dcs->id), ['statement' => 'Previewing.'])->assertForbidden();
    expect($this->dcs->fresh()->appealed_at)->toBeNull();
});

it('refuses the appeal of a trooper who has left the Cav', function () {
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();

    expect(fn () => app(AppealCounselingRecordHandler::class)->handle($this->self, new AppealCounselingRecord($this->dcs->id, 'Left.')))
        ->toThrow(Exception::class);
    expect($this->dcs->fresh()->appealed_at)->toBeNull();
});
