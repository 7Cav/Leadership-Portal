<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\ConfirmSectionPractice;
use App\Commands\Attendance\ConfirmSectionPracticeForbiddenException;
use App\Commands\Attendance\ConfirmSectionPracticeHandler;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceForbiddenException;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\MarkRollcallPosted;
use App\Commands\Attendance\MarkRollcallPostedForbiddenException;
use App\Commands\Attendance\MarkRollcallPostedHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Commands\Capture\CaptureRetryForbiddenException;
use App\Commands\Capture\CaptureRetryRefusedException;
use App\Commands\Capture\CaptureRetryThrottledException;
use App\Commands\Capture\CaptureTelemetry;
use App\Commands\Capture\PracticeTelemetry;
use App\Commands\Capture\RequestPracticeCapture;
use App\Commands\Capture\RequestPracticeCaptureHandler;
use App\Commands\GameIdentities\DismissUnmatchedPlayer;
use App\Commands\GameIdentities\DismissUnmatchedPlayerHandler;
use App\Commands\GameIdentities\GameIdentityForbiddenException;
use App\Commands\GameIdentities\LinkUnmatchedPlayer;
use App\Commands\GameIdentities\LinkUnmatchedPlayerHandler;
use App\Commands\GameIdentities\UnmatchedPlayerResolutionException;
use App\Jobs\CaptureSectionPracticeJob;
use App\Kpi\KpiService;
use App\Kpi\TrooperAttendance;
use App\Loa\LoaCoverage;
use App\Loa\LoaProvenance;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\GamePlatform;
use App\Models\PracticeCapturePlayer;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Rollcall\TemplateResolver;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The weekly rollcall of one section (Stories 3.7–3.10, binding design: mockups/v2/rollcall-r3.html
 * "Guided week"): who signed the rollcall thread, who attended Section Practice, and the forum post.
 *
 * Every read is gated by the ScopeResolver (AD-8) — a section outside the viewer's scope is a 403,
 * whatever the UI offers. A week is any moment in it (`?week=YYYY-MM-DD`), bucketed by `WeekOf`
 * (AD-11); a future week is never served — there is nothing to mark yet. A week before
 * `WeekOf::floor()` is still shown, read-only (`week.beforeFloor`): its writes are refused.
 *
 * A week whose section report has been sent is locked (AD-12): `show` says so (`locked`) and the
 * screen turns read-only, and `store` refuses any save for it — the rule itself lives in
 * `MarkAttendanceHandler`. Marking the forum post posted (`posted`) stays open: it records when the
 * BBCode went up, not a fact the report froze.
 */
class RollcallController extends Controller
{
    /**
     * The cookie that remembers the section a user last opened, suffixed with their user id so two
     * people sharing a browser keep their own. Encrypted like every portal cookie, so it is only
     * ever a section id the portal set; it is still checked against the scope before it is used.
     */
    private const REMEMBERED_SECTION_COOKIE = 'rollcall_section_';

    /** How long the remembered section is kept: a year, in minutes. */
    private const REMEMBERED_SECTION_MINUTES = 525_600;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly SyncRunRepository $syncRuns,
        private readonly WeeklyReportRepository $reports,
        private readonly KpiService $kpis,
        private readonly PracticeCapturePlayerRepository $capturePlayers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly PracticeTelemetry $telemetry,
        private readonly TemplateResolver $templates,
        private readonly LoaCoverage $loaCoverage,
        private readonly LoaProvenance $loaProvenance,
    ) {}

    /**
     * The rollcall of `$unit` for the requested week. Without a unit, a section leader lands on
     * their own section and anyone whose scope holds exactly one section on that one; everyone
     * else gets the section picker. The section the user last opened in this browser wins over
     * both while it is still in their scope. `?pick=1` always gets the picker (the page's "Choose a
     * section" link). A view-as preview neither follows nor records the remembered section, so it
     * never moves the real user's choice.
     */
    public function show(Request $request, ?int $unit = null): Response|RedirectResponse
    {
        $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);

        $user = $request->user();
        $currentWeek = WeekOf::start(CarbonImmutable::now());
        $weekStart = $this->requestedWeek($request->query('week'), $currentWeek);

        if ($weekStart->greaterThan($currentWeek)) {
            return redirect()->route('rollcall.show', array_filter(['unit' => $unit]));
        }

        $scope = $this->scope->resolve($user);
        $sections = $this->units->sectionsAmong($scope);
        $weekQuery = $weekStart->equalTo($currentWeek) ? [] : ['week' => $weekStart->toDateString()];
        $previewing = $this->scope->activeViewAs($user) !== null;

        if ($unit === null) {
            $landing = match (true) {
                $request->boolean('pick') => null,
                $previewing => $this->landingSection($user, $sections),
                default => $this->rememberedSection($request, $user, $sections) ?? $this->landingSection($user, $sections),
            };

            if ($landing !== null) {
                return redirect()->route('rollcall.show', ['unit' => $landing->id, ...$weekQuery]);
            }

            return Inertia::render('Rollcall', [
                'section' => null,
                'sections' => $this->pickerOptions($sections),
                'week' => $this->weekProps($weekStart, $currentWeek),
            ]);
        }

        // Scope first, so a unit outside it is a 403 whether or not it exists (AD-8).
        abort_unless($scope->contains($unit), 403, 'This section is outside your scope.');
        $section = $this->units->find($unit);
        abort_unless($section?->type === UnitType::Section, 404);

        if (! $previewing) {
            Cookie::queue(self::REMEMBERED_SECTION_COOKIE.$user->id, (string) $section->id, self::REMEMBERED_SECTION_MINUTES);
        }

        return Inertia::render('Rollcall', [
            'section' => $this->sectionProps($section, $sections),
            'sections' => $this->pickerOptions($sections),
            'week' => $this->weekProps($weekStart, $currentWeek),
            // Whether this viewer may mark, confirm or post here: command authority, not a staff grant, which only
            // reads (the handlers refuse the write either way). The page goes read-only when false.
            'canWrite' => $this->scope->writeScope($user)->contains($section->id),
            // Resolved on every request, never cached, so an Admin's edit or rollback reaches every section under the
            // company on its next load (3.11, AD-17). Marks are data; the template is only how they are written up.
            'template' => $this->templates->forUnit($section)->toArray(),
            ...$this->rosterProps($section, $weekStart),
        ]);
    }

    /**
     * Autosave from the rollcall screen (3.8): a batch of per-trooper changes — a practice mark, a
     * rollcall signature, or both — written through the audited `MarkAttendance` Command, the one
     * writer of record (AD-16, AD-22). "Mark all present" is the same batch. Answers JSON, since
     * the page saves in the background and must keep the leader's input on a failure: 422 for a
     * batch nobody may record, 403 outside scope (AD-8), 409 with `locked` once the section's
     * report for the week has been sent (AD-12) — any batch, even an empty one. On success it
     * returns the at-risk flags recomputed by the KPI service (AD-21), so the review step reflects
     * the saved marks.
     */
    public function store(Request $request, int $unit, MarkAttendanceHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'marks' => ['present', 'array', 'max:500'],
            'marks.*.trooper_id' => ['required', 'integer', 'distinct'],
            'marks.*.status' => ['nullable', Rule::in(array_map(fn (AttendanceStatus $s) => $s->value, [
                AttendanceStatus::Present, AttendanceStatus::Missed, AttendanceStatus::Makeup, AttendanceStatus::Attached,
            ]))],
            'marks.*.rollcall' => ['nullable', Rule::enum(RollcallSignature::class)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));

        if ($weekStart->greaterThan(WeekOf::start(CarbonImmutable::now()))) {
            return response()->json(['message' => 'A future week cannot be marked yet.'], 422);
        }

        try {
            $marks = array_map(fn (array $mark) => new AttendanceMark(
                trooperId: (int) $mark['trooper_id'],
                status: isset($mark['status']) ? AttendanceStatus::from($mark['status']) : null,
                rollcall: isset($mark['rollcall']) ? RollcallSignature::from($mark['rollcall']) : null,
            ), $request->input('marks', []));

            // Scope is checked first, by the handler: a unit outside it — existing or not — is a
            // 403, so ids beyond the leader's scope are not probeable (AD-8).
            $handler->handle($request->user(), new MarkAttendance($unit, $weekStart, ...$marks));
        } catch (MarkAttendanceForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidAttendanceMarkException $e) {
            // `rejected` names the troopers the batch was refused for, and `reason` why: moved out of
            // the section since the page loaded (`not_placed`), or on leave that covers the week
            // (`loa_covered`, a leave synced after the page loaded). The screen keeps saving everyone
            // else's marks, and a covered trooper's signature.
            return response()->json(['message' => $e->getMessage(), 'rejected' => $e->trooperIds, 'reason' => $e->reason], 422);
        } catch (RollcallWeekLockedException $e) {
            // The week's report was sent (AD-12), perhaps while this screen was open. A 409 with
            // `locked`, not a 422: no trooper can be dropped to make the batch acceptable, so the
            // screen stops saving and shows the week read-only instead of retrying.
            return response()->json(['message' => $e->getMessage(), 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving this change.'], 500);
        }

        // The marks are committed. The at-risk refresh is a convenience on top: if it fails, the
        // save still answers as saved — never as failed, which would make the screen resend it.
        try {
            $atRisk = $this->atRisk($this->units->find($unit), $weekStart);
        } catch (Throwable $e) {
            report($e);
            $atRisk = null;
        }

        // A practice-mark save clears the confirmation (7.8); the screen takes the state from here
        // rather than guessing, so a refused save cannot leave the badge wrong.
        try {
            $confirmation = ['confirmation' => $this->confirmationProps($this->practices->find($unit, $weekStart))];
        } catch (Throwable $e) {
            report($e);
            $confirmation = [];
        }

        return response()->json([
            'savedAt' => CarbonImmutable::now()->toIso8601String(),
            'atRisk' => $atRisk,
            ...$confirmation,
        ]);
    }

    /**
     * The leader has posted the week's BBCode to the forum thread and says so (3.10). The portal
     * itself never posts (AD-20); this only records when, and who, through the audited
     * `MarkRollcallPosted` Command. Marking again after edits is allowed — the latest wins.
     */
    public function posted(Request $request, int $unit, MarkRollcallPostedHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), ['week' => ['required', 'date_format:Y-m-d']]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $currentWeek = WeekOf::start(CarbonImmutable::now());
        $weekStart = $this->requestedWeek($request->input('week'), $currentWeek);

        if ($weekStart->greaterThan($currentWeek)) {
            return response()->json(['message' => 'A future week cannot be posted yet.'], 422);
        }

        try {
            $practice = $handler->handle($request->user(), new MarkRollcallPosted($unit, $weekStart));
        } catch (MarkRollcallPostedForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidAttendanceMarkException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error marking the rollcall posted.'], 500);
        }

        return response()->json(['posted' => $this->postedProps($practice)]);
    }

    /**
     * The leader confirms the practice's marks as reviewed (Story 7.8): one audited Command,
     * scope-gated (AD-8). Marking it again re-stamps. Answers the confirmation for the badge.
     * `confirmedAt` is the confirmation the screen showed (null for none): one the leader never
     * saw is answered back as it is rather than stamped over. Without it, every request re-stamps.
     */
    public function confirm(Request $request, int $unit, ConfirmSectionPracticeHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'confirmedAt' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $currentWeek = WeekOf::start(CarbonImmutable::now());
        $weekStart = $this->requestedWeek($request->input('week'), $currentWeek);

        if ($weekStart->greaterThan($currentWeek)) {
            return response()->json(['message' => 'A future week cannot be confirmed yet.'], 422);
        }

        try {
            $seen = match (true) {
                ! $request->exists('confirmedAt') => false,
                $request->input('confirmedAt') === null => null,
                default => CarbonImmutable::parse($request->input('confirmedAt'))->toDateTimeImmutable(),
            };
            $practice = $handler->handle($request->user(), new ConfirmSectionPractice($unit, $weekStart, $seen));
        } catch (ConfirmSectionPracticeForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidAttendanceMarkException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error confirming the practice.'], 500);
        }

        return response()->json(['confirmation' => $this->confirmationProps($practice)]);
    }

    /**
     * "Try again" on the telemetry-unavailable banner (Story 7.9): queue a fresh capture of the
     * practice, as the leader, through {@see RequestPracticeCaptureHandler}. Answers 202 once it is
     * queued — the run is asynchronous (AD-23), so the screen polls the practice's state. A refusal
     * is spoken, never swallowed: 403 outside scope, 422 when nothing could be attempted (with the
     * reason), 429 with `retryAfter` when it was asked a moment ago.
     */
    public function retryCapture(Request $request, int $unit, RequestPracticeCaptureHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), ['week' => ['required', 'date_format:Y-m-d']]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $currentWeek = WeekOf::start(CarbonImmutable::now());
        $weekStart = $this->requestedWeek($request->input('week'), $currentWeek);

        if ($weekStart->greaterThan($currentWeek)) {
            return response()->json(['message' => 'A future week cannot be captured yet.'], 422);
        }

        if (WeekOf::isBeforeFloor($weekStart)) {
            return response()->json(['message' => 'That week is too far back to capture.'], 422);
        }

        try {
            $capture = $handler->handle($request->user(), new RequestPracticeCapture($unit, $weekStart));
        } catch (CaptureRetryForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (CaptureRetryThrottledException $e) {
            return response()->json(['message' => $e->getMessage(), 'retryAfter' => $e->retryAfter], 429);
        } catch (CaptureRetryRefusedException|InvalidAttendanceMarkException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error requesting the capture.'], 500);
        }

        try {
            CaptureSectionPracticeJob::dispatch($capture);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The capture could not be queued. Try again in a moment, or mark attendance by hand.'], 500);
        }

        return response()->json(['queued' => true], 202);
    }

    /**
     * A leader links an unmatched player to the trooper they chose (Story 7.7, AD-19): the identity
     * is written and the trooper pre-marked present through audited Commands, scope-gated inside
     * the handler (AD-8). Answers JSON for the drawer: the trooper's resulting mark (a manual one is
     * kept, so `marked` can be false) and the refreshed at-risk flags. 422 carries the readable
     * reason inline — including "Already linked to <trooper> on <game>".
     */
    public function linkUnmatched(Request $request, int $unit, LinkUnmatchedPlayerHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'platform' => ['required', Rule::enum(GamePlatform::class)],
            'platform_id' => ['required', 'string', 'max:255'],
            'trooper_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));
        $platform = GamePlatform::from($request->input('platform'));
        $trooperId = (int) $request->input('trooper_id');

        try {
            $result = $handler->handle($request->user(), new LinkUnmatchedPlayer($unit, $weekStart, $platform, $request->input('platform_id'), $trooperId));
        } catch (GameIdentityForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UnmatchedPlayerResolutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (GameIdentityLinkException) {
            return response()->json(['message' => $this->collisionMessage($platform, $request->input('platform_id'))], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error linking this player. Reload to see whether the link was saved, and check their mark.'], 500);
        }

        // The link and the mark are committed; what follows only refreshes the screen, so a failure
        // here is reported and answered as saved, never as a failed link the leader would repeat.
        try {
            $practice = $this->practices->find($unit, $weekStart);
            $mark = $practice ? $this->attendance->forPractice($practice)->firstWhere('trooper_id', $trooperId) : null;
        } catch (Throwable $e) {
            report($e);
            $mark = null;
        }

        try {
            $atRisk = $this->atRisk($this->units->find($unit), $weekStart);
        } catch (Throwable $e) {
            report($e);
            $atRisk = null;
        }

        return response()->json([
            'marked' => $result->marked > 0,
            'trooper' => ['id' => $trooperId, 'status' => $mark?->status?->value, 'source' => $mark?->source?->value],
            'atRisk' => $atRisk,
        ]);
    }

    /**
     * A leader dismisses an unmatched player as not-a-member (Story 7.7): one audited Command,
     * scope-gated (AD-8). The player stays off this and later unmatched lists until linked.
     */
    public function dismissUnmatched(Request $request, int $unit, DismissUnmatchedPlayerHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'platform' => ['required', Rule::enum(GamePlatform::class)],
            'platform_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));

        try {
            $handler->handle($request->user(), new DismissUnmatchedPlayer($unit, $weekStart, GamePlatform::from($request->input('platform')), $request->input('platform_id')));
        } catch (GameIdentityForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UnmatchedPlayerResolutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error dismissing this player.'], 500);
        }

        return response()->json(['dismissed' => true]);
    }

    /**
     * "Already linked to SGT Ortega.M on Hell Let Loose." — the owner is named so the leader knows
     * whose row to fix (as on the link-identity screen, 7.2).
     */
    private function collisionMessage(GamePlatform $platform, string $platformId): string
    {
        $owner = $this->identities->findByPlatformId($platform, trim($platformId));
        $name = $owner === null ? null : $this->troopers->find($owner->trooper_id)?->display_name;

        return 'Already linked to '.($name ?? 'another trooper')." on {$platform->label()}.";
    }

    /**
     * @return array{at: string, by: ?string}|null
     */
    private function postedProps(?SectionPractice $practice): ?array
    {
        if ($practice?->rollcall_posted_at === null) {
            return null;
        }

        return [
            'at' => $practice->rollcall_posted_at->toIso8601String(),
            'by' => $practice->rollcallPostedBy?->username,
        ];
    }

    /**
     * @return array{at: string, by: ?string}|null
     */
    private function confirmationProps(?SectionPractice $practice): ?array
    {
        if ($practice?->confirmed_at === null) {
            return null;
        }

        return ['at' => $practice->confirmed_at->toIso8601String(), 'by' => $practice->confirmedBy?->username];
    }

    private function requestedWeek(?string $week, CarbonImmutable $currentWeek): CarbonImmutable
    {
        return $week === null ? $currentWeek : WeekOf::start(CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC'));
    }

    /**
     * The section this user last opened in this browser, when it is still one of `$sections` (their
     * scope); null otherwise.
     *
     * @param  Collection<int, Unit>  $sections
     */
    private function rememberedSection(Request $request, User $user, Collection $sections): ?Unit
    {
        $id = (int) $request->cookie(self::REMEMBERED_SECTION_COOKIE.$user->id);

        return $id > 0 ? $sections->firstWhere('id', $id) : null;
    }

    /**
     * The section a user opens the rollcall on without choosing: the one their billet commands, or
     * the only section in their scope. Null leaves the choice to the picker.
     *
     * @param  Collection<int, Unit>  $sections  the sections in the user's scope
     */
    private function landingSection(User $user, Collection $sections): ?Unit
    {
        $commanded = $this->commands->commandedUnitFor($user);

        if ($commanded !== null && $sections->contains('id', $commanded->id)) {
            return $commanded;
        }

        return $sections->count() === 1 ? $sections->first() : null;
    }

    /**
     * @param  Collection<int, Unit>  $sections
     * @return list<array<string, mixed>>
     */
    private function pickerOptions(Collection $sections): array
    {
        return $sections->map(fn (Unit $section) => [
            'id' => $section->id,
            'designation' => $section->callsign ?? $section->name,
            'name' => $section->name,
            'platoon' => $section->parent ? ($section->parent->callsign ?? $section->parent->name) : null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Unit>  $sections
     * @return array<string, mixed>
     */
    private function sectionProps(Unit $section, Collection $sections): array
    {
        $parent = $sections->firstWhere('id', $section->id)?->parent;

        return [
            'id' => $section->id,
            'designation' => $section->callsign ?? $section->name,
            'name' => $section->name,
            'platoon' => $parent ? ($parent->callsign ?? $parent->name) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weekProps(CarbonImmutable $weekStart, CarbonImmutable $currentWeek): array
    {
        return [
            // Saturday 00:00 UTC (AD-11); the rollcall thread's signatures close the Friday after.
            'start' => $weekStart->toIso8601String(),
            'date' => $weekStart->toDateString(),
            'closes' => $weekStart->addWeek()->subMinute()->toIso8601String(),
            'isCurrent' => $weekStart->equalTo($currentWeek),
            'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->lessThan($currentWeek) ? $weekStart->addWeek()->toDateString() : null,
            'current' => $currentWeek->toDateString(),
            // Older than the week floor: shown read-only, every write for it is refused.
            'beforeFloor' => WeekOf::isBeforeFloor($weekStart),
        ];
    }

    /**
     * The section's troopers with this week's marks, last week's practice marks (the pre-fill
     * suggestion, 3.7), each trooper's leave for the week from `LoaCoverage` (3.4, 4.4), the at-risk
     * flags from the shared KPI service (AD-21), and when the roster and the leave data they come
     * from were last synced (AD-20).
     *
     * @return array<string, mixed>
     */
    private function rosterProps(Unit $section, CarbonImmutable $weekStart): array
    {
        $practice = $this->practices->find($section->id, $weekStart);
        $marks = $practice ? $this->attendance->forPractice($practice)->keyBy('trooper_id') : collect();

        $priorPractice = $this->practices->find($section->id, $weekStart->subWeek());
        $prior = $priorPractice
            ? $this->attendance->forPractice($priorPractice)->filter(fn (Attendance $row) => $row->status !== null)->keyBy('trooper_id')
            : collect();

        $roster = $this->troopers->placedIn($section->id);
        $lastSync = $this->syncRuns->latestSuccessful('roster');
        // The one LOA definition (4.4, AD-21), the same the Command refuses a practice mark by: a covered trooper's
        // status is derived as `loa`, so the screen locks the row and never sends one for them (3.4).
        $covers = $this->loaCoverage->forTroopers($roster->modelKeys(), $weekStart);

        return [
            'troopers' => $roster->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'cavId' => $trooper->cav_id,
                // The synced forum identity (2.10): `rankShort` + `name` render as `displayName`
                // ("SGT Vasquez.R", "SGT #1234" until the username syncs).
                'rank' => $trooper->rank,
                'rankShort' => $trooper->rank_short,
                'name' => $trooper->username,
                'displayName' => $trooper->display_name,
                'rollcall' => $marks->get($trooper->id)?->rollcall->value ?? 'unsigned',
                'status' => $marks->get($trooper->id)?->status?->value,
                'source' => $marks->get($trooper->id)?->source?->value,
                'priorStatus' => $prior->get($trooper->id)?->status->value,
                // `LoaCover::toArray()` for a trooper with leave this week (covered or needs review), the badge's
                // shape; null with none. A leave that needs review derives no status, so that row stays markable.
                'loa' => $covers[$trooper->id]->suppressesAtRisk() ? $covers[$trooper->id]->toArray() : null,
            ])->sortBy(fn (array $row) => mb_strtolower($row['name'] ?? "~{$row['cavId']}"))->values()->all(),
            'priorWeekMarked' => $prior->isNotEmpty(),
            // Where the leave data comes from and how fresh it is (4.3, AD-20), shown with every LOA badge.
            'loaSync' => $this->loaProvenance->current()->toArray(),
            // The section's real report for the week was sent (AD-12): the rollcall is read-only.
            // A draft, no report, or a generated demo report (#115) leaves it open.
            'locked' => $this->reports->isSentFor($section->id, $weekStart),
            'atRisk' => $this->atRisk($section, $weekStart),
            'roster' => [
                'source' => 'milpac',
                'syncedAt' => $lastSync?->finished_at?->toIso8601String(),
            ],
            ...$this->captureProps($practice),
            'telemetry' => $this->telemetryProps($section->id, $practice),
            'practice' => [
                'lastMarkedAt' => $marks->max(fn (Attendance $row) => $row->updated_at)?->toIso8601String(),
                'posted' => $this->postedProps($practice),
                'confirmation' => $this->confirmationProps($practice),
            ],
        ];
    }

    /**
     * What the capture run left for the leader (7.7): the players no trooper is linked to, once the
     * practice has been captured. `capture` drives the capture banner (7.8); the drawer renders only
     * when there are players. A practice with no capture gets `telemetry` instead (7.9).
     *
     * @return array{capture: ?array{source: string, capturedAt: string}, unmatched: list<array<string, string>>}
     */
    private function captureProps(?SectionPractice $practice): array
    {
        if ($practice?->captured_at === null) {
            return ['capture' => null, 'unmatched' => []];
        }

        return [
            'capture' => ['source' => $practice->capture_source, 'capturedAt' => $practice->captured_at->toIso8601String()],
            'unmatched' => $this->capturePlayers->unmatchedFor($practice->id)->map(fn (PracticeCapturePlayer $player) => [
                'platform' => $player->platform->value,
                'platformLabel' => $player->platform->label(),
                'playerName' => $player->player_name,
                'platformId' => $player->platform_id,
            ])->values()->all(),
        ];
    }

    /**
     * Why the practice has no server data, when it has none (7.9, AD-20): `state` is `not_configured`
     * (the game's env keys are unset, nothing was attempted) or `failed` (the last capture found no
     * source that could answer, at `lastAttemptAt`). Null when the practice was captured or never
     * attempted. The failure is never read as an empty practice: the marks stay the manual ones.
     *
     * @return array{state: string, platformLabel: ?string, lastAttemptAt: ?string, canRetry: bool}|null
     */
    private function telemetryProps(int $sectionId, ?SectionPractice $practice): ?array
    {
        $state = $this->telemetry->stateFor($sectionId, $practice);

        return $state instanceof CaptureTelemetry ? [
            'state' => $state->state->value,
            'platformLabel' => $state->platform?->label(),
            'lastAttemptAt' => $state->lastAttemptAt?->format(DATE_ATOM),
            'canRetry' => $state->canRetry,
        ] : null;
    }

    /**
     * The section's at-risk troopers this week (AD-21's `TrooperAttendance::atRisk()`), with their
     * misses in the trailing window — computed by the KPI service, never here.
     *
     * @return list<array{trooperId: int, missed: int, windowWeeks: int}>
     */
    private function atRisk(Unit $section, CarbonImmutable $weekStart): array
    {
        return $this->kpis->trooperAttendance($section, $weekStart)
            ->filter(fn (TrooperAttendance $row) => $row->atRisk())
            ->map(fn (TrooperAttendance $row) => [
                'trooperId' => $row->trooperId,
                'missed' => $row->missed,
                'windowWeeks' => KpiService::WINDOW_WEEKS,
            ])->values()->all();
    }
}
