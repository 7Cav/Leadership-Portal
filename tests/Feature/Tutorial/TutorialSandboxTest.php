<?php

use App\Commands\StaffReports\PublicServerHour;
use App\Commands\StaffReports\PushStaffReportToSheet;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\PushStaffReportToSheetJob;
use App\Models\AuditLogEntry;
use App\Models\PageVisit;
use App\Models\PlatoonTrainingTopic;
use App\Models\PracticeCapturePlayer;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\TutorialPracticeRepository;
use App\Support\Time\WeekOf;
use App\Tutorial\PracticePublicServerHour;
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
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();

    $this->get("/rollcall/{$section}?week={$week}")->assertOk()->assertInertia(fn ($page) => $page->where('tutorialMode.steps.0.id', 'rollcall'));
    $this->postJson("/rollcall/{$section}/create", ['week' => $week])->assertOk();
    $this->postJson("/rollcall/{$section}/posted", ['week' => $week])->assertOk();
    markDemo($this, $leader->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();

    // The one real trace of the tutorial: the start, noted against the person.
    expect(realCounts())->toBe([...$before, 'audit_log' => $before['audit_log'] + 1])
        ->and(AuditLogEntry::query()->latest('id')->value('action'))->toBe('tutorial.started');

    $practice = inTutorial($leader->id, fn () => [
        'created' => AuditLogEntry::query()->where('action', 'rollcall.created')->count(),
        'confirmed' => AuditLogEntry::query()->where('action', 'practice.confirmed')->count(),
        'posted' => AuditLogEntry::query()->where('action', 'rollcall.posted')->count(),
        'units' => Unit::query()->count(),
    ]);
    expect($practice)->toBe(['created' => 1, 'confirmed' => 1, 'posted' => 1, 'units' => 6]);
});

it('ticks the coach card from what was done in the practice copy', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $section = practiceSectionId($leader->id);
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();

    // The tutorial's week is on the new roll call (#703): Create roll call first, or the Create step while another is open.
    $this->get("/rollcall/{$section}?week={$week}")->assertInertia(fn ($page) => $page
        ->where('flow.version', 2)
        ->where('tutorialMode.steps.0.highlight', '[data-step="1"]:not([aria-current="step"]), section[aria-labelledby="step-1-title"] [data-testid="create-rollcall"]'));
    markDemo($this, $leader->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();
    $this->get("/rollcall/{$section}?week={$week}")->assertInertia(fn ($page) => $page
        ->where('tutorialMode.steps.0.checklist.0.done', true)
        ->where('tutorialMode.steps.0.checklist.1.done', false)
        ->where('tutorialMode.steps.0.checklist.4.done', true)
        ->where('tutorialMode.steps.0.checklist.5.done', true)
        // Marked and confirmed, but not created: creating the roll call is the next thing to do.
        ->where('tutorialMode.steps.0.highlight', fn ($selector) => str_contains((string) $selector, 'step-1-title')));
});

it('asks for an answer for Demo.K, and ticks it only once that answer is not Unsigned', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $section = practiceSectionId($leader->id);
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();
    $demo = inTutorial($leader->id, fn () => app(TutorialPracticeRepository::class)->trooperByUsername(PracticeWorld::DEMO)->id);
    $step = function () use ($section, $week) {
        $step = null;
        $this->get("/rollcall/{$section}?week={$week}")->assertInertia(function ($page) use (&$step) {
            $step = $page->toArray()['props']['tutorialMode']['steps'][0];
        });

        return $step;
    };

    // Unsigned is already selected on the card, so the copy must not describe Demo.K's start as "not signed".
    $before = $step();
    expect(strtolower($before['body']))->not->toContain('not signed')
        ->and(strtolower($before['body']))->not->toContain('unsigned')
        ->and($before['body'])->toContain('has not answered the roll call yet')
        ->and($before['body'])->toContain('Will attend')
        ->and($before['checklist'][3])->toBe(['label' => 'Pick an answer for SPC Demo.K', 'done' => false]);

    $sign = fn (string $answer) => $this->postJson("/rollcall/{$section}", ['week' => $week, 'marks' => [['trooper_id' => $demo, 'rollcall' => $answer]]])->assertOk();

    // Any answer but Unsigned ticks it, Excused too, and going back to Unsigned opens it again.
    $sign('will_attend');
    expect($step()['checklist'][3]['done'])->toBeTrue();
    $sign('unsigned');
    expect($step()['checklist'][3]['done'])->toBeFalse();
    $sign('excused');
    expect($step()['checklist'][3]['done'])->toBeTrue();
});

it('points at each roll call step in turn: its tab while another step is open, then what to do on it', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $section = practiceSectionId($leader->id);
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();
    $demo = inTutorial($leader->id, fn () => app(TutorialPracticeRepository::class)->trooperByUsername(PracticeWorld::DEMO)->id);
    $highlight = function () use ($section, $week) {
        $selector = null;
        $this->get("/rollcall/{$section}?week={$week}")->assertInertia(function ($page) use (&$selector) {
            $selector = $page->toArray()['props']['tutorialMode']['steps'][0]['highlight'];
        });

        return $selector;
    };

    expect($highlight())->toBe('[data-step="1"]:not([aria-current="step"]), section[aria-labelledby="step-1-title"] [data-testid="create-rollcall"]');

    $this->postJson("/rollcall/{$section}/create", ['week' => $week])->assertOk();
    expect($highlight())->toBe('[data-step="2"]:not([aria-current="step"]), section[aria-labelledby="step-2-title"] [data-testid="mark-posted"]');

    $this->postJson("/rollcall/{$section}/posted", ['week' => $week])->assertOk();
    expect($highlight())->toBe('[data-step="3"]:not([aria-current="step"]), section[aria-labelledby="step-3-title"] [data-kind="signature"] [data-trooper="'.$demo.'"]');

    $this->postJson("/rollcall/{$section}", ['week' => $week, 'marks' => [['trooper_id' => $demo, 'rollcall' => 'will_attend']]])->assertOk();
    expect($highlight())->toBe('[data-step="3"]:not([aria-current="step"]), section[aria-labelledby="step-3-title"] [data-kind="practice"] [data-trooper="'.$demo.'"]');

    markDemo($this, $leader->id, $section, $week);
    expect($highlight())->toBe('[data-testid="confirm-practice"]');

    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();
    expect($highlight())->toBeNull();
});

it('ticks the subject of a platoon report from its training topic, and points at the subject and notes, then Send', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $persona = new TutorialPersona(
        admin: false, leads: UnitType::Platoon, hqStaff: null,
        username: 'Someone.X', forumUserId: 1, cavId: 1, rankShort: '1LT', rank: 'First Lieutenant',
    );
    $steps = fn () => collect(app(TutorialSteps::class)->describe($leader->id, $persona))->keyBy('id');

    $platoon = inTutorial($leader->id, fn () => app(TutorialPracticeRepository::class)->unitByCallsign(PracticeWorld::PLATOON)->id);
    $before = inTutorial($leader->id, $steps);
    // Not opened yet: the platoon's row on the roll-up, then Open report in its panel.
    expect($before['weekly-report']['checklist'][1]['done'])->toBeFalse()
        ->and($before['weekly-report']['highlight'])->toBe('[data-row-id="'.$platoon.'"]:not([aria-selected="true"]), [data-report-unit="'.$platoon.'"] [data-testid="open-report"]')
        ->and($before['weekly-report']['area'])->toBe('/reports')
        ->and($before['weekly-report']['nav'])->toBe('[data-testid="nav-Reports"]')
        ->and($before['rollcall']['nav'])->toBe('[data-testid="nav-Roll call"]');

    $opened = inTutorial($leader->id, function () use ($steps, $platoon, $leader) {
        PageVisit::query()->create(['user_id' => $leader->id, 'url' => '/reports/'.$platoon.'/'.WeekOf::reporting(CarbonImmutable::now('UTC'))->toDateString(), 'visited_at' => CarbonImmutable::now()]);

        return $steps();
    });
    expect($opened['weekly-report']['checklist'][0]['done'])->toBeTrue()
        ->and($opened['weekly-report']['highlight'])->toBe('#training-subject, #platoon-topic');

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
        ->and($after['weekly-report']['checklist'][2]['label'])->toBe('Write a note')
        ->and($after['weekly-report']['checklist'][2]['done'])->toBeFalse()
        ->and($after['weekly-report']['highlight'])->toBe('#report-notes');
});

it('asks a company leader for a note only, as a company report has no subject, and then points at Send', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $persona = new TutorialPersona(
        admin: false, leads: UnitType::Company, hqStaff: null,
        username: 'Someone.X', forumUserId: 1, cavId: 1, rankShort: 'CPT', rank: 'Captain',
    );
    $steps = fn () => collect(app(TutorialSteps::class)->describe($leader->id, $persona))->keyBy('id');
    $company = inTutorial($leader->id, fn () => app(TutorialPracticeRepository::class)->unitByCallsign(PracticeWorld::COMPANY)->id);

    $opened = inTutorial($leader->id, function () use ($steps, $company, $leader) {
        PageVisit::query()->create(['user_id' => $leader->id, 'url' => '/reports/'.$company.'/'.WeekOf::reporting(CarbonImmutable::now('UTC'))->toDateString(), 'visited_at' => CarbonImmutable::now()]);

        return $steps();
    });

    expect(array_column($opened['weekly-report']['checklist'], 'label'))->toBe(['Open the report for this week', 'Write a note', 'Send the report'])
        ->and($opened['weekly-report']['body'])->toStartWith('Write a short note, check the numbers')
        ->and($opened['weekly-report']['highlight'])->toBe('#report-notes');
});

it('keeps a tab switch as a visit in the practice copy, but never the coach card\'s own refresh', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $partial = fn (string $url, string $only) => $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Partial-Component' => 'Roster',
        'X-Inertia-Partial-Data' => $only,
    ])->get($url)->assertOk();

    $partial('/roster?view=matrix', 'roster');
    $partial('/roster?view=orbat', 'tutorialMode');

    $urls = inTutorial($leader->id, fn () => PageVisit::query()->where('user_id', $leader->id)->pluck('url')->all());
    expect($urls)->toContain('/roster?view=matrix')
        ->and($urls)->not->toContain('/roster?view=orbat');
});

it('leaves no unmatched player on the practice roll call, as no step teaches matching one', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');

    expect(inTutorial($leader->id, fn () => PracticeCapturePlayer::query()->whereNull('trooper_id')->count()))->toBe(0);
});

it('backs up an HQ staff member\'s staff report with practice server data', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    $leader = User::factory()->commanding($platoon)->create();
    Trooper::query()->where('cav_id', $leader->cav_id)->first()->forceFill(['primary_section_id' => $platoon->id])->save();
    $this->actingAs($leader)->post('/tutorial')->assertRedirect();

    [$public, $seen, $sections] = inTutorial($leader->id, function () use ($leader) {
        $practice = app(TutorialPracticeRepository::class);
        $self = $practice->trooperByUsername($leader->username);
        $week = WeekOf::reporting(CarbonImmutable::now('UTC'));

        return [
            app(PublicServerHour::class)->check($self->id, $practice->unitByCallsign(PracticeWorld::PLATOON), $week),
            array_keys(app(PracticeCapturePlayerRepository::class)->seenInWeek([$self->id], $week)[$self->id] ?? []),
            [$practice->unitByCallsign(PracticeWorld::SECTION)->id, $practice->unitByCallsign(PracticeWorld::OTHER_SECTION)->id],
        ];
    });

    expect($public)->toBe(['check' => 'confirmed', 'minutes' => PracticePublicServerHour::MINUTES, 'reason' => null])
        ->and(collect($seen)->sort()->values()->all())->toBe(collect($sections)->sort()->values()->all())
        ->and(app(PublicServerHour::class))->not->toBeInstanceOf(PracticePublicServerHour::class);
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
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();
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
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();
    markDemo($this, $leader->id, $section, $week);
    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();

    $this->post('/tutorial/restart');

    expect(inTutorial($leader->id, fn () => AuditLogEntry::query()->where('action', 'practice.confirmed')->count()))->toBe(0);
});

it('confirms the week the tutorial is built on, which on a Saturday is the closing week until its report is due', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));
    $leader = User::factory()->commanding($this->section)->create();
    $this->actingAs($leader)->post('/tutorial');
    $section = practiceSectionId($leader->id);
    $week = WeekOf::reporting(CarbonImmutable::now())->toDateString();
    expect($week)->toBe('2026-11-07');
    markDemo($this, $leader->id, $section, $week);

    $this->postJson("/rollcall/{$section}/confirm", ['week' => $week])->assertOk();
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
