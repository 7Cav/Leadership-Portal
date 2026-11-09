<?php

use App\Commands\Units\SetUnitOversightHandler;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitOversightRepository;
use App\Repositories\UnitRepository;
use App\Sync\EndDepartedOversight;

beforeEach(function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $this->other = $units->create(UnitType::Battalion, '3rd Battalion', '3-7', $regiment->id);
    $this->admin = User::factory()->admin()->create();
    $this->leaving = Trooper::factory()->create();
    $this->staying = Trooper::factory()->create();
    app(UnitOversightRepository::class)->set($this->battalion->id, $this->leaving->id, $this->admin->id, null, null);
    app(UnitOversightRepository::class)->set($this->other->id, $this->staying->id, $this->admin->id, null, null);
});

it('removes an overseer who left active duty, as a System entry with the reason', function (TrooperStatus $status) {
    $this->leaving->forceFill(['status' => $status])->save();

    expect(app(EndDepartedOversight::class)->handle())->toBe(1)
        ->and(app(UnitOversightRepository::class)->forUnit($this->battalion->id))->toBeNull()
        ->and(app(UnitOversightRepository::class)->forUnit($this->other->id))->not->toBeNull();

    $entry = AuditLogEntry::query()->where('action', SetUnitOversightHandler::CLEARED)->sole();
    expect($entry->actor_id)->toBeNull()
        ->and($entry->reason)->toBe(EndDepartedOversight::REASON)
        ->and($entry->target_id)->toBe((string) $this->battalion->id);

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertOk()->assertSee('System');
})->with([TrooperStatus::Eloa, TrooperStatus::Reserve, TrooperStatus::Inactive]);

it('removes nothing while every overseer is on active duty', function () {
    expect(app(EndDepartedOversight::class)->handle())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});
