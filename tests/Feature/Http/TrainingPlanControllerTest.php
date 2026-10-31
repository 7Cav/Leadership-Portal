<?php

use App\Commands\TrainingPlans\ApproveTrainingPlanHandler;
use App\Commands\TrainingPlans\ReturnTrainingPlanHandler;
use App\Commands\TrainingPlans\SaveTrainingPlanHandler;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanStatus;
use App\Models\Trooper;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #380: the training plan page. A platoon's chain of command writes the month's plan, its company's reviews it, and an
// approved plan fills in the training subject on every section's report.

beforeEach(function () {
    // Wednesday of the Cav week that opened Saturday 2026-11-07, the first week of November.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));

    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->otherPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->otherCompany = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);

    $this->leader = tpcCommander($this->platoon->id, 601, 'Hale.J', 'SFC');
    $this->commander = tpcCommander($this->company->id, 602, 'Reyes.M', 'CPT');
    $this->sectionLeader = tpcCommander($this->section->id, 603, 'Vasquez.R', 'SGT');
    $this->outsider = tpcCommander($this->otherCompany->id, 604, 'Stone.K', 'CPT');
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function tpcCommander(int $unitId, int $positionId, string $username, string $rank): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unitId, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId, 'username' => $username, 'rank_short' => $rank]);

    return User::factory()->create(['cav_id' => $trooper->cav_id, 'username' => $username]);
}

function tpcUrl(int $unitId, string $month = '2026-11', string $suffix = ''): string
{
    return "/reports/{$unitId}/plans/{$month}{$suffix}";
}

it('shows the platoon leader the month\'s weeks to fill in, with no plan yet', function () {
    $this->actingAs($this->leader)->get("/reports/{$this->platoon->id}/plans")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/TrainingPlan')
            ->where('month.value', '2026-11')
            ->where('month.label', 'November 2026')
            ->where('weeks.0.start', '2026-11-07')
            ->where('weeks.0.reportUrl', "/reports/{$this->platoon->id}/2026-11-07")
            ->where('weeks.1.reportUrl', null)
            ->where('weeks.3.start', '2026-11-28')
            ->has('weeks', 4)
            ->where('plan', null)
            ->where('canEdit', true)
            ->where('canReview', false)
            ->where('company.callsign', 'A/1-7'));
});

it('takes a plan from the platoon leader and shows it pending, then the company commander approves it', function () {
    $this->actingAs($this->leader)
        ->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing', '2026-11-14' => 'Radio procedure'], 'enforced' => false])
        ->assertRedirect(tpcUrl($this->platoon->id))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->commander)->get(tpcUrl($this->platoon->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.status', 'pending')
            ->where('plan.writtenBy', 'SFC Hale.J')
            ->where('plan.revision', 1)
            ->where('weeks.0.subject', 'Room clearing')
            ->where('weeks.2.subject', null)
            ->where('canReview', true)
            ->where('canEdit', true));

    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1])
        ->assertRedirect(tpcUrl($this->platoon->id))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->leader)->get(tpcUrl($this->platoon->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.status', 'approved')
            ->where('plan.reviewedBy', 'CPT Reyes.M')
            ->where('canReview', false));

    expect(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe([SaveTrainingPlanHandler::SAVED, ApproveTrainingPlanHandler::APPROVED]);
});

it('fills in the section report with the approved subject, which the section may change when not enforced', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']]);
    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1]);

    $this->actingAs($this->sectionLeader)->get("/reports/{$this->section->id}/2026-11-07")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('topic.subject', 'Room clearing')
            ->where('topic.enforced', false)
            ->where('topic.setBy', 'SFC Hale.J'));

    $this->actingAs($this->sectionLeader)
        ->put("/reports/{$this->section->id}/2026-11-07", ['notes' => 'n', 'training_subject' => 'Land navigation'])
        ->assertSessionHasNoErrors();

    expect(WeeklyReport::query()->sole()->training_subject)->toBe('Land navigation');
});

it('locks the approved subject on the section report when the plan is enforced', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing'], 'enforced' => true]);
    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1]);

    $this->actingAs($this->sectionLeader)->get("/reports/{$this->section->id}/2026-11-07")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('topic.subject', 'Room clearing')->where('topic.enforced', true));

    $this->actingAs($this->sectionLeader)
        ->put("/reports/{$this->section->id}/2026-11-07", ['notes' => 'n', 'training_subject' => 'Land navigation'])
        ->assertSessionHasErrors('training_subject');
});

it('keeps the subject a section report was sent with when a plan is approved after', function () {
    $week = CarbonImmutable::parse('2026-11-07', 'UTC');
    app(WeeklyReportRepository::class)->send($this->section->id, $week, (new ReportSnapshot(new UnitKpis(1, new Ratio(1, 1), new Ratio(1, 1), 0, 0, 0), null))->toArray(), null, $this->sectionLeader->id, 'd', null, 'Radio procedure');

    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing'], 'enforced' => true]);
    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1]);

    $this->actingAs($this->sectionLeader)->get("/reports/{$this->section->id}/2026-11-07")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.status', 'sent')
            ->where('report.trainingSubject', 'Radio procedure')
            ->where('topic', null));
});

it('marks a week whose platoon report is sent as locked, and refuses a change to it', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->platoon->id, 'week_start' => CarbonImmutable::parse('2026-11-07', 'UTC')]);

    $this->actingAs($this->leader)->get(tpcUrl($this->platoon->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('weeks.0.locked', 'sent')->where('weeks.1.locked', null));

    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']])
        ->assertSessionHasErrors(['plan' => 'The report for the week ending 13NOV26 (07NOV26 to 13NOV26) is already sent, so its subject can no longer change.']);

    expect(TrainingPlan::query()->exists())->toBeFalse();
});

it('sends a plan back with a comment the platoon leader sees, and needs the comment', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']]);

    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/return'), ['revision' => 1, 'comment' => ''])
        ->assertSessionHasErrors('comment');

    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/return'), ['revision' => 1, 'comment' => 'Add a week on radios'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->leader)->get(tpcUrl($this->platoon->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.status', 'returned')
            ->where('plan.comment', 'Add a week on radios')
            ->where('plan.reviewedBy', 'CPT Reyes.M'));

    expect(AuditLogEntry::query()->latest('id')->first()->action)->toBe(ReturnTrainingPlanHandler::RETURNED)
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse();
});

it('shows a plan changed since the reviewer opened it as a form error', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']]);
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Radio procedure']]);

    $this->actingAs($this->commander)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1])
        ->assertSessionHasErrors('plan');

    expect(TrainingPlan::query()->sole()->status)->toBe(TrainingPlanStatus::Pending);
});

it('refuses the platoon leader an approval of their own plan', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']]);

    $this->actingAs($this->leader)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1])->assertForbidden();
    $this->actingAs($this->leader)->post(tpcUrl($this->platoon->id, suffix: '/return'), ['revision' => 1, 'comment' => 'x'])->assertForbidden();

    expect(TrainingPlan::query()->sole()->status)->toBe(TrainingPlanStatus::Pending);
});

it('lets a staff grant read a plan but never write or review it', function () {
    $staff = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->company->id]);
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']]);

    $this->actingAs($staff)->get(tpcUrl($this->platoon->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', false)->where('canReview', false)->where('plan.status', 'pending'));
    $this->actingAs($staff)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Radio procedure']])->assertForbidden();
    $this->actingAs($staff)->post(tpcUrl($this->platoon->id, suffix: '/approve'), ['revision' => 1])->assertForbidden();
});

it('answers 403 for a platoon outside the viewer\'s scope, on every route', function (string $method, string $suffix) {
    $this->actingAs($this->outsider)->call($method, tpcUrl($this->platoon->id, suffix: $suffix), ['subjects' => ['2026-11-07' => 'x'], 'revision' => 1, 'comment' => 'x'])
        ->assertForbidden();

    expect(TrainingPlan::query()->exists())->toBeFalse();
})->with([['GET', ''], ['PUT', ''], ['POST', '/approve'], ['POST', '/return']]);

it('answers 404 for a section, a malformed month, and a month outside the plan window', function (string $path) {
    $this->actingAs(User::factory()->admin()->create())->get(str_replace('{platoon}', (string) $this->platoon->id, str_replace('{section}', (string) $this->section->id, $path)))
        ->assertNotFound();
})->with(['/reports/{section}/plans', '/reports/{platoon}/plans/2026-13', '/reports/{platoon}/plans/2027-12', '/reports/{platoon}/plans/2025-01']);

it('offers no write or review during an Admin\'s view-as preview, where the writes are refused anyway', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->platoon->id]);

    $this->actingAs($admin)->get(tpcUrl($this->platoon->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', false)->where('canReview', false));
    $this->actingAs($admin)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']])->assertForbidden();

    expect(TrainingPlan::query()->exists())->toBeFalse();
});

it('lists every platoon of the company with where its plan stands', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing', '2026-11-14' => 'Radio procedure']]);

    $this->actingAs($this->commander)->get(tpcUrl($this->company->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/TrainingPlan')
            ->has('platoons', 2)
            ->where('platoons.0.callsign', '1/A/1-7')
            ->where('platoons.0.status', 'pending')
            ->where('platoons.0.weeksPlanned', 2)
            ->where('platoons.1.callsign', '2/A/1-7')
            ->where('platoons.1.status', 'none')
            ->missing('weeks'));
});

it('validates what a save sends', function (array $payload, string $error) {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), $payload)->assertSessionHasErrors($error);
})->with([
    'no subjects' => [[], 'subjects'],
    'a subject too long' => [['subjects' => ['2026-11-07' => str_repeat('x', 256)]], 'subjects.2026-11-07'],
    'a subject that is not text' => [['subjects' => ['2026-11-07' => ['x']]], 'subjects.2026-11-07'],
    'an empty plan' => [['subjects' => ['2026-11-07' => '']], 'plan'],
]);

it('links the audit entries about a plan to its page', function () {
    $this->actingAs($this->leader)->put(tpcUrl($this->platoon->id), ['subjects' => ['2026-11-07' => 'Room clearing']]);

    $this->actingAs(User::factory()->admin()->create())->get('/settings/audit-log')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.data.0.link.href', tpcUrl($this->platoon->id))
            ->where('entries.data.0.link.label', '1/A/1-7 training plan, 2026-11'));
});
