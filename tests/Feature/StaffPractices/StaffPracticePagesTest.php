<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Kpi\KpiService;
use App\Kpi\PracticeGuests;
use App\Models\Attendance;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\PracticeCapturePlayer;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Rollcall\RollcallGuests;
use Carbon\CarbonImmutable;

require_once __DIR__.'/fixture.php';

// #448: a staff member's attended practices show on their milpac (where they are entered) and the roster sheet, are
// taken from the game server capture where it saw them, and never reach the host section's rollcall, numbers, reports
// or anyone's makeup.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
});

function enterStaffPractice(User $actor, Trooper $trooper, int $sectionId): CrossSectionSighting
{
    return app(RecordStaffPracticeHandler::class)->handle($actor, new RecordStaffPractice($trooper->id, $sectionId, staffPracticeWeek()));
}

function staffPracticeProps(object $test, User $viewer, Trooper $trooper): ?array
{
    return $test->flushHeaders()->actingAs($viewer)->get(route('troopers.show', $trooper->id))->assertOk()->viewData('page')['props']['staffPractices'];
}

/** Run the capture of `$sectionId`'s practice this week, with the game server answering these players. */
function captureStaffPractice(int $sectionId, array $players): void
{
    $game = game('hll');
    $practice = app(SectionPracticeRepository::class)->findOrCreate($sectionId, staffPracticeWeek());
    $source = new CaptureSource('crcon', 'crcon-server-1', fn ($start, $end) => new CrconPresence($players, $start, $end, CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC')->toDateTimeImmutable()));
    app()->instance(CaptureSources::class, new CaptureSources([$game->interface->value => [$source]]));

    app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice(
        $practice->id,
        $game->id,
        CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC')->toDateTimeImmutable(),
        CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC')->toDateTimeImmutable(),
        'system',
    ));
}

it('records against a staff member the practice the capture saw them at, credits nobody, and shows it on their milpac', function () {
    $psg = $this->tree['troopers']['psg'];
    TrooperGameIdentity::factory()->create(['trooper_id' => $psg->id, 'game_id' => game('hll')->id, 'platform_id' => '76561190000000020']);

    captureStaffPractice($this->tree['units']['host']->id, [new CrconPlayer('Brandt', '76561190000000020')]);

    expect(PracticeCapturePlayer::query()->sole()->trooper_id)->toBe($psg->id)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(CrossSectionSighting::query()->count())->toBe(0);

    $entries = staffPracticeProps($this, $this->tree['users']['pl'], $psg)['entries'];
    expect($entries)->toBe([[
        'week' => '2026-10-03',
        'sectionId' => $this->tree['units']['host']->id,
        'section' => '1/1/A/1-7',
        'source' => 'capture',
        'enteredBy' => null,
        'selfReported' => false,
        'removable' => false,
    ]]);
});

it('shows the entry instead of the capture when a practice has both', function () {
    $psg = $this->tree['troopers']['psg'];
    TrooperGameIdentity::factory()->create(['trooper_id' => $psg->id, 'game_id' => game('hll')->id, 'platform_id' => '76561190000000020']);
    captureStaffPractice($this->tree['units']['host']->id, [new CrconPlayer('Brandt', '76561190000000020')]);
    enterStaffPractice($this->tree['users']['psg'], $psg, $this->tree['units']['host']->id);

    $entries = staffPracticeProps($this, $this->tree['users']['pl'], $psg)['entries'];
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['source'])->toBe('entered')
        ->and($entries[0]['enteredBy'])->toBe('Brandt.L')
        ->and($entries[0]['selfReported'])->toBeTrue();
});

it('lists the entries on the milpac with who entered them, and offers the form to the staff leader', function () {
    $psg = $this->tree['troopers']['psg'];
    enterStaffPractice($this->tree['users']['psg'], $psg, $this->tree['units']['host']->id);
    enterStaffPractice($this->tree['users']['pl'], $psg, $this->tree['units']['other']->id);

    $props = staffPracticeProps($this, $this->tree['users']['pl'], $psg);

    expect(collect($props['entries'])->map(fn ($e) => [$e['section'], $e['enteredBy'], $e['selfReported'], $e['removable']])->all())->toBe([
        ['1/1/A/1-7', 'Brandt.L', true, true],
        ['2/1/A/1-7', 'Mercer.J', false, true],
    ])
        ->and($props['canRecord'])->toBeTrue()
        // 2/1/A/1-7 has nobody in it, so the form does not offer it (#442).
        ->and(collect($props['sections'])->pluck('label')->all())->toBe(['1/1/A/1-7'])
        ->and($props['weeks'][0])->toBe('2026-10-03')
        ->and($props['weeks'])->toHaveCount(12);
});

it('offers only the sections with troopers in the form, an empty one again once someone is placed in it', function () {
    $psg = $this->tree['troopers']['psg'];
    $labels = fn () => collect(staffPracticeProps($this, $this->tree['users']['pl'], $psg)['sections'])->pluck('label')->all();

    expect($labels())->toBe(['1/1/A/1-7']);

    Trooper::factory()->create(['primary_section_id' => $this->tree['units']['other']->id]);

    expect($labels())->toBe(['1/1/A/1-7', '2/1/A/1-7']);
});

it('shows a staff department member their own panel with the form, and a grant holder the list only', function () {
    $member = $this->tree['troopers']['s6Member'];
    enterStaffPractice($this->tree['users']['s6Lead'], $member, $this->tree['units']['host']->id);

    $own = staffPracticeProps($this, $this->tree['users']['s6Member'], $member);
    expect($own['canRecord'])->toBeTrue()->and($own['entries'][0]['removable'])->toBeTrue();

    $admin = staffPracticeProps($this, $this->tree['users']['admin'], $member);
    expect($admin['canRecord'])->toBeTrue();

    enterStaffPractice($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id);
    $grant = staffPracticeProps($this, $this->tree['users']['grant'], $this->tree['troopers']['psg']);
    expect($grant['canRecord'])->toBeFalse()
        ->and($grant['sections'])->toBe([])
        ->and($grant['entries'])->toHaveCount(1)
        ->and($grant['entries'][0]['removable'])->toBeFalse();
});

it('shows no panel for a trooper who is not staff and has no entries', function () {
    expect(staffPracticeProps($this, $this->tree['users']['pl'], $this->tree['troopers']['rifleman']))->toBeNull();
});

it('shows the attended practices on the roster sheet, read only', function () {
    $psg = $this->tree['troopers']['psg'];
    enterStaffPractice($this->tree['users']['pl'], $psg, $this->tree['units']['host']->id);

    $this->actingAs($this->tree['users']['pl'])->getJson(route('roster.trooper', $psg->id))
        ->assertOk()
        ->assertJsonPath('trooper.staffPractices.0.section', '1/1/A/1-7')
        ->assertJsonPath('trooper.staffPractices.0.enteredBy', 'Mercer.J')
        ->assertJsonPath('trooper.staffPractices.0.source', 'entered')
        ->assertJsonMissingPath('trooper.staffPractices.0.removable');
});

it('enters and removes through the routes, answering refusals on their field', function () {
    $psg = $this->tree['troopers']['psg'];
    $pl = $this->tree['users']['pl'];
    $host = $this->tree['units']['host']->id;

    $this->actingAs($pl)->post(route('staff-practices.store', $psg->id), ['section_id' => $host, 'week' => '2026-10-06'])
        ->assertRedirect(route('troopers.show', $psg->id));
    expect(CrossSectionSighting::query()->sole()->week_start->toDateString())->toBe('2026-10-03');

    $this->actingAs($pl)->post(route('staff-practices.store', $psg->id), ['section_id' => $host, 'week' => '2026-10-03'])
        ->assertSessionHasErrors('section_id');
    $this->actingAs($pl)->post(route('staff-practices.store', $psg->id), ['section_id' => $host, 'week' => '2026-10-17'])
        ->assertSessionHasErrors('week');
    $this->actingAs($pl)->post(route('staff-practices.store', $psg->id), ['section_id' => $host])
        ->assertSessionHasErrors('week');

    $this->actingAs($pl)->post(route('staff-practices.remove', $psg->id), ['section_id' => $host, 'week' => '2026-10-03'])
        ->assertRedirect(route('troopers.show', $psg->id));
    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->pluck('action')->all())->toBe(['staff_practice.recorded', 'staff_practice.removed']);
});

it('answers 403 to someone who sees the trooper but may not speak for them, and 404 to someone who cannot see them', function () {
    $psg = $this->tree['troopers']['psg'];
    $body = ['section_id' => $this->tree['units']['host']->id, 'week' => '2026-10-03'];

    $this->actingAs($this->tree['users']['grant'])->post(route('staff-practices.store', $psg->id), $body)->assertForbidden();
    $this->actingAs($this->tree['users']['s6Lead'])->post(route('staff-practices.store', $psg->id), $body)->assertNotFound();

    expect(CrossSectionSighting::query()->count())->toBe(0);
});

it('is read only during a view-as preview: nothing offered, every write refused', function () {
    $admin = $this->tree['users']['admin'];
    $psg = $this->tree['troopers']['psg'];
    enterStaffPractice($admin, $psg, $this->tree['units']['host']->id);
    app(ViewAs::class)->enter($admin, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['platoon']->id));

    $props = staffPracticeProps($this, $admin, $psg);
    expect($props['canRecord'])->toBeFalse()
        ->and($props['sections'])->toBe([])
        ->and($props['entries'][0]['removable'])->toBeFalse();

    $body = ['section_id' => $this->tree['units']['other']->id, 'week' => '2026-10-03'];
    $this->actingAs($admin)->post(route('staff-practices.store', $psg->id), $body)->assertForbidden();
    $this->actingAs($admin)->post(route('staff-practices.remove', $psg->id), ['section_id' => $this->tree['units']['host']->id, 'week' => '2026-10-03'])->assertForbidden();

    expect(CrossSectionSighting::query()->count())->toBe(1);
});

it('never puts a staff member on the host rollcall, its guests, its numbers or the JSAM counts', function () {
    $host = $this->tree['units']['host'];
    $platoon = $this->tree['units']['platoon'];
    $kpis = app(KpiService::class);
    $before = [$kpis->forUnit($host, $this->week), $kpis->forUnit($platoon, $this->week)];

    enterStaffPractice($this->tree['users']['pl'], $this->tree['troopers']['psg'], $host->id);
    enterStaffPractice($this->tree['users']['s6Lead'], $this->tree['troopers']['s6Member'], $host->id);

    $practice = app(SectionPracticeRepository::class)->find($host->id, $this->week);
    expect(app(RollcallGuests::class)->forPractice($practice))->toBe([])
        ->and(Attendance::query()->count())->toBe(0)
        ->and($kpis->forUnit($host, $this->week))->toEqual($before[0])
        ->and($kpis->forUnit($platoon, $this->week))->toEqual($before[1])
        ->and(app(CrossSectionSightingRepository::class)->visitsWithin($this->tree['units']['regiment'], $this->week->subWeeks(11), $this->week))->toHaveCount(0)
        ->and(app(PracticeGuests::class)->forUnit($platoon, $this->week))->toBe([]);

    $page = $this->actingAs($this->tree['users']['sl'])->get(route('rollcall.show', $host->id))->assertOk()->viewData('page')['props'];
    expect($page['guests'])->toBe([])
        ->and(collect($page['troopers'] ?? [])->pluck('id')->all())->not->toContain($this->tree['troopers']['psg']->id);
});

it('gives no makeup, even once the staff member has moved into a section', function () {
    $psg = $this->tree['troopers']['psg'];
    enterStaffPractice($this->tree['users']['pl'], $psg, $this->tree['units']['host']->id);
    $psg->forceFill(['primary_section_id' => $this->tree['units']['other']->id])->save();

    $sightings = app(CrossSectionSightingRepository::class);
    $practice = app(SectionPracticeRepository::class)->find($this->tree['units']['host']->id, $this->week);
    $result = app(MarkAttendanceHandler::class)->preMarkMakeups($practice, [$psg->id], $this->tree['users']['pl']->id);

    expect($sightings->trooperIdsSeenInWeek([$psg->id], $this->week))->toBe([])
        ->and($sightings->existsForTrooperInWeek($psg->id, $this->week))->toBeFalse()
        ->and($result->marked)->toBe(0)
        ->and(Attendance::query()->count())->toBe(0);
});
