<?php

use App\Authorization\ScopeResolver;
use App\Commands\PortalRoleGrants\ExpireEndedStaffGrants;
use App\Commands\PortalRoleGrants\GrantExpiry;
use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\RenewStaffGrant;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

// A staff grant can end on a date (revoked for good) or need renewing by one (lapsed until renewed). Either way it
// gives no scope from the moment the date's last second has passed, before any scheduled run.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
    $this->company = app(UnitRepository::class)->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->serving()->create();
});

function seesCompany($test): bool
{
    return app(ScopeResolver::class)->resolve($test->staff)->contains($test->company->id);
}

function bulkSeesCompany($test): bool
{
    return app(ScopeResolver::class)->realScopes(collect([$test->staff]))->get($test->staff->id, collect())->contains($test->company->id);
}

function giveGrant($test, string $mode, string $endsOn): PortalRoleGrant
{
    return app(GrantStaffScope::class)->handle($test->admin, $test->staff, $test->company, 'S1 cover', GrantExpiry::fromInput($mode, $endsOn, now()));
}

it('stores the end of the chosen Zulu day and the renewal period', function () {
    $grant = giveGrant($this, 'renew', '2026-12-01')->fresh();

    expect($grant->expires_at->toIso8601String())->toBe('2026-12-01T23:59:59+00:00')
        ->and($grant->renew_every_days)->toBe(30)
        ->and($grant->expiry_set_by)->toBe($this->admin->id);
});

it('gives scope until the last second of the end day and none from the next', function (string $mode) {
    giveGrant($this, $mode, '2026-11-08');

    Carbon::setTestNow('2026-11-08 23:59:59');
    expect(seesCompany($this))->toBeTrue()->and(bulkSeesCompany($this))->toBeTrue();

    Carbon::setTestNow('2026-11-09 00:00:00');
    expect(seesCompany($this))->toBeFalse()->and(bulkSeesCompany($this))->toBeFalse();
})->with(['ends', 'renew']);

it('keeps a grant without a date working as before', function () {
    app(GrantStaffScope::class)->handle($this->admin, $this->staff, $this->company, 'S1 cover');

    Carbon::setTestNow('2030-01-01 00:00:00');
    expect(seesCompany($this))->toBeTrue();
});

it('renews a lapsed grant by its period from today, audited, restoring scope at once', function () {
    $grant = giveGrant($this, 'renew', '2026-11-08');
    Carbon::setTestNow('2026-11-10 09:00:00');
    expect(seesCompany($this))->toBeFalse();

    app(RenewStaffGrant::class)->handle($this->admin, $grant->fresh(), null, 'still on staff', now());

    expect($grant->fresh()->expires_at->toDateString())->toBe('2026-11-17')
        ->and(seesCompany($this))->toBeTrue();
    $entry = AuditLogEntry::latest('id')->first();
    expect($entry->action)->toBe('portal_role_grant.renewed')->and($entry->reason)->toBe('still on staff');
});

it('renews a grant not yet lapsed from its own date, or to a new date', function () {
    $grant = giveGrant($this, 'renew', '2026-11-08');

    app(RenewStaffGrant::class)->handle($this->admin, $grant->fresh(), null, 'early', now());
    expect($grant->fresh()->expires_at->toDateString())->toBe('2026-11-15');

    app(RenewStaffGrant::class)->handle($this->admin, $grant->fresh(), '2026-12-31', 'to year end', now());
    expect($grant->fresh()->expires_at->toDateString())->toBe('2026-12-31')
        ->and($grant->fresh()->renew_every_days)->toBe(60);
});

it('revokes ended grants on schedule with an audited system entry, keeping renewable ones', function () {
    $ending = giveGrant($this, 'ends', '2026-11-08');
    $other = User::factory()->serving()->create();
    $renewing = app(GrantStaffScope::class)->handle($this->admin, $other, $this->company, 'cover', GrantExpiry::fromInput('renew', '2026-11-08', now()));

    Carbon::setTestNow('2026-11-08 23:59:59');
    expect(app(ExpireEndedStaffGrants::class)->handle(now()))->toBe(0);

    Carbon::setTestNow('2026-11-09 00:05:00');
    $this->artisan('portal:expire-staff-grants')->expectsOutput('Revoked 1 staff grant past their end date.')->assertSuccessful();

    expect(PortalRoleGrant::find($ending->id))->toBeNull()
        ->and(PortalRoleGrant::find($renewing->id))->not->toBeNull();
    $entry = AuditLogEntry::latest('id')->first();
    expect($entry->action)->toBe('portal_role_grant.expired')
        ->and($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->target_id)->toBe((string) $ending->id)
        ->and($entry->reason)->toBe("Ended automatically: the grant's end date, 08NOV26, passed.");
});

it('schedules the expiry every five minutes', function () {
    $this->artisan('list');
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:expire-staff-grants'));

    expect($event)->not->toBeNull()->and($event->getExpression())->toBe('*/5 * * * *');
});

it('lets an Admin give, edit and renew end dates through the page, each audited', function () {
    $this->actingAs($this->admin)->post('/settings/staff-grants', [
        'user_id' => $this->staff->id, 'unit_id' => $this->company->id, 'mode' => 'ends', 'ends_on' => '2026-11-30', 'reason' => 'cover',
    ])->assertRedirect('/settings/staff-grants');
    $grant = PortalRoleGrant::sole();
    expect($grant->expires_at->toDateString())->toBe('2026-11-30')->and($grant->renew_every_days)->toBeNull();

    $this->actingAs($this->admin)->patch("/settings/staff-grants/{$grant->id}", ['mode' => 'renew', 'ends_on' => '2026-12-01', 'reason' => 'renewable'])
        ->assertRedirect('/settings/staff-grants');
    expect($grant->fresh()->renew_every_days)->toBe(30);

    $this->actingAs($this->admin)->post("/settings/staff-grants/{$grant->id}/renew", ['reason' => 'again'])->assertRedirect('/settings/staff-grants');
    expect($grant->fresh()->expires_at->toDateString())->toBe('2026-12-31');

    $this->actingAs($this->admin)->patch("/settings/staff-grants/{$grant->id}", ['mode' => 'none', 'reason' => 'permanent'])->assertRedirect();
    expect($grant->fresh()->expires_at)->toBeNull();

    expect(AuditLogEntry::orderBy('id')->pluck('action')->all())->toBe([
        'portal_role_grant.granted', 'portal_role_grant.expiry_set', 'portal_role_grant.renewed', 'portal_role_grant.expiry_set',
    ]);
});

it('sends each grant end date and whether it has passed', function () {
    giveGrant($this, 'renew', '2026-11-08');
    Carbon::setTestNow('2026-11-09 00:00:00');

    $this->actingAs($this->admin)->get('/settings/staff-grants')->assertInertia(fn ($page) => $page
        ->where('grants.0.mode', 'renew')
        ->where('grants.0.expires_at', '2026-11-08T23:59:59+00:00')
        ->where('grants.0.renew_every_days', 7)
        ->where('grants.0.past_end', true));
});

it('refuses a past date, a missing date and a renewal of a grant that ends for good', function () {
    $this->actingAs($this->admin)->post('/settings/staff-grants', [
        'user_id' => $this->staff->id, 'unit_id' => $this->company->id, 'mode' => 'ends', 'ends_on' => '2026-10-31', 'reason' => 'x',
    ])->assertSessionHasErrors(['ends_on' => 'Choose today or a later date.']);
    $this->actingAs($this->admin)->post('/settings/staff-grants', [
        'user_id' => $this->staff->id, 'unit_id' => $this->company->id, 'mode' => 'renew', 'reason' => 'x',
    ])->assertSessionHasErrors(['ends_on' => 'Choose the date.']);

    $grant = giveGrant($this, 'ends', '2026-11-30');
    $this->actingAs($this->admin)->post("/settings/staff-grants/{$grant->id}/renew", ['reason' => 'x'])
        ->assertSessionHasErrors(['ends_on' => 'This grant has no renewal date. Edit its end date instead.']);
});

it('refuses a non-Admin editing or renewing, before looking the grant up', function () {
    $grant = giveGrant($this, 'renew', '2026-11-30');

    $this->actingAs($this->staff)->patch("/settings/staff-grants/{$grant->id}", ['mode' => 'none', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($this->staff)->post("/settings/staff-grants/{$grant->id}/renew", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->staff)->post('/settings/staff-grants/99999/renew', ['reason' => 'x'])->assertForbidden();
    expect($grant->fresh()->expires_at->toDateString())->toBe('2026-11-30');
});
