<?php

use App\Authorization\CounselingPermissions;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\Counseling\CorrectCounselingRecord;
use App\Commands\Counseling\CorrectCounselingRecordHandler;
use App\Commands\Counseling\CounselingForbiddenException;
use App\Commands\Counseling\DeleteCounselingRecord;
use App\Commands\Counseling\DeleteCounselingRecordHandler;
use App\Commands\Counseling\OverturnCounselingRecord;
use App\Commands\Counseling\OverturnCounselingRecordHandler;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\AuditLogEntry;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// S6, 2026-10-29: an LOR is frozen 72 hours after it is filed, its appeal window (the wiki: 24 hours to say they appeal,
// the appeal done within 72, no further appeal). Until then whoever may change it still can; after it nobody corrects or
// deletes it, Admins included. Only the Regimental Executive Officer may then overturn it, with a reason: it stays on
// file, marked overturned, and stops counting toward the counseling rules. A DCS is unchanged.

/** A serving trooper on `$billet`, whose position commands `$commands`, placed there, with a portal account. */
function freezeHolder(int $cavId, string $username, string $billet, Unit $commands): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $commands->id, 'cav_position_id' => 8000 + $cavId]);
    Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => 8000 + $cavId, 'billet' => $billet, 'status' => TrooperStatus::Combat,
        'username' => $username, 'primary_section_id' => $commands->id,
    ]);

    return User::factory()->create(['cav_id' => $cavId, 'username' => $username]);
}

/** The counseling panel's props for whoever the request runs as. */
function freezeProps(object $test, User $as, Trooper $trooper): array
{
    return $test->flushHeaders()->actingAs($as)->get(route('troopers.show', $trooper->id))->assertOk()->viewData('page')['props']['counseling'];
}

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->rxo = freezeHolder(500, 'Vance.R', 'Regimental Executive Officer', $this->tree['units']['regiment']);
    $this->rco = freezeHolder(501, 'Stone.A', 'Regimental Commanding Officer', $this->tree['units']['regiment']);
    $this->lor = CounselingRecord::factory()->lor()->filedHoursAgo()->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['pl']->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => now()->subDays(4)->toDateString(),
        'reason' => 'Disrespect toward an NCO.',
    ]);
    Storage::disk('counseling')->put($this->lor->document_path, counselingPdfBytes('frozen-lor'));
    $this->correction = [
        'counseled_on' => now()->subDays(5)->toDateString(),
        'reason' => 'Disrespect toward an NCO, twice.',
        'audit_reason' => 'Count was wrong.',
    ];
});

it('freezes an LOR exactly 72 hours after it is filed, not a second before', function () {
    $filed = CarbonImmutable::now()->subDays(10)->setTime(18, 30);
    $lor = CounselingRecord::factory()->lor()->create([
        'trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => $filed->toDateString(), 'created_at' => $filed, 'updated_at' => $filed,
    ]);
    $this->correction['counseled_on'] = $filed->toDateString();

    expect(CounselingPermissions::frozen($lor, $filed->addHours(72)->subSecond()))->toBeFalse()
        ->and(CounselingPermissions::frozen($lor, $filed->addHours(72)))->toBeTrue();

    $this->travelTo($filed->addHours(72)->subSecond());
    $this->actingAs($this->tree['users']['pl'])->patch(route('counseling.update', $lor->id), $this->correction)->assertRedirect();
    expect($lor->fresh()->reason)->toBe('Disrespect toward an NCO, twice.');

    $this->travelTo($filed->addHours(72));
    $this->actingAs($this->tree['users']['pl'])
        ->patch(route('counseling.update', $lor->id), [...$this->correction, 'reason' => 'Changed again.'])
        ->assertForbidden();
    expect($lor->fresh()->reason)->toBe('Disrespect toward an NCO, twice.');
});

it('judges the window from when the LOR was filed, never from the date it carries', function () {
    // Dated a month back but filed an hour ago: still in its window.
    $late = CounselingRecord::factory()->lor()->filedHoursAgo(1)->create([
        'trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => now()->subDays(30)->toDateString(),
    ]);

    $this->actingAs($this->tree['users']['pl'])->patch(route('counseling.update', $late->id), $this->correction)->assertRedirect();
    expect($late->fresh()->reason)->toBe('Disrespect toward an NCO, twice.');
});

it('refuses every correction and delete of a frozen LOR, the flag Admin and the issuer included, with a message that says why', function () {
    foreach (['admin', 'pl', 'co'] as $who) {
        $user = $this->tree['users'][$who];
        $this->actingAs($user)->patch(route('counseling.update', $this->lor->id), $this->correction)->assertForbidden();
        $this->actingAs($user)->delete(route('counseling.destroy', $this->lor->id), ['audit_reason' => 'Upheld appeal.'])->assertForbidden();
    }

    $message = 'This LOR was filed more than 72 hours ago, so it can no longer be changed. The Regimental Executive Officer can overturn it.';
    $admin = $this->tree['users']['admin'];
    expect(fn () => app(CorrectCounselingRecordHandler::class)->handle($admin, new CorrectCounselingRecord($this->lor->id, CarbonImmutable::now()->subDay(), 'New.', 'Typo.')))
        ->toThrow(CounselingForbiddenException::class, $message)
        ->and(fn () => app(DeleteCounselingRecordHandler::class)->handle($admin, new DeleteCounselingRecord($this->lor->id, 'Upheld appeal.')))
        ->toThrow(CounselingForbiddenException::class, $message);

    expect($this->lor->fresh()->reason)->toBe('Disrespect toward an NCO.')
        ->and(AuditLogEntry::query()->count())->toBe(0);
    Storage::disk('counseling')->assertExists($this->lor->document_path);
});

it('refuses an Admin by billet a frozen LOR too', function () {
    Trooper::factory()->create([
        'cav_id' => 600, 'cav_position_id' => 9600, 'billet' => 'Regimental Chief of Staff', 'status' => TrooperStatus::Combat,
        'primary_section_id' => $this->tree['units']['regiment']->id,
    ]);
    AdminBillet::query()->create([
        'cav_position_id' => 9600, 'title' => 'Regimental Chief of Staff', 'group_title' => AdminBillet::REGIMENTAL_HQ,
        'proposed_title' => 'Regimental Chief of Staff', 'proposed_group_title' => AdminBillet::REGIMENTAL_HQ,
        'status' => AdminBilletStatus::Approved,
    ]);
    $chief = User::factory()->create(['cav_id' => 600]);

    $this->actingAs($chief)->delete(route('counseling.destroy', $this->lor->id), ['audit_reason' => 'Upheld appeal.'])->assertForbidden();
    expect(CounselingRecord::query()->find($this->lor->id))->not->toBeNull();
});

it('leaves a DCS correctable and deletable however long ago it was filed', function () {
    $dcs = CounselingRecord::factory()->filedHoursAgo(24 * 30)->create([
        'trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['section']->id,
    ]);

    expect(CounselingPermissions::frozen($dcs))->toBeFalse();
    $this->actingAs($this->tree['users']['sl'])->patch(route('counseling.update', $dcs->id), $this->correction)->assertRedirect();
    $this->actingAs($this->tree['users']['sl'])->delete(route('counseling.destroy', $dcs->id), ['audit_reason' => 'Wrong trooper.'])->assertRedirect();
    expect(CounselingRecord::query()->find($dcs->id))->toBeNull();
});

it('lets the RXO overturn a frozen LOR: kept on file, marked, audited with the reason', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00'));

    $this->actingAs($this->rxo)
        ->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld by the RXO after review.'])
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    $record = $this->lor->fresh();
    expect($record)->not->toBeNull()
        ->and($record->overturned_at->toDateTimeString())->toBe('2026-10-29 12:00:00')
        ->and($record->overturned_by)->toBe($this->rxo->id)
        ->and($record->overturned_by_admin)->toBeNull()
        ->and($record->overturn_reason)->toBe('Appeal upheld by the RXO after review.')
        ->and($record->reason)->toBe('Disrespect toward an NCO.');

    expect(AuditLogEntry::query()->where('action', 'counseling.overturned')->sole())
        ->actor_id->toBe($this->rxo->id)
        ->on_behalf_of_id->toBeNull()
        ->target_id->toBe((string) $this->lor->id)
        ->reason->toBe('Appeal upheld by the RXO after review.');

    // Whoever could see it still sees it, overturned, and can download it.
    $props = freezeProps($this, $this->tree['users']['grant'], $this->rifleman);
    expect($props['records'][0]['overturned'])->toBe(['date' => '2026-10-29', 'by' => 'Vance.R', 'reason' => 'Appeal upheld by the RXO after review.'])
        ->and($props['records'][0]['canChange'])->toBeFalse()
        ->and($props['records'][0]['canOverturn'])->toBeFalse();
    $this->actingAs($this->tree['users']['grant'])->get(route('counseling.document', $this->lor->id))->assertOk();
    $this->actingAs($this->tree['users']['pl'])
        ->get(route('counseling.document', $this->lor->id))->assertOk();
});

it('requires a reason to overturn', function () {
    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => '  '])->assertSessionHasErrors('reason');
    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');

    expect($this->lor->fresh()->overturned_at)->toBeNull()
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses an overturn by anyone but the RXO, Admins and the RCO as themselves included', function () {
    foreach ([$this->tree['users']['admin'], $this->tree['users']['co'], $this->tree['users']['pl'], $this->rco, $this->tree['users']['grant']] as $user) {
        $this->actingAs($user)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertForbidden();
    }
    // Someone who cannot see the trooper gets the 404 a missing record gets.
    $this->actingAs($this->tree['users']['otherSl'])->post(route('counseling.overturn', $this->lor->id), ['reason' => 'x'])->assertNotFound();
    $this->actingAs($this->rxo)->post(route('counseling.overturn', 999999), ['reason' => 'x'])->assertNotFound();

    expect(fn () => app(OverturnCounselingRecordHandler::class)->handle($this->tree['users']['admin'], new OverturnCounselingRecord($this->lor->id, 'Appeal upheld.')))
        ->toThrow(CounselingForbiddenException::class, 'Only the Regimental Executive Officer can overturn a Letter of Reprimand.');
    expect($this->lor->fresh()->overturned_at)->toBeNull()
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses the RXO an LOR filed on themselves', function () {
    $own = CounselingRecord::factory()->lor()->filedHoursAgo()->create([
        'trooper_id' => Trooper::query()->where('cav_id', $this->rxo->cav_id)->sole()->id,
        'issued_by' => $this->rco->id,
        'issuer_unit_id' => $this->tree['units']['regiment']->id,
    ]);

    $this->actingAs($this->rxo)->post(route('counseling.overturn', $own->id), ['reason' => 'Mine.'])->assertForbidden();
    expect(fn () => app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($own->id, 'Mine.')))
        ->toThrow(CounselingForbiddenException::class, 'You cannot overturn an LOR filed on yourself.');
    expect($own->fresh()->overturned_at)->toBeNull();
});

it('refuses to overturn an LOR still in its window, a DCS, or one already overturned', function () {
    $open = CounselingRecord::factory()->lor()->filedHoursAgo(71)->create(['trooper_id' => $this->rifleman->id]);
    $dcs = CounselingRecord::factory()->filedHoursAgo()->create(['trooper_id' => $this->rifleman->id]);

    $overturn = fn (int $id) => app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($id, 'Appeal upheld.'));
    expect(fn () => $overturn($open->id))->toThrow(CounselingForbiddenException::class, 'This LOR is still in its 72-hour appeal window.')
        ->and(fn () => $overturn($dcs->id))->toThrow(CounselingForbiddenException::class, 'Only a Letter of Reprimand can be overturned.');

    $overturn($this->lor->id);
    expect(fn () => $overturn($this->lor->id))->toThrow(CounselingForbiddenException::class, 'This LOR is already overturned.')
        ->and(AuditLogEntry::query()->where('action', 'counseling.overturned')->count())->toBe(1);
});

it('keeps an overturned LOR on file for good: nobody corrects or deletes it, the Admin included', function () {
    app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($this->lor->id, 'Appeal upheld.'));

    $this->actingAs($this->tree['users']['admin'])->delete(route('counseling.destroy', $this->lor->id), ['audit_reason' => 'Clean up.'])->assertForbidden();
    expect(fn () => app(DeleteCounselingRecordHandler::class)->handle($this->tree['users']['admin'], new DeleteCounselingRecord($this->lor->id, 'Clean up.')))
        ->toThrow(CounselingForbiddenException::class, 'This LOR was overturned, so it can no longer be changed.');
    expect(CounselingRecord::query()->find($this->lor->id))->not->toBeNull();
});

it('lets the RXO overturn a departed trooper\'s LOR, which corrects the record', function () {
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();

    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertRedirect();
    expect($this->lor->fresh()->overturned_at)->not->toBeNull();
});

it('stops counting an overturned LOR toward the flags, the no favorable action period and the forecast', function () {
    $second = CounselingRecord::factory()->lor()->conduct()->filedHoursAgo()->create([
        'trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => now()->subDays(40)->toDateString(),
    ]);
    $this->lor->forceFill(['kind' => CounselingKind::Conduct, 'counseled_on' => now()->subDays(60)->toDateString()])->save();

    $before = freezeProps($this, $this->tree['users']['pl'], $this->rifleman);
    $flags = collect($before['records'])->keyBy('id')->map(fn (array $record) => collect($record['flags'])->pluck('key')->all());
    expect($flags[$second->id])->toBe(['lor-repeat', 'lor-removal']);
    $newLor = collect($before['forecast']['lor']['conduct'])->last()['flags'];
    expect(collect($newLor)->pluck('key')->all())->toContain('lor-article-15');

    app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($this->lor->id, 'Appeal upheld.'));

    $after = freezeProps($this, $this->tree['users']['pl'], $this->rifleman);
    $records = collect($after['records'])->keyBy('id');
    expect($records[$second->id]['flags'])->toBe([])
        ->and($records[$this->lor->id]['flags'])->toBe([])
        ->and($records[$this->lor->id]['overturned']['by'])->toBe('Vance.R');
    expect(collect(collect($after['forecast']['lor']['conduct'])->last()['flags'])->pluck('key')->all())->toBe(['lor-removal']);
});

it('drops an overturned LOR from the no favorable action banner', function () {
    $this->lor->forceFill(['counseled_on' => now()->subDays(4)->toDateString()])->save();
    expect(freezeProps($this, $this->tree['users']['pl'], $this->rifleman)['warnings'])->toHaveCount(1);

    app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($this->lor->id, 'Appeal upheld.'));

    expect(freezeProps($this, $this->tree['users']['pl'], $this->rifleman)['warnings'])->toBe([]);
});

it('offers Overturn to the RXO only, on frozen LORs only, and Correct on none of them', function () {
    $open = CounselingRecord::factory()->lor()->filedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
    ]);

    $rxo = collect(freezeProps($this, $this->rxo, $this->rifleman)['records'])->keyBy('id');
    expect($rxo[$this->lor->id])->toMatchArray(['frozen' => true, 'canOverturn' => true, 'canChange' => false, 'overturned' => null])
        ->and($rxo[$open->id])->toMatchArray(['frozen' => false, 'canOverturn' => false]);

    $admin = collect(freezeProps($this, $this->tree['users']['admin'], $this->rifleman)['records'])->keyBy('id');
    expect($admin[$this->lor->id])->toMatchArray(['frozen' => true, 'canOverturn' => false, 'canChange' => false])
        ->and($admin[$open->id])->toMatchArray(['frozen' => false, 'canChange' => true]);
});

it('lets an Admin acting as the RXO overturn, named beside them, and never on the Admin themselves', function () {
    $admin = $this->tree['users']['admin'];
    $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');

    expect(collect(freezeProps($this, $admin, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['canOverturn'])->toBeTrue();
    $this->actingAs($admin)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertRedirect();

    $record = $this->lor->fresh();
    expect($record->overturned_by)->toBe($this->rxo->id)
        ->and($record->overturned_by_admin)->toBe($admin->id);
    expect(AuditLogEntry::query()->where('action', 'counseling.overturned')->sole())
        ->actor_id->toBe($admin->id)
        ->on_behalf_of_id->toBe($this->rxo->id);
    expect(collect(freezeProps($this, $admin, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['overturned']['by'])
        ->toBe('Hale.S for Vance.R');
});

it('lets the RCO acting as the RXO overturn as them', function () {
    $this->actingAs($this->rco)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');

    $this->actingAs($this->rco)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertRedirect();
    expect($this->lor->fresh()->overturned_by_admin)->toBe($this->rco->id);
});

it('never lets the RXO overturn during a view-as preview, and offers nothing then', function () {
    app(ViewAs::class)->enter($this->rxo, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['platoon']->id));

    expect(collect(freezeProps($this, $this->rxo, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['canOverturn'])->toBeFalse();
    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertForbidden();
    expect($this->lor->fresh()->overturned_at)->toBeNull();
});

it('takes the overturn from the billet: a former RXO, or one no longer serving, cannot', function () {
    // No longer serving, they are signed out before anything is read.
    Trooper::query()->where('cav_id', $this->rxo->cav_id)->update(['status' => TrooperStatus::Inactive]);
    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertRedirect();
    expect($this->lor->fresh()->overturned_at)->toBeNull();

    Trooper::query()->where('cav_id', $this->rxo->cav_id)->update(['status' => TrooperStatus::Combat, 'billet' => 'Regimental Chief of Staff']);
    expect(fn () => app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($this->lor->id, 'Appeal upheld.')))
        ->toThrow(CounselingForbiddenException::class);
    expect($this->lor->fresh()->overturned_at)->toBeNull();
});
