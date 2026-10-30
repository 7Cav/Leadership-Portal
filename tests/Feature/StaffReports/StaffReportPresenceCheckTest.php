<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The game server check frozen on a section practice report when it is sent: a conflict when a picked practice was
// captured and no picked capture saw them, never when nothing was captured. The report is sent either way, and the
// check reads the same to the staff member, the overview and the unit report.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->psg = $this->tree['troopers']['psg'];
    $this->host = $this->tree['units']['host'];
    $this->other = $this->tree['units']['other'];
    $this->pageUrl = route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']);
});

function presencePractice(int $sectionId): SectionPractice
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($sectionId, CarbonImmutable::parse('2026-10-03', 'UTC'));
    $practice->forceFill(['captured_at' => CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'), 'capture_source' => 'crcon'])->save();

    return $practice;
}

function presenceSaw(SectionPractice $practice, int $trooperId): void
{
    PracticeCapturePlayer::query()->create(['section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => 'Brandt', 'platform_id' => '76561190000000020', 'trooper_id' => $trooperId]);
}

function sendPresenceReport(User $user, Trooper $trooper, bool $attended, array $sectionIds, ?string $type = null, array $more = []): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($user, new SendStaffReport(
        $trooper->id, CarbonImmutable::parse('2026-10-03', 'UTC'), $attended, $sectionIds, $type,
        $more['ftx_name'] ?? null, $more['ftx_date'] ?? null,
    ));
}

it('flags a report whose captured practice did not see them, names CRCON for an HLL unit, and still sends it', function () {
    $this->tree['units']['company']->update(['game_id' => game('hll')->id]);
    presencePractice($this->host->id);

    $report = sendPresenceReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect($report->exists)->toBeTrue()
        ->and($report->presence_check)->toBe(StaffReport::PRESENCE_CONFLICT)
        ->and($report->presence_source)->toBe('CRCON');

    $presence = ['check' => 'conflict', 'source' => 'CRCON'];
    $this->actingAs($this->tree['users']['psg'])->get($this->pageUrl)->assertInertia(fn (AssertableInertia $page) => $page->where('report.presence', $presence));
    $groups = $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertOk()->viewData('page')['props']['groups'];
    expect(collect($groups)->flatMap(fn (array $group) => $group['rows'])->firstWhere('trooperId', $this->psg->id)['presence'])->toBe($presence);
    $this->actingAs($this->tree['users']['co'])->get(route('reports.show', ['unit' => $this->tree['units']['platoon']->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('staffReports', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['presence'] === $presence));
});

it('confirms when any picked capture saw them, even if another picked capture did not', function () {
    presenceSaw(presencePractice($this->host->id), $this->psg->id);
    presencePractice($this->other->id);

    $report = sendPresenceReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id, $this->other->id]);

    expect($report->presence_check)->toBe(StaffReport::PRESENCE_CONFIRMED);
});

it('never flags without capture data: no data is its own note', function () {
    $report = sendPresenceReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect($report->presence_check)->toBe(StaffReport::PRESENCE_NO_DATA)
        ->and($report->presence_source)->toBe('game server');
});

it('checks nothing for No', function () {
    presencePractice($this->host->id);
    expect(sendPresenceReport($this->tree['users']['psg'], $this->psg, false, [])->presence_check)->toBeNull();
});

it('stays as sent when the capture changes later', function () {
    $practice = presencePractice($this->host->id);
    sendPresenceReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);
    presenceSaw($practice, $this->psg->id);

    expect(StaffReport::query()->where('trooper_id', $this->psg->id)->value('presence_check'))->toBe(StaffReport::PRESENCE_CONFLICT);
});
