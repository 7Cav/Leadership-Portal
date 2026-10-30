<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The warning a reader sees when the game server's practice capture disagrees with a staff report: a captured practice
// they reported did not see them, or a captured practice they did not report did. Never for a practice with no
// capture, an FTX or an office hour, and never to the staff member about their own report.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03', 'UTC');
    $this->psg = $this->tree['troopers']['psg'];
    $this->host = $this->tree['units']['host'];
    $this->other = $this->tree['units']['other'];
    $this->co = $this->tree['users']['co'];
    $this->pageUrl = route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']);
});

/** The section's practice for the fixture's week, stamped as captured. */
function capturedPractice(int $sectionId): SectionPractice
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($sectionId, CarbonImmutable::parse('2026-10-03', 'UTC'));
    $practice->forceFill(['captured_at' => CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'), 'capture_source' => 'crcon'])->save();

    return $practice;
}

/** The capture saw `$trooperId` (or, with null, an unlinked player on `$platformId`) at the practice. */
function capturedSaw(SectionPractice $practice, ?int $trooperId, string $platformId = '76561190000000020'): void
{
    PracticeCapturePlayer::query()->create(['section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => 'Brandt', 'platform_id' => $platformId, 'trooper_id' => $trooperId]);
}

function sendDiscrepancyReport(User $user, Trooper $trooper, bool $attended, array $sectionIds, ?string $type = null, array $more = []): void
{
    app(SendStaffReportHandler::class)->handle($user, new SendStaffReport(
        $trooper->id, CarbonImmutable::parse('2026-10-03', 'UTC'), $attended, $sectionIds, $type,
        $more['ftx_name'] ?? null, $more['ftx_date'] ?? null, null, null, $more['office_hour_date'] ?? null, $more['office_hour_note'] ?? null,
    ));
}

/** The PSG's row on the CO's overview. */
function psgOverviewRow(User $viewer, int $trooperId): array
{
    $groups = test()->actingAs($viewer)->get('/staff-reports')->assertOk()->viewData('page')['props']['groups'];

    return collect($groups)->flatMap(fn (array $group) => $group['rows'])->firstWhere('trooperId', $trooperId);
}

it('warns when they reported a captured practice that did not see them', function () {
    capturedPractice($this->host->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy'])->toBe(['notSeen' => ['1/1/A/1-7'], 'seenElsewhere' => []]);
    $this->actingAs($this->co)->get($this->pageUrl)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('discrepancy', ['notSeen' => ['1/1/A/1-7'], 'seenElsewhere' => []]));
});

it('says nothing when the capture of the reported practice saw them', function () {
    capturedSaw(capturedPractice($this->host->id), $this->psg->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy'])->toBeNull();
});

it('warns when a captured practice saw them and they answered No, or reported another section', function () {
    capturedSaw(capturedPractice($this->other->id), $this->psg->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, false, []);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy'])->toBe(['notSeen' => [], 'seenElsewhere' => ['2/1/A/1-7']]);
});

it('warns about another section seen, beside one reported that has no capture', function () {
    capturedSaw(capturedPractice($this->other->id), $this->psg->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy'])->toBe(['notSeen' => [], 'seenElsewhere' => ['2/1/A/1-7']]);
});

it('matches the capture through a game identity linked since the capture ran', function () {
    capturedSaw(capturedPractice($this->other->id), null, '76561190000000077');
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->psg->id, 'game_id' => game('hll')->id, 'platform_id' => '76561190000000077']);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, false, []);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy']['seenElsewhere'])->toBe(['2/1/A/1-7']);
});

it('stays neutral when the reported practice has no capture', function () {
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy'])->toBeNull();
});

it('never compares an FTX or an office hour, though a capture saw them elsewhere', function () {
    capturedSaw(capturedPractice($this->other->id), $this->psg->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [], 'ftx', ['ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-10-05']);

    expect(psgOverviewRow($this->co, $this->psg->id)['discrepancy'])->toBeNull();

    // A battalion HQ staff member's office hour.
    $bnStaff = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['battalion']->id, 'username' => 'Stone.A']);
    $bnUser = User::factory()->create(['cav_id' => $bnStaff->cav_id, 'username' => 'Stone.A']);
    capturedSaw(capturedPractice($this->host->id), $bnStaff->id, '76561190000000099');
    sendDiscrepancyReport($bnUser, $bnStaff, true, [], 'office_hour', ['office_hour_date' => '2026-10-06', 'office_hour_note' => 'Q and A']);

    expect(psgOverviewRow($this->tree['users']['admin'], $bnStaff->id)['discrepancy'])->toBeNull();
});

it('shows the warning to readers only: never to the staff member on their own page, row or panel', function () {
    capturedPractice($this->host->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    $this->actingAs($this->tree['users']['psg'])->get($this->pageUrl)->assertInertia(fn (AssertableInertia $page) => $page->where('discrepancy', null));

    // The CO's own report and own row carry none, though theirs differs too.
    $co = Trooper::query()->where('cav_id', $this->co->cav_id)->sole();
    capturedSaw(capturedPractice($this->other->id), $co->id, '76561190000000055');
    sendDiscrepancyReport($this->co, $co, false, []);
    $this->actingAs($this->co)->get('/staff-reports')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('own.trooperId', $co->id)
        ->missing('own.discrepancy'));
    $this->actingAs($this->co)->get(route('staff-report.show', ['trooper' => $co->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('discrepancy', null));

    // The HQ panel on the platoon's report: the CO reads the PSG's warning; the PL reads only their own row, with none.
    $reportUrl = route('reports.show', ['unit' => $this->tree['units']['platoon']->id, 'week' => '2026-10-03']);
    $this->actingAs($this->co)->get($reportUrl)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('staffReports', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['discrepancy'] === ['notSeen' => ['1/1/A/1-7'], 'seenElsewhere' => []]));
});

it('reads the overview in the same number of queries for one staff report and several', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($this->tree['users']['admin'])->get('/staff-reports')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    capturedPractice($this->host->id);
    capturedSaw(capturedPractice($this->other->id), $this->psg->id);
    sendDiscrepancyReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);
    $one = $count();

    // Three more platoons, each with a staff member who sent a report against a captured practice.
    $units = app(UnitRepository::class);
    foreach (['2', '3', '4'] as $n) {
        $platoon = $units->create(UnitType::Platoon, "Platoon {$n}", "{$n}/A/1-7", $this->tree['units']['company']->id);
        $section = $units->create(UnitType::Section, '1st Section', "1/{$n}/A/1-7", $platoon->id);
        Trooper::factory()->create(['primary_section_id' => $section->id]);
        $staff = Trooper::factory()->create(['primary_section_id' => $platoon->id]);
        $user = User::factory()->create(['cav_id' => $staff->cav_id]);
        capturedSaw(capturedPractice($section->id), $staff->id, "7656119000000{$n}000");
        sendDiscrepancyReport($user, $staff, false, []);
    }

    expect($count())->toBe($one);
});
