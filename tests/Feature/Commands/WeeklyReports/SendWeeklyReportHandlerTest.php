<?php

use App\Commands\WeeklyReports\InvalidReportContentException;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportForbiddenException;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Commands\WeeklyReports\SentWeeklyReport;
use App\Kpi\KpiDelta;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\ArtifactType;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Repositories\WeeklyReportRepository\Eloquent as EloquentWeeklyReportRepository;
use App\Rollcall\TemplateResolver;
use App\Support\Audit\ReasonRequiredException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;

// 5.6 / AD-12, AD-16, AD-10, AD-22, AD-8: send freezes the live-computed snapshot into the
// unit-week's report through one audited, reason-required, purely internal Command.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '700/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '700/701/']);
    $this->leader = User::factory()->commanding($this->section)->create();

    // Wednesday 2026-10-07 18:00 UTC falls in the Cav week opening Saturday 2026-10-03.
    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    // Writes are refused before the week floor, which counts back from now: pin now to this week.
    CarbonImmutable::setTestNow($this->moment);

    $this->troopers = Trooper::factory()->count(2)->create(['primary_section_id' => $this->section->id]);
    sendMark($this->section, $this->troopers[0], $this->weekStart, AttendanceStatus::Present);
});

function sendMark(Unit $section, Trooper $trooper, CarbonInterface $weekStart, AttendanceStatus $status): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, $weekStart);
    app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
}

function sendReport(User $actor, int $unitId, DateTimeImmutable $moment, ?string $reason = 'Weekly report', ?string $notes = 'All good.'): SentWeeklyReport
{
    return app(SendWeeklyReportHandler::class)->handle($actor, new SendWeeklyReport($unitId, $moment, $reason, $notes));
}

/**
 * The stored row's digest recomputed from the stored row itself, with the snapshot's keys put in
 * reverse order — what MySQL's JSON column may hand back — so a match proves the digest does not
 * depend on key order.
 */
function digestOfStored(WeeklyReport $report): string
{
    $reverse = function (array $value) use (&$reverse): array {
        $value = array_is_list($value) ? $value : array_reverse($value, preserve_keys: true);

        return array_map(fn ($item) => is_array($item) ? $reverse($item) : $item, $value);
    };

    return SendWeeklyReportHandler::contentDigest(
        $report->unit_id, $report->week_start, $reverse($report->kpis), $report->notes, $report->template_version_id,
        $report->training_subject, $report->unaccounted ?? [],
    );
}

it('freezes the live KPIs, the delta and the notes, and marks the report sent', function () {
    $result = sendReport($this->leader, $this->section->id, $this->moment, notes: 'Two new recruits.');

    $report = WeeklyReport::query()->sole();
    expect($result->alreadySent)->toBeFalse()
        ->and($result->report->is($report))->toBeTrue()
        ->and($report->unit_id)->toBe($this->section->id)
        ->and($report->week_start->equalTo($this->weekStart))->toBeTrue()
        ->and($report->status)->toBe(WeeklyReportStatus::Sent)
        ->and(ReportSnapshot::fromArray($report->kpis))->toEqual(new ReportSnapshot(
            app(KpiService::class)->forUnit($this->section, $this->weekStart),
            null, // the section's first report
        ))
        ->and($report->notes)->toBe('Two new recruits.')
        ->and($report->sent_by)->toBe($this->leader->id)
        ->and($report->sent_at)->not->toBeNull()
        ->and($report->template_version_id)->toBeNull(); // the weekly report has no layout template yet
});

it('records a content digest that can be re-verified from the stored row, whatever its key order', function () {
    sendReport($this->leader, $this->section->id, $this->moment->subWeek());
    sendReport($this->leader, $this->section->id, $this->moment); // carries a delta

    $report = WeeklyReport::query()->where('week_start', $this->weekStart)->sole();
    expect($report->kpis['delta'])->not->toBeNull()
        ->and($report->content_digest)->toBe(digestOfStored($report));
});

it('digests the notes and the KPIs, so a change to either changes the digest', function () {
    $report = sendReport($this->leader, $this->section->id, $this->moment, notes: 'original')->report;
    $digest = fn (array $kpis, ?string $notes) => SendWeeklyReportHandler::contentDigest(
        $report->unit_id, $report->week_start, $kpis, $notes, null,
    );

    expect($digest($report->kpis, 'original'))->toBe($report->content_digest)
        ->and($digest($report->kpis, 'edited'))->not->toBe($report->content_digest)
        ->and($digest([...$report->kpis, 'strength' => $report->kpis['strength'] + 1], 'original'))->not->toBe($report->content_digest);
});

it('digests the template version, so a different version changes the digest', function () {
    $report = sendReport($this->leader, $this->section->id, $this->moment)->report;
    $digest = fn (?int $templateVersionId) => SendWeeklyReportHandler::contentDigest(
        $report->unit_id, $report->week_start, $report->kpis, $report->notes, $templateVersionId,
    );

    expect($digest(null))->toBe($report->content_digest)
        ->and($digest(1))->not->toBe($report->content_digest)
        ->and($digest(2))->not->toBe($digest(1));
});

it('freezes a null template version even when the owning company has an active rollcall template', function () {
    // The rollcall BBCode is the only template kind, and the weekly report does not render from it.
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    DB::table('units')->where('id', $this->section->id)->update(['path' => "{$company->id}/{$this->section->id}/"]);
    app(TemplateVersionRepository::class)->author($company->id, ArtifactType::RollcallBbcode, '[B]{{unit}}[/B]', $this->leader->id);
    expect(app(TemplateResolver::class)->forUnit($this->section)->isResolved())->toBeTrue();

    $report = sendReport($this->leader, $this->section->id, $this->moment)->report;

    expect($report->template_version_id)->toBeNull()
        ->and($report->content_digest)->toBe(digestOfStored($report));
});

it('freezes null notes as null', function () {
    expect(sendReport($this->leader, $this->section->id, $this->moment, notes: null)->report->notes)->toBeNull();
});

it('freezes the delta against the unit\'s previous sent report', function () {
    sendReport($this->leader, $this->section->id, $this->moment->subWeek());
    sendMark($this->section, $this->troopers[1], $this->weekStart, AttendanceStatus::Makeup);

    sendReport($this->leader, $this->section->id, $this->moment);

    $previous = ReportSnapshot::fromArray(app(WeeklyReportRepository::class)->find($this->section->id, $this->weekStart->subWeek())->kpis);
    $current = ReportSnapshot::fromArray(app(WeeklyReportRepository::class)->find($this->section->id, $this->weekStart)->kpis);
    expect($current->delta)->toEqual(KpiDelta::between($current->kpis, $previous->kpis))
        ->and($current->delta->makeups)->toBe(1);
});

it('promotes an existing draft in place rather than adding a row', function () {
    $draft = app(WeeklyReportRepository::class)->saveDraft($this->section->id, $this->weekStart, ['stale' => true], 'draft notes');

    sendReport($this->leader, $this->section->id, $this->moment, notes: 'final notes');

    $report = WeeklyReport::query()->sole();
    expect($report->id)->toBe($draft->id)
        ->and($report->status)->toBe(WeeklyReportStatus::Sent)
        ->and($report->notes)->toBe('final notes')
        ->and($report->kpis)->not->toHaveKey('stale');
});

it('records one audit entry with actor, action, the report as target, reason and digest', function () {
    $result = sendReport($this->leader, $this->section->id, $this->moment, reason: 'End of week');

    $entry = AuditLogEntry::query()->sole();
    expect($entry->actor_id)->toBe($this->leader->id)
        ->and($entry->action)->toBe('weekly_report.sent')
        ->and($entry->target_type)->toBe('WeeklyReport')
        ->and($entry->target_id)->toBe((string) $result->report->id) // the one (unit_id, week_start) row
        ->and($entry->reason)->toBe('End of week')
        ->and($entry->content_digest)->not->toBeEmpty()
        ->and($entry->created_at)->not->toBeNull();
});

it('requires a reason, writing nothing without one', function (?string $reason) {
    expect(fn () => sendReport($this->leader, $this->section->id, $this->moment, reason: $reason))
        ->toThrow(ReasonRequiredException::class);

    expect(WeeklyReport::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('makes no external call of any kind — the send is purely internal (AD-10)', function () {
    Http::preventStrayRequests();
    MockClient::global([]); // any Saloon request would find no mocked response and fail the test

    sendReport($this->leader, $this->section->id, $this->moment);

    Http::assertNothingSent();
    expect(WeeklyReport::query()->sole()->status)->toBe(WeeklyReportStatus::Sent);
});

arch('the send Command has no route to an external system (AD-10)')
    ->expect('App\Commands\WeeklyReports')
    ->not->toUse(['App\Integration', 'Saloon', 'Illuminate\Support\Facades\Http', 'Illuminate\Http\Client']);

it('keeps showing the frozen snapshot once attendance changes after the send (AD-12)', function () {
    sendReport($this->leader, $this->section->id, $this->moment);
    $frozen = WeeklyReport::query()->sole()->kpis;

    sendMark($this->section, $this->troopers[1], $this->weekStart, AttendanceStatus::Makeup);
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);

    $reopened = app(WeeklyReportRepository::class)->find($this->section->id, $this->weekStart);
    expect($reopened->kpis)->toBe($frozen)
        ->and(ReportSnapshot::fromArray($reopened->kpis)->kpis)
        ->not->toEqual(app(KpiService::class)->forUnit($this->section, $this->weekStart));
});

it('treats a re-send as a clean no-op: same row, same snapshot and digest, no second audit entry', function () {
    $first = sendReport($this->leader, $this->section->id, $this->moment, notes: 'original');
    $before = WeeklyReport::query()->sole()->getAttributes();
    sendMark($this->section, $this->troopers[1], $this->weekStart, AttendanceStatus::Makeup); // live numbers move

    $again = sendReport($this->leader, $this->section->id, $this->moment, reason: 'Sending again', notes: 'changed my mind');

    expect($again->alreadySent)->toBeTrue()
        ->and($again->report->id)->toBe($first->report->id)
        ->and(WeeklyReport::query()->sole()->getAttributes())->toBe($before)
        ->and(AuditLogEntry::query()->count())->toBe(1);
});

it('still requires a reason on a re-send', function () {
    sendReport($this->leader, $this->section->id, $this->moment);

    sendReport($this->leader, $this->section->id, $this->moment, reason: '');
})->throws(ReasonRequiredException::class);

it('no-ops cleanly when a concurrent send freezes the report first', function () {
    $racer = User::factory()->create();
    // The racer's send commits between this send's "already sent?" check and its own freeze.
    app()->instance(WeeklyReportRepository::class, new class($racer->id) extends EloquentWeeklyReportRepository
    {
        private bool $raced = false;

        public function __construct(private readonly int $racerId) {}

        public function find(int $unitId, CarbonInterface $weekStart): ?WeeklyReport
        {
            $found = parent::find($unitId, $weekStart);

            if (! $this->raced && $found === null) {
                $this->raced = true;
                parent::send($unitId, $weekStart, ['racer' => true], 'racer notes', $this->racerId, 'racer-digest', null);
            }

            return $found;
        }
    });

    $result = sendReport($this->leader, $this->section->id, $this->moment);

    $report = WeeklyReport::query()->sole();
    expect($result->alreadySent)->toBeTrue()
        ->and($result->report->id)->toBe($report->id)
        ->and($report->sent_by)->toBe($racer->id)
        ->and($report->content_digest)->toBe('racer-digest')
        ->and(AuditLogEntry::query()->count())->toBe(0); // the losing send aborted before writing its audit entry
});

it('computes the frozen snapshot after taking the report-row lock, inside the send\'s transaction', function () {
    $late = $this->troopers[1];
    $section = $this->section;
    // A rollcall save that commits while the send waits for the report-row lock: it is the last
    // thing to happen before the lock is granted, so the frozen numbers must include it.
    $repo = new class($section, $late) extends EloquentWeeklyReportRepository
    {
        public ?int $lockedAtLevel = null;

        public function __construct(private readonly Unit $section, private readonly Trooper $late) {}

        public function lockForSend(int $unitId, CarbonInterface $weekStart): void
        {
            sendMark($this->section, $this->late, $weekStart, AttendanceStatus::Makeup);
            $this->lockedAtLevel = DB::transactionLevel();

            parent::lockForSend($unitId, $weekStart);
        }
    };
    app()->instance(WeeklyReportRepository::class, $repo);
    $outside = DB::transactionLevel(); // RefreshDatabase's own wrapping transaction

    $result = sendReport($this->leader, $this->section->id, $this->moment);

    $frozen = ReportSnapshot::fromArray($result->report->kpis);
    expect($repo->lockedAtLevel)->toBeGreaterThan($outside) // taken inside the audited transaction
        ->and($frozen->kpis)->toEqual(app(KpiService::class)->forUnit($this->section, $this->weekStart))
        ->and($frozen->kpis->makeups)->toBe(1); // the late save is in the frozen numbers
});

it('rethrows rather than pass off a draft as the sent report when the race re-read is not sent', function () {
    $real = app(WeeklyReportRepository::class);
    // A re-read that cannot see the winner (a stale snapshot inside a caller's transaction) comes
    // back as the old draft; the handler must not report that as "already sent".
    $real->saveDraft($this->section->id, $this->weekStart, ['draft' => true], 'draft');
    app()->instance(WeeklyReportRepository::class, new class extends EloquentWeeklyReportRepository
    {
        public function send(int $unitId, CarbonInterface $weekStart, array $kpis, ?string $notes, int $sentBy, string $contentDigest, ?int $templateVersionId, ?string $trainingSubject = null, array $unaccounted = []): WeeklyReport
        {
            throw WeeklyReportAlreadySentException::for($unitId, $weekStart);
        }
    });

    sendReport($this->leader, $this->section->id, $this->moment);
})->throws(WeeklyReportAlreadySentException::class);

it('rejects a user with no scope over the unit, writing nothing', function () {
    expect(fn () => sendReport(User::factory()->create(), $this->section->id, $this->moment))
        ->toThrow(SendWeeklyReportForbiddenException::class);

    expect(WeeklyReport::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('rejects the leader of a sibling section', function () {
    $sibling = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '700/702/']);
    $siblingLeader = User::factory()->commanding($sibling)->create();

    sendReport($siblingLeader, $this->section->id, $this->moment);
})->throws(SendWeeklyReportForbiddenException::class);

it('reports the out-of-scope rejection as an authorization failure (a 403 for any caller)', function () {
    expect(new SendWeeklyReportForbiddenException($this->section->id))->toBeInstanceOf(AuthorizationException::class);
});

it('rejects an out-of-scope re-send without revealing the sent report', function () {
    sendReport($this->leader, $this->section->id, $this->moment);

    sendReport(User::factory()->create(), $this->section->id, $this->moment);
})->throws(SendWeeklyReportForbiddenException::class);

it('lets a leader whose command sits higher up the tree send the section\'s report', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();

    expect(sendReport($platoonLeader, $this->section->id, $this->moment)->alreadySent)->toBeFalse();
});

it('lets an admin send any unit\'s report', function () {
    expect(sendReport(User::factory()->admin()->create(), $this->section->id, $this->moment)->report->status)
        ->toBe(WeeklyReportStatus::Sent);
});

it('refuses a unit that does not exist, even for an admin (no unit is in anyone\'s scope)', function () {
    sendReport(User::factory()->admin()->create(), 999999, $this->moment);
})->throws(SendWeeklyReportForbiddenException::class);

it('sends a non-leaf report as the roll-up of its sent children, freezing the ones it went without (5.7)', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();
    $sent = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '700/702/']);
    $sentKpis = new UnitKpis(4, new Ratio(12, 16), new Ratio(3, 4), 1, 0, 1);
    app(WeeklyReportRepository::class)->send($sent->id, $this->weekStart, (new ReportSnapshot($sentKpis, null))->toArray(), null, $platoonLeader->id, 'd', null);
    app(WeeklyReportRepository::class)->saveDraft($this->section->id, $this->weekStart, [], null); // a still-draft child

    $result = sendReport($platoonLeader, $this->platoon->id, $this->moment);

    $frozen = ReportSnapshot::fromArray($result->report->kpis);
    expect($frozen->kpis)->toEqual($sentKpis) // the draft child's live numbers are never substituted
        ->and($frozen->missingChildren)->toBe([$this->section->id])
        ->and($result->report->status)->toBe(WeeklyReportStatus::Sent);
});

it('refuses a non-leaf unit with no children to roll up, writing nothing', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company, 'path' => '800/']);

    expect(fn () => sendReport(User::factory()->admin()->create(), $company->id, $this->moment))
        ->toThrow(ReportKpisUnavailableException::class);

    expect(WeeklyReport::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('freezes the training subject and the unaccounted reasons, and digests them', function () {
    $result = app(SendWeeklyReportHandler::class)->handle($this->leader, new SendWeeklyReport(
        $this->section->id, $this->moment, 'Weekly report', 'Notes.', '  Room clearing  ',
        [['trooper_id' => $this->troopers[1]->id, 'reason' => 'sick']],
    ));

    $report = $result->report->fresh();
    expect($report->training_subject)->toBe('Room clearing')
        ->and($report->unaccounted)->toBeIgnoringKeyOrder([['trooper_id' => $this->troopers[1]->id, 'reason' => 'sick']])
        ->and($report->content_digest)->toBe(digestOfStored($report))
        ->and($report->content_digest)->not->toBe(SendWeeklyReportHandler::contentDigest(
            $report->unit_id, $report->week_start, $report->kpis, $report->notes, null,
        ));
});

it('refuses a reason for a trooper outside the section, or an unknown reason, writing nothing', function (Closure $row) {
    expect(fn () => app(SendWeeklyReportHandler::class)->handle($this->leader, new SendWeeklyReport(
        $this->section->id, $this->moment, 'Weekly report', null, null, [$row($this)],
    )))->toThrow(InvalidReportContentException::class);

    expect(WeeklyReport::query()->exists())->toBeFalse();
})->with([
    'trooper elsewhere' => [fn ($test) => ['trooper_id' => Trooper::factory()->create()->id, 'reason' => 'sick']],
    'unknown reason' => [fn ($test) => ['trooper_id' => $test->troopers[0]->id, 'reason' => 'bored']],
]);
