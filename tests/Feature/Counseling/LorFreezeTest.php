<?php

use App\Authorization\CounselingPermissions;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\Counseling\CounselingForbiddenException;
use App\Commands\Counseling\OverturnCounselingRecord;
use App\Commands\Counseling\OverturnCounselingRecordHandler;
use App\Commands\Counseling\WithdrawCounselingRecord;
use App\Commands\Counseling\WithdrawCounselingRecordHandler;
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

// S6, 2026-10-29, and #565: an LOR is frozen 72 hours after it is shared (or when its appeal is decided). Until then the
// issuer's chain may still withdraw it back to draft; after it nobody withdraws or deletes it, Admins included. Only the Regimental Executive Officer may then overturn it, with a reason: it stays on
// file, marked overturned, and stops counting toward the counseling rules. A DCS freezes the same way but has no
// overturn (DcsFreezeAndAppealTest).

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
    // One fixed clock for the whole file: the LOR below is filed 73 hours before it, and the tests that travel to
    // 2026-10-29 12:00 then sit past its 72 hours whatever the real time is.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->rxo = freezeHolder(500, 'Vance.R', 'Regimental Executive Officer', $this->tree['units']['regiment']);
    $this->rco = freezeHolder(501, 'Stone.A', 'Regimental Commanding Officer', $this->tree['units']['regiment']);
    $this->lor = CounselingRecord::factory()->lor()->sharedHoursAgo()->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->tree['users']['pl']->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => now()->subDays(4)->toDateString(),
        'reason' => 'Disrespect toward an NCO.',
    ]);
    Storage::disk('counseling')->put($this->lor->document_path, counselingPdfBytes('frozen-lor'));
    counselingFakeForum();
    $this->withdraw = fn (User $as, CounselingRecord $record) => $this->actingAs($as)->withSession(counselingPmSession($as))
        ->post(route('counseling.withdraw', $record->id), ['reason' => 'Wrong trooper.']);
});

it('freezes an LOR exactly 72 hours after it is shared, not a second before', function () {
    $shared = CarbonImmutable::now()->subDays(10)->setTime(18, 30);
    $make = fn () => CounselingRecord::factory()->lor()->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->tree['users']['pl']->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => $shared->toDateString(), 'created_at' => $shared, 'updated_at' => $shared, 'shared_at' => $shared,
    ]);
    $lor = $make();

    expect(CounselingPermissions::frozen($lor, $shared->addHours(72)->subSecond()))->toBeFalse()
        ->and(CounselingPermissions::frozen($lor, $shared->addHours(72)))->toBeTrue();

    $this->travelTo($shared->addHours(72)->subSecond());
    ($this->withdraw)($this->tree['users']['pl'], $lor)->assertSessionHasNoErrors();
    expect($lor->fresh()->isDraft())->toBeTrue();

    $late = $make();
    $this->travelTo($shared->addHours(72));
    ($this->withdraw)($this->tree['users']['pl'], $late)->assertForbidden();
    expect($late->fresh()->isShared())->toBeTrue();
});

it('judges the window from when the LOR was shared, never from the date it carries', function () {
    // Dated a month back but shared an hour ago: still in its window.
    $late = CounselingRecord::factory()->lor()->sharedHoursAgo(1)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->tree['users']['pl']->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => now()->subDays(30)->toDateString(),
    ]);

    ($this->withdraw)($this->tree['users']['pl'], $late)->assertSessionHasNoErrors();
    expect($late->fresh()->isDraft())->toBeTrue();
});

it('refuses every withdrawal of a frozen LOR, the flag Admin and the issuer included, with a message that says why', function () {
    foreach (['admin', 'pl', 'co'] as $who) {
        ($this->withdraw)($this->tree['users'][$who], $this->lor)->assertForbidden();
        $this->actingAs($this->tree['users'][$who])->delete(route('counseling.destroy', $this->lor->id))->assertForbidden();
    }

    $admin = $this->tree['users']['admin'];
    expect(fn () => app(WithdrawCounselingRecordHandler::class)->handle($admin, new WithdrawCounselingRecord($this->lor->id, 'Upheld appeal.', null)))
        ->toThrow(CounselingForbiddenException::class, 'This LOR is locked, so it can no longer be withdrawn. The Regimental Executive Officer can overturn it.');

    expect($this->lor->fresh()->isShared())->toBeTrue()
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

    ($this->withdraw)($chief, $this->lor)->assertForbidden();
    expect($this->lor->fresh()->isShared())->toBeTrue();
});

it('lets the RXO overturn a frozen LOR: kept on file, marked, audited with the reason', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00'));

    $this->actingAs($this->rxo)
        ->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld by the RXO after review.'])
        ->assertRedirect();

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
        ->and($props['records'][0]['can']['overturn'])->toBeFalse();
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
        ->toThrow(CounselingForbiddenException::class, 'Only the Regimental Executive Officer, signed in as themselves, can overturn an LOR.');
    expect($this->lor->fresh()->overturned_at)->toBeNull()
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses the RXO an LOR filed on themselves', function () {
    $own = CounselingRecord::factory()->lor()->sharedHoursAgo()->create([
        'trooper_id' => Trooper::query()->where('cav_id', $this->rxo->cav_id)->sole()->id,
        'issued_by' => $this->rco->id,
        'issuer_unit_id' => $this->tree['units']['regiment']->id,
    ]);

    $this->actingAs($this->rxo)->post(route('counseling.overturn', $own->id), ['reason' => 'Mine.'])->assertForbidden();
    expect(fn () => app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($own->id, 'Mine.')))
        ->toThrow(CounselingForbiddenException::class, 'You cannot overturn an LOR on yourself.');
    expect($own->fresh()->overturned_at)->toBeNull();
});

it('refuses to overturn an LOR still in its window, a DCS, or one already overturned', function () {
    $open = CounselingRecord::factory()->lor()->sharedHoursAgo(71)->create(['trooper_id' => $this->rifleman->id]);
    $dcs = CounselingRecord::factory()->sharedHoursAgo()->create(['trooper_id' => $this->rifleman->id]);

    $overturn = fn (int $id) => app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($id, 'Appeal upheld.'));
    expect(fn () => $overturn($open->id))->toThrow(CounselingForbiddenException::class, 'This LOR is not locked yet.')
        ->and(fn () => $overturn($dcs->id))->toThrow(CounselingForbiddenException::class, 'Only a Letter of Reprimand can be overturned.');

    $overturn($this->lor->id);
    expect(fn () => $overturn($this->lor->id))->toThrow(CounselingForbiddenException::class, 'This LOR is already overturned.')
        ->and(AuditLogEntry::query()->where('action', 'counseling.overturned')->count())->toBe(1);
});

it('keeps an overturned LOR on file for good: nobody withdraws or deletes it, the Admin included', function () {
    app(OverturnCounselingRecordHandler::class)->handle($this->rxo, new OverturnCounselingRecord($this->lor->id, 'Appeal upheld.'));

    ($this->withdraw)($this->tree['users']['admin'], $this->lor)->assertForbidden();
    $this->actingAs($this->tree['users']['admin'])->delete(route('counseling.destroy', $this->lor->id))->assertForbidden();
    expect(CounselingRecord::query()->find($this->lor->id))->not->toBeNull();
});

it('lets the RXO overturn a departed trooper\'s LOR, which corrects the record', function () {
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();

    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertRedirect();
    expect($this->lor->fresh()->overturned_at)->not->toBeNull();
});

it('stops counting an overturned LOR toward the flags, the no favorable action period and the forecast', function () {
    $second = CounselingRecord::factory()->lor()->conduct()->sharedHoursAgo()->create([
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

it('offers Overturn to the RXO only, on frozen LORs only, and Withdraw only on a record not yet locked', function () {
    $open = CounselingRecord::factory()->lor()->sharedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->tree['users']['pl']->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
    ]);

    $rxo = collect(freezeProps($this, $this->rxo, $this->rifleman)['records'])->keyBy('id');
    expect($rxo[$this->lor->id]['frozen'])->toBeTrue()
        ->and($rxo[$this->lor->id]['can']['overturn'])->toBeTrue()
        ->and($rxo[$this->lor->id]['overturned'])->toBeNull()
        ->and($rxo[$open->id]['frozen'])->toBeFalse()
        ->and($rxo[$open->id]['can']['overturn'])->toBeFalse();

    $admin = collect(freezeProps($this, $this->tree['users']['admin'], $this->rifleman)['records'])->keyBy('id');
    expect($admin[$this->lor->id]['can']['overturn'])->toBeFalse();

    expect($this->actingAs($this->tree['users']['pl'])->get(route('counseling.show', $open->id))->viewData('page')['props']['can']['withdraw'])->toBeTrue()
        ->and($this->actingAs($this->tree['users']['pl'])->get(route('counseling.show', $this->lor->id))->viewData('page')['props']['can']['withdraw'])->toBeFalse();
});

it('refuses an Admin acting as the RXO: only the RXO, signed in as themselves, overturns', function () {
    $admin = $this->tree['users']['admin'];
    $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');

    expect(collect(freezeProps($this, $admin, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['can']['overturn'])->toBeFalse();
    $this->actingAs($admin)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])
        ->assertForbidden()
        ->assertSee('Only the Regimental Executive Officer, signed in as themselves, can overturn an LOR.');

    expect($this->lor->fresh()->overturned_at)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.overturned')->count())->toBe(0);
});

it('refuses the RCO acting as the RXO under the command team rule', function () {
    $this->actingAs($this->rco)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');

    expect(collect(freezeProps($this, $this->rco, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['can']['overturn'])->toBeFalse();
    $this->actingAs($this->rco)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertForbidden();

    expect($this->lor->fresh()->overturned_at)->toBeNull();
});

it('lets the RXO overturn again once signed in as themselves, after an act as ends', function () {
    $this->actingAs($this->rco)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');
    $this->actingAs($this->rco)->delete('/act-as');

    expect(collect(freezeProps($this, $this->rxo, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['can']['overturn'])->toBeTrue();
    $this->actingAs($this->rxo)->post(route('counseling.overturn', $this->lor->id), ['reason' => 'Appeal upheld.'])->assertRedirect();

    expect($this->lor->fresh())
        ->overturned_by->toBe($this->rxo->id)
        ->overturned_by_admin->toBeNull();
});

it('never lets the RXO overturn during a view-as preview, and offers nothing then', function () {
    app(ViewAs::class)->enter($this->rxo, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['platoon']->id));

    expect(collect(freezeProps($this, $this->rxo, $this->rifleman)['records'])->firstWhere('id', $this->lor->id)['can']['overturn'])->toBeFalse();
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
