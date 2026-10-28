<?php

use App\Commands\Guests\GuestAttendanceRefusedException;
use App\Commands\Guests\RecordGuestAttendance;
use App\Commands\Guests\RecordGuestAttendanceHandler;
use App\Kpi\ChainNode;
use App\Kpi\KpiService;
use App\Kpi\ReportChain;
use App\Kpi\ReportSnapshot;
use App\Kpi\RollupKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// HQ staff and groups are counted in strength on the org page and roster, but are on no rollcall and are not measured
// (decided 2026-10-24): a non-leaf's live KPIs stay the sum of its sections', so they still equal the sum of the
// children's sent snapshots, and a group is never a report, a rollcall or a guest.

function skWeek(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
}

function skPlace(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->s1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->s2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa);

    $practices = app(SectionPracticeRepository::class);
    $attendance = app(AttendanceRepository::class);
    foreach ([[$this->s1, 2], [$this->s2, 1]] as [$section, $count]) {
        $practice = $practices->findOrCreate($section->id, skWeek());
        for ($i = 0; $i < $count; $i++) {
            $trooper = skPlace($section);
            $attendance->markPractice($practice, $trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
            $attendance->signRollcall($practice, $trooper->id, RollcallSignature::WillAttend);
        }
    }

    $this->pl = skPlace($this->platoon, ['username' => 'Brennan.J', 'billet' => 'Platoon Leader 1/A/1-7']);
    $this->co = skPlace($this->company, ['username' => 'Cole.D', 'billet' => 'Commander A/1-7']);
});

it('measures only the troopers placed in sections: a non-leaf\'s live KPIs equal the sum of its children\'s sent snapshots', function () {
    $kpis = app(KpiService::class);
    $reports = app(WeeklyReportRepository::class);
    $sender = User::factory()->create();

    foreach ([$this->s1, $this->s2] as $section) {
        $reports->send($section->id, skWeek(), (new ReportSnapshot($kpis->forUnit($section, skWeek()), null))->toArray(), 'notes', $sender->id, 'digest', null);
    }

    $live = $kpis->forUnit($this->platoon, skWeek());
    $rollup = app(RollupKpis::class)->forUnit($this->platoon, skWeek());

    expect($live->strength)->toBe(3)
        ->and($rollup->kpis)->toEqual($live)
        ->and($kpis->forUnit($this->company, skWeek())->strength)->toBe(3)
        ->and(app(TrooperRepository::class)->assignedWithin($this->platoon)->all())->not->toContain($this->pl->id);
});

it('leaves groups out of the reporting chain, so they never hold a roll-up up', function () {
    skPlace($this->battalion);
    skPlace($this->eloa);
    $chain = app(ReportChain::class)->build($this->regiment, skWeek());

    expect(array_map(fn (ChainNode $node) => $node->unit->id, $chain->children))->toBe([$this->battalion->id])
        ->and(app(UnitRepository::class)->lineChildren($this->regiment)->modelKeys())->toBe([$this->battalion->id]);
});

it('keeps HQ staff off every rollcall and refuses a group\'s rollcall and report', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get("/rollcall/{$this->s1->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('troopers', 2));
    $this->actingAs($admin)->get("/rollcall/{$this->platoon->id}")->assertNotFound();
    $this->actingAs($admin)->get("/rollcall/{$this->eloa->id}")->assertNotFound();
    $this->actingAs($admin)->get("/reports/{$this->eloa->id}/2026-10-03")->assertNotFound();
});

it('never offers or accepts HQ staff or a group member as a guest', function () {
    $onLeave = skPlace($this->eloa, ['username' => 'Brendel.E', 'status' => TrooperStatus::Eloa]);
    $admin = User::factory()->admin()->create();

    expect(app(TrooperRepository::class)->searchGuests('Bren', $this->s1->id, 10)->modelKeys())->toBe([])
        ->and(fn () => app(RecordGuestAttendanceHandler::class)->handle($admin, new RecordGuestAttendance($this->s1->id, skWeek(), $this->pl->id)))
        ->toThrow(GuestAttendanceRefusedException::class)
        ->and(fn () => app(RecordGuestAttendanceHandler::class)->handle($admin, new RecordGuestAttendance($this->s1->id, skWeek(), $onLeave->id)))
        ->toThrow(GuestAttendanceRefusedException::class);
});
