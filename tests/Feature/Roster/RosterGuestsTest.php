<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\CrossSectionSighting;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The roster's "Guests at our practices" panel (JSAM evidence, decided 2026-10-23): its prop for a section, platoon or
// company in scope, none for a battalion, and the same read-only panel for a staff grant.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->host = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id)->id);
    $other = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->home = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $other->id)->id);
    $this->leader = User::factory()->commanding($this->host)->create();

    $this->guest = Trooper::factory()->create(['status' => TrooperStatus::Combat, 'username' => 'Visitor.V', 'rank_short' => 'SPC', 'rank' => 'Specialist']);
    $this->guest->forceFill(['primary_section_id' => $this->home->id])->save();
    $practices = app(SectionPracticeRepository::class);
    $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    foreach ([0, 1, 2] as $back) {
        $practice = $practices->findOrCreate($this->host->id, $week->subWeeks($back));
        CrossSectionSighting::query()->create([
            'trooper_id' => $this->guest->id, 'section_practice_id' => $practice->id, 'week_start' => $practice->week_start,
            'source' => $back === 0 ? 'manual' : 'capture',
            'seen_from' => $back === 0 ? null : $practice->week_start->addDays(5), 'seen_until' => $back === 0 ? null : $practice->week_start->addDays(5)->addHour(),
        ]);
        $own = $practices->findOrCreate($this->home->id, $week->subWeeks($back));
        Attendance::factory()->create(['trooper_id' => $this->guest->id, 'section_practice_id' => $own->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]);
    }
});

it('shows a section its guests with their visits, last week seen, own SP and the candidate badge', function () {
    $this->actingAs($this->leader)->get("/roster/{$this->host->id}")
        ->assertInertia(fn ($page) => $page
            ->where('guests.criteria', ['minVisits' => 3, 'minOwnSpPercent' => 75, 'windowWeeks' => 12])
            ->has('guests.rows', 1)
            ->where('guests.rows.0', [
                'trooperId' => $this->guest->id,
                'name' => 'Visitor.V',
                'displayName' => $this->guest->display_name,
                'rank' => 'SPC',
                'rankFull' => 'Specialist',
                'section' => ['id' => $this->home->id, 'designation' => '1/1/B/1-7'],
                'visits' => 3,
                'lastSeen' => '2026-10-03',
                'ownSp' => [3, 3],
                'candidate' => true,
            ]));
});

it('shows the same read-only panel to a staff grant', function () {
    $granted = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->host->id]);

    $this->actingAs($granted)->get("/roster/{$this->host->id}")
        ->assertInertia(fn ($page) => $page->has('guests.rows', 1)->where('guests.rows.0.candidate', true));
});

it('has no guests panel for a battalion', function () {
    $this->actingAs(User::factory()->admin()->create())->get("/roster/{$this->battalion->id}")
        ->assertInertia(fn ($page) => $page->where('guests', null));
});

it('answers an empty list for a section nobody visited', function () {
    $this->actingAs(User::factory()->commanding($this->home)->create())->get("/roster/{$this->home->id}")
        ->assertInertia(fn ($page) => $page->where('guests.rows', []));
});
