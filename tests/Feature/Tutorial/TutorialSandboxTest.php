<?php

use App\Commands\StaffReports\PushStaffReportToSheet;
use App\Jobs\PushStaffReportToSheetJob;
use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PracticeCapturePlayer;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TutorialPracticeRepository;
use App\Support\Time\WeekOf;
use App\Tutorial\PracticeWorld;
use App\Tutorial\RealDatabaseTouched;
use App\Tutorial\TutorialPersona;
use App\Tutorial\TutorialSandbox;
use App\Tutorial\TutorialSteps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\Request as SaloonRequest;

// The hands-on tutorial's isolation (App\Tutorial). The practice copies live in a temporary folder kept between runs, so
// the migrated template is built once and reused.
beforeEach(function () {
    config(['portal.tutorial_path' => sys_get_temp_dir().'/portal-tutorial-tests']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'callsign' => '1/1/A/1-7']);
});

afterEach(function () {
    app(TutorialSandbox::class)->leave();
    foreach (glob(sys_get_temp_dir().'/portal-tutorial-tests/user-*.sqlite') ?: [] as $file) {
        @unlink($file);
    }
});

function realCounts(): array
{
    return collect(['units', 'troopers', 'audit_log', 'attendance', 'section_practices', 'weekly_reports', 'staff_reports', 'page_visits', 'users'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function inTutorial(int $userId, callable $work): mixed
{
    return app(TutorialSandbox::class)->run($userId, $work);
}

/** Gives SPC Demo.K, the one unmarked trooper, a mark, as the roll call step asks. */
function markDemo($test, int $userId, int $section, string $week): void
{
    $demo = inTutorial($userId, fn () => app(TutorialPracticeRepository::class)->trooperByUsername(PracticeWorld::DEMO)->id);
    $test->postJson("/rollcall/{$section}", ['week' => $week, 'marks' => [['trooper_id' => $demo, 'status' => 'excused']]])->assertOk();
}

function practiceSectionId(int $userId): int
{
    return inTutorial($userId, fn () => app(TutorialPracticeRepository::class)->unitByCallsign(PracticeWorld::SECTION)->id);
}

it('runs every tutorial write on the practice copy, leaving the real audit log only the start', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $before = realCounts();

    $this->actingAs($leader)->post('/tutorial')->assertRedirect();
    $section = practiceSectionId($leader->id);
    $week = WeekOf::start(CarbonImmutable::now())->toDateString();

    $this->get("/rollcall/{$section}")->assertOk()->assertInertia(fn ($page) => $page->where('tutorialMode.steps.0.id', 'rollcall'));
    markDemo($this, $leader->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();
    $this->postJson("/rollcall/{$section}/posted", ['week' => $week])->assertOk();

    // The one real trace of the tutorial: the start, noted against the person.
    expect(realCounts())->toBe([...$before, 'audit_log' => $before['audit_log'] + 1])
        ->and(AuditLogEntry::query()->latest('id')->value('action'))->toBe('tutorial.started');

    $practice = inTutorial($leader->id, fn () => [
        'confirmed' => AuditLogEntry::query()->where('action', 'practice.confirmed')->count(),
        'posted' => AuditLogEntry::query()->where('action', 'rollcall.posted')->count(),
        'units' => Unit::query()->count(),
    ]);
    expect($practice)->toBe(['confirmed' => 1, 'posted' => 1, 'units' => 6]);
});

it('ticks the coach card from what was done in the practice copy', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $section = practiceSectionId($leader->id);
    $week = WeekOf::start(CarbonImmutable::now())->toDateString();

    $this->get("/rollcall/{$section}")->assertInertia(fn ($page) => $page->where('tutorialMode.steps.0.highlight', fn ($selector) => str_starts_with((string) $selector, '[data-trooper="')));
    markDemo($this, $leader->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();
    $this->get("/rollcall/{$section}")->assertInertia(fn ($page) => $page
        ->where('tutorialMode.steps.0.checklist.0.done', true)
        ->where('tutorialMode.steps.0.checklist.3.done', true)
        ->where('tutorialMode.steps.0.checklist.4.done', false)
        // Confirmed but not posted: Mark posted is the next thing to do, unless Demo.K still needs a signature.
        ->where('tutorialMode.steps.0.highlight', fn ($selector) => in_array($selector, ['[data-testid="mark-posted"]'], true) || str_starts_with((string) $selector, '[data-trooper="')));
});

it('ticks the subject of a platoon report from its training topic, and points at the subject and notes, then Send', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $persona = new TutorialPersona(
        admin: false, leads: UnitType::Platoon, hqStaff: null,
        username: 'Someone.X', forumUserId: 1, cavId: 1, rankShort: '1LT', rank: 'First Lieutenant',
    );
    $steps = fn () => collect(app(TutorialSteps::class)->describe($leader->id, $persona))->keyBy('id');

    $before = inTutorial($leader->id, $steps);
    expect($before['weekly-report']['checklist'][1]['done'])->toBeFalse()
        ->and($before['weekly-report']['highlight'])->toBe('#training-subject, #platoon-topic, #report-notes')
        ->and($before['weekly-report']['nav'])->toBe('[data-testid="nav-Reports"]')
        ->and($before['rollcall']['nav'])->toBe('[data-testid="nav-Roll call"]');

    $after = inTutorial($leader->id, function () use ($steps) {
        PlatoonTrainingTopic::query()->create([
            'unit_id' => app(TutorialPracticeRepository::class)->unitByCallsign(PracticeWorld::PLATOON)->id,
            'week_start' => WeekOf::reporting(CarbonImmutable::now('UTC')),
            'subject' => 'Radio procedure',
            'set_by' => User::query()->value('id'),
        ]);

        return $steps();
    });
    expect($after['weekly-report']['checklist'][1]['done'])->toBeTrue()
        ->and($after['weekly-report']['highlight'])->toBe('[data-testid="send-button"], [data-testid="send-anyway"]');
});

it('leaves no unmatched player on the practice roll call, as no step teaches matching one', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');

    expect(inTutorial($leader->id, fn () => PracticeCapturePlayer::query()->whereNull('trooper_id')->count()))->toBe(0);
});

it('links every practice trooper to the practice game, so no identity warning shows', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');

    $unlinked = inTutorial($leader->id, fn () => Trooper::query()->whereNotIn('id', TrooperGameIdentity::query()->select('trooper_id'))->count());

    expect($unlinked)->toBe(0);
});

it('fails loudly when a tutorial request reaches the real database', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');

    // The real connection by name, whichever database the suite runs on (SQLite locally, MySQL in CI).
    $real = config('database.default');
    expect(fn () => inTutorial($leader->id, fn () => DB::connection($real)->table('users')->count()))
        ->toThrow(RealDatabaseTouched::class);
    // Out of the tutorial again, the real connection works as before.
    expect(DB::table('users')->count())->toBeGreaterThan(0);
});

it('sends nothing out: jobs are dropped and every connector gets the mock', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    Bus::fake();

    $status = inTutorial($leader->id, function () {
        dispatch(new PushStaffReportToSheetJob(new PushStaffReportToSheet(1)));
        $connector = new class extends Connector
        {
            public function resolveBaseUrl(): string
            {
                return 'https://example.invalid';
            }
        };
        $request = new class extends SaloonRequest
        {
            protected Method $method = Method::GET;

            public function resolveEndpoint(): string
            {
                return '/anything';
            }
        };

        return $connector->send($request)->status();
    });

    expect($status)->toBe(503)
        ->and(app(TutorialSandbox::class)->heldBackCalls())->toBeGreaterThan(0);
    Bus::assertNotDispatched(PushStaffReportToSheetJob::class);
});

it('keeps each person in their own practice copy', function () {
    $first = User::factory()->commanding($this->section)->create();
    $second = User::factory()->commanding(Unit::factory()->create(['type' => UnitType::Section]))->create();

    $this->actingAs($first)->post('/tutorial');
    $section = practiceSectionId($first->id);
    $week = WeekOf::start(CarbonImmutable::now())->toDateString();
    markDemo($this, $first->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();

    $this->actingAs($second)->post('/tutorial');

    $sandbox = app(TutorialSandbox::class);
    expect($sandbox->path($first->id))->not->toBe($sandbox->path($second->id))
        ->and(inTutorial($second->id, fn () => AuditLogEntry::query()->where('action', 'practice.confirmed')->count()))->toBe(0)
        ->and(inTutorial($second->id, fn () => User::query()->whereKey($first->id)->exists()))->toBeFalse()
        ->and(inTutorial($first->id, fn () => AuditLogEntry::query()->where('action', 'practice.confirmed')->count()))->toBe(1);
});

it('puts the two maintainers in every fresh practice copy, also after a start over', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');

    $names = fn () => inTutorial($leader->id, fn () => Trooper::query()->whereIn('username', PracticeWorld::MAINTAINERS)->pluck('username')->sort()->values()->all());
    expect($names())->toBe(['Grayson.J', 'Laui.M']);

    $this->post('/tutorial/restart')->assertRedirect();
    expect($names())->toBe(['Grayson.J', 'Laui.M']);
});

it('starts over from fresh practice data', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $section = practiceSectionId($leader->id);
    $week = WeekOf::start(CarbonImmutable::now())->toDateString();
    markDemo($this, $leader->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();

    $this->post('/tutorial/restart');

    expect(inTutorial($leader->id, fn () => AuditLogEntry::query()->where('action', 'practice.confirmed')->count()))->toBe(0);
});

it('returns to the guides home and the real portal on exit', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $real = DB::getDefaultConnection();
    $this->actingAs($leader)->from('/docs')->post('/tutorial');

    $this->delete('/tutorial')->assertRedirect('/docs');

    expect(app(TutorialSandbox::class)->exists($leader->id))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe($real);
    $this->get('/home')->assertOk()->assertInertia(fn ($page) => $page->where('tutorialMode', null)->where('scopeTop.0.id', $this->section->id));
});

it('cannot be combined with view as', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');

    $this->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertRedirect();
    expect(session('view_as'))->toBeNull();
});

it('lists the steps for the echelon the person holds', function (array $persona, array $steps) {
    $persona = new TutorialPersona(
        admin: $persona['admin'] ?? false,
        leads: $persona['leads'] ?? null,
        hqStaff: $persona['hq'] ?? null,
        username: 'Someone.X', forumUserId: 1, cavId: 1, rankShort: 'SGT', rank: 'Sergeant',
    );

    expect(TutorialSteps::idsFor($persona))->toBe($steps);
})->with([
    'section leader' => [['leads' => UnitType::Section], ['rollcall', 'weekly-report', 'roster']],
    'platoon leader' => [['leads' => UnitType::Platoon, 'hq' => UnitType::Platoon], ['rollcall', 'weekly-report', 'staff-report', 'roster']],
    'company HQ staff' => [['hq' => UnitType::Company], ['staff-report', 'roster']],
    'trooper' => [[], ['roster']],
    'admin' => [['admin' => true], ['rollcall', 'weekly-report', 'roster', 'admin']],
]);

it('opens every step page of a platoon leader and an Admin in the tutorial', function (bool $admin) {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    $leader = User::factory()->commanding($platoon)->create();
    Trooper::query()->where('cav_id', $leader->cav_id)->first()->forceFill(['primary_section_id' => $platoon->id])->save();
    if ($admin) {
        $leader->forceFill(['is_admin' => true])->save();
    }

    $this->actingAs($leader)->post('/tutorial')->assertRedirect();
    $steps = $this->get('/home')->assertOk()->viewData('page')['props']['tutorialMode']['steps'];

    expect(array_column($steps, 'id'))->toBe($admin
        ? ['rollcall', 'weekly-report', 'staff-report', 'roster', 'admin']
        : ['rollcall', 'weekly-report', 'staff-report', 'roster']);
    foreach ($steps as $step) {
        $r = $this->get($step['href']);
        if ($r->status() === 302) {
            $r = $this->get($r->headers->get('Location'));
        } expect($r->status())->toBe(200, $step['href']);
    }
})->with(['platoon leader' => false, 'Admin' => true]);

it('serves the blue tutorial icons in the tutorial and the usual ones outside it', function () {
    $leader = User::factory()->commanding($this->section)->create();

    $this->actingAs($leader)->get('/home')->assertSee('href="/favicon-32x32.png?v=', false);
    $this->post('/tutorial');
    $this->get('/home')->assertSee('href="/tutorial-icons/favicon-32x32.png?v=', false);
    expect(file_exists(public_path('tutorial-icons/favicon.ico')))->toBeTrue();
    // A public/tutorial folder would shadow the POST /tutorial route on the web server.
    expect(is_dir(public_path('tutorial')))->toBeFalse();
});

it('notes starting, starting over and leaving the tutorial in the real audit log, against the person', function () {
    $leader = User::factory()->commanding($this->section)->create();

    $this->actingAs($leader)->post('/tutorial');
    $this->post('/tutorial/restart');
    $this->delete('/tutorial');

    $entries = AuditLogEntry::query()->orderBy('id')->get();
    expect($entries->pluck('action')->all())->toBe(['tutorial.started', 'tutorial.restarted', 'tutorial.exited'])
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$leader->id])
        ->and($entries->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([$leader->id]);
});
