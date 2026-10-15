<?php

use App\Commands\WeeklyReports\SetPlatoonTrainingTopicHandler;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// The report screen's training topic: a platoon leader sets it for their sections on the platoon's
// report, each section's draft shows it (locked when enforced), and an empty section field is
// offered last week's subject.

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = '2026-10-03';
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->otherPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);

    $this->platoonLeader = rtCommander($this->platoon->id, 901, 'Hale.J', 'SFC');
    $this->sectionLeader = rtCommander($this->section->id, 902, 'Vasquez.R', 'SGT');
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rtCommander(int $unitId, int $positionId, string $username = 'Leader.X', string $rank = 'SGT'): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unitId, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId, 'username' => $username, 'rank_short' => $rank]);

    return User::factory()->create(['cav_id' => $trooper->cav_id, 'username' => $username]);
}

function rtUrl(int $unitId, string $week, string $suffix = ''): string
{
    return "/reports/{$unitId}/{$week}{$suffix}";
}

it('lets the platoon leader set an enforced topic from the platoon\'s report, and shows it there', function () {
    $this->actingAs($this->platoonLeader)
        ->put(rtUrl($this->platoon->id, $this->week, '/topic'), ['subject' => 'Room clearing', 'enforced' => true])
        ->assertRedirect(rtUrl($this->platoon->id, $this->week))
        ->assertSessionHasNoErrors();

    expect(PlatoonTrainingTopic::query()->sole())->subject->toBe('Room clearing')->enforced->toBeTrue()
        ->and(AuditLogEntry::query()->sole()->action)->toBe(SetPlatoonTrainingTopicHandler::SET);

    $this->actingAs($this->platoonLeader)->get(rtUrl($this->platoon->id, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSetTopic', true)
            ->where('topic.subject', 'Room clearing')
            ->where('topic.enforced', true)
            ->where('topic.platoon.callsign', '1/A/1-7')
            ->where('topic.setBy', 'SFC Hale.J'));
});

it('clears the topic', function () {
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, $this->weekStart, 'Room clearing', false, $this->platoonLeader->id);

    $this->actingAs($this->platoonLeader)->delete(rtUrl($this->platoon->id, $this->week, '/topic'))
        ->assertRedirect(rtUrl($this->platoon->id, $this->week));

    expect(PlatoonTrainingTopic::query()->exists())->toBeFalse();
});

it('refuses the topic to anyone outside the platoon\'s chain of command', function (Closure $actor) {
    $this->actingAs($actor($this))
        ->put(rtUrl($this->platoon->id, $this->week, '/topic'), ['subject' => 'Room clearing', 'enforced' => true])
        ->assertForbidden();

    expect(PlatoonTrainingTopic::query()->exists())->toBeFalse();
})->with([
    'its section leader' => [fn ($test) => $test->sectionLeader],
    'another platoon\'s leader' => [fn ($test) => rtCommander($test->otherPlatoon->id, 903)],
    'a staff grant holder on the platoon' => [function ($test) {
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $test->platoon->id]);

        return $staff;
    }],
]);

it('does not offer the topic control to a staff grant holder who can read the platoon', function () {
    $staff = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->platoon->id]);

    $this->actingAs($staff)->get(rtUrl($this->platoon->id, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canSetTopic', false)->where('topic', null));
});

it('says why once the platoon\'s report for the week is sent', function () {
    app(WeeklyReportRepository::class)->send($this->platoon->id, $this->weekStart, [], null, $this->platoonLeader->id, 'd', null);

    $this->actingAs($this->platoonLeader)
        ->put(rtUrl($this->platoon->id, $this->week, '/topic'), ['subject' => 'Room clearing'])
        ->assertSessionHasErrors('topic');

    expect(PlatoonTrainingTopic::query()->exists())->toBeFalse();
});

it('shows a section the enforced topic it must use, and refuses a draft that changes it', function () {
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, $this->weekStart, 'Room clearing', true, $this->platoonLeader->id);

    $this->actingAs($this->sectionLeader)->get(rtUrl($this->section->id, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSetTopic', false)
            ->where('topic.subject', 'Room clearing')
            ->where('topic.enforced', true)
            ->where('topic.platoon.callsign', '1/A/1-7'));

    $this->actingAs($this->sectionLeader)
        ->put(rtUrl($this->section->id, $this->week), ['notes' => 'n', 'training_subject' => 'Something else'])
        ->assertSessionHasErrors('training_subject');
    expect(WeeklyReport::query()->exists())->toBeFalse();

    $this->actingAs($this->sectionLeader)
        ->post(rtUrl($this->section->id, $this->week, '/send'), ['reason' => 'Weekly report', 'training_subject' => 'Room clearing'])
        ->assertSessionHasNoErrors();
    expect(WeeklyReport::query()->sole()->training_subject)->toBe('Room clearing');
});

it('suggests the subject of the section\'s last sent report on its draft', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart->subWeeks(2), (new ReportSnapshot(new UnitKpis(1, new Ratio(1, 1), new Ratio(1, 1), 0, 0, 0), null))->toArray(), null, $this->sectionLeader->id, 'd', null, 'Radio procedure');

    $this->actingAs($this->sectionLeader)->get(rtUrl($this->section->id, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('suggestedSubject', 'Radio procedure')
            ->where('report.trainingSubject', null)
            ->where('topic', null));

    // A suggestion is only shown; nothing is saved until the leader takes it.
    expect(WeeklyReport::query()->where('week_start', $this->weekStart)->exists())->toBeFalse();
});
