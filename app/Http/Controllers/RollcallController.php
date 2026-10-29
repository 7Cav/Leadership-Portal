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
use App\Commands\GameIdentities\GameRetiredException;
use App\Commands\GameIdentities\InvalidGameIdentityException;
use App\Commands\GameIdentities\LinkUnmatchedPlayer;
use App\Commands\GameIdentities\LinkUnmatchedPlayerHandler;
use App\Commands\GameIdentities\RestoreDismissedPlayer;
use App\Commands\GameIdentities\RestoreDismissedPlayerHandler;
use App\Commands\GameIdentities\UnmatchedPlayerResolutionException;
use App\Jobs\CaptureSectionPracticeJob;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Kpi\TrooperAttendance;
use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Loa\LoaProvenance;
use App\Loa\UpcomingLeave;
use App\Milpac\RosterProvenance;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Game;
use App\Models\GameIdentityText;
use App\Models\PracticeCapturePlayer;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\GameRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitDesignationOrder;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Rollcall\RollcallGuests;
use App\Rollcall\RollcallRoster;
use App\Rollcall\SectionFireteams;
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
 * screen turns read-only, and `store`, `confirm`, `posted` and `retryCapture` refuse it with a
 * 409 and `locked` (the rule itself lives in their handlers; a capture run and a link's pre-mark
 * skip the week the same way).
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

    /**
     * How many past weeks the week navigation flags when they still need work: the KPI window, the
     * weeks whose marks still feed the at-risk flags (AD-21).
     */
    private const STATUS_WEEKS = KpiService::WINDOW_WEEKS;

    /** Completed Cav weeks each trooper card's attendance strip shows: the ones before the week being viewed. */
    public const HISTORY_WEEKS = 4;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly RosterProvenance $rosterSync,
        private readonly WeeklyReportRepository $reports,
        private readonly KpiService $kpis,
        private readonly PracticeCapturePlayerRepository $capturePlayers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly PracticeTelemetry $telemetry,
        private readonly TemplateResolver $templates,
        private readonly LoaCoverage $loaCoverage,
        private readonly LoaProvenance $loaProvenance,
        private readonly RollcallRoster $roster,
        private readonly SectionFireteams $fireteams,
        private readonly RollcallGuests $guests,
        private readonly GameRepository $games,
        private readonly Headcount $headcount,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly UpcomingLeave $upcomingLeave,
    ) {}

    /**
     * The rollcall of `$unit` for the requested week. Without a unit, a section leader lands on
     * their own section and anyone whose scope holds exactly one section on that one; everyone
     * else gets the section picker. The section the user last opened in this browser wins over
     * both while it is still in their scope. `?pick=1` always gets the picker (the page's "Choose a
     * section" link). A view-as preview neither follows nor records the remembered section, so it
     * never moves the real user's choice.
     *
     * Without `?week`, a section opens on the week whose report is still due ({@see WeekOf::reporting()}) while
     * that section has not sent it: on the Saturday after a week ends, the leader lands on the week they still
     * have to finish, not on the new empty one. Once the report is sent, or the deadline passes, the bare URL
     * opens the running week. An explicit `?week` always wins, so "This week" still reaches the new week.
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
        // A week the leader named is kept on the redirect, even the running one: without it the section would open
        // on a report still due instead.
        $weekQuery = $request->query('week') === null ? [] : ['week' => $weekStart->toDateString()];
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
                'sections' => $this->pickerOptions($sections, $weekStart),
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

        $reportingWeek = WeekOf::reporting(CarbonImmutable::now());

        if ($request->query('week') === null && $reportingWeek->lessThan($currentWeek) && ! $this->reports->isSentFor($section->id, $reportingWeek)) {
            return redirect()->route('rollcall.show', [...$request->query(), 'unit' => $section->id, 'week' => $reportingWeek->toDateString()]);
        }

        return Inertia::render('Rollcall', [
            'section' => $this->roster->section($section, $sections),
            'sections' => $this->pickerOptions($sections, $weekStart),
            'week' => $this->weekProps($weekStart, $currentWeek),
            // Whether this viewer may mark, confirm or post here: command authority, not a staff grant, which only
            // reads (the handlers refuse the write either way). The page goes read-only when false.
            'canWrite' => $this->scope->writeScope($user)->contains($section->id),
            // Resolved on every request, never cached, so a company's edit or rollback reaches every section using that
            // template on its next load (3.11, AD-17). Marks are data; the template is only how they are written up.
            'template' => $this->templates->forUnit($section)->toArray(),
            // The templates this section may render from and its leader's pick (#375); picking is a write, so the page
            // offers it only with `canWrite`.
            'templatePicker' => $this->templates->pickerFor($section),
            'report' => $this->reportProps($section, $weekStart),
            ...$this->rosterProps($section, $weekStart, $currentWeek),
            'fireteams' => $this->fireteamProps($user, $section),
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

            return response()->json(['message' => 'The portal hit an error saving this change. Try again in a moment.'], 500);
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
     * `MarkRollcallPosted` Command. Marking again after edits is allowed — the latest wins. A week
     * whose report was sent is refused with a 409 and `locked`, like the other rollcall writes (AD-12).
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
        } catch (RollcallWeekLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error marking the rollcall posted. Try again in a moment.'], 500);
        }

        return response()->json(['posted' => $this->postedProps($practice)]);
    }

    /**
     * The leader confirms the practice's marks as reviewed (Story 7.8): one audited Command,
     * scope-gated (AD-8). Marking it again re-stamps. Answers the confirmation for the badge, or a
     * 409 with `locked` once the section's report for the week has been sent (AD-12).
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
        } catch (RollcallWeekLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error confirming the practice. Try again in a moment.'], 500);
        }

        return response()->json(['confirmation' => $this->confirmationProps($practice)]);
    }

    /**
     * "Try again" on the telemetry-unavailable banner (Story 7.9): queue a fresh capture of the
     * practice, as the leader, through {@see RequestPracticeCaptureHandler}. Answers 202 once it is
     * queued — the run is asynchronous (AD-23), so the screen polls the practice's state. A refusal
     * is spoken, never swallowed: 403 outside scope, 422 when nothing could be attempted or the
     * practice has no failed capture to retry (with the reason), 409 with `locked` for a week whose
     * report was sent, 429 with `retryAfter` when it was asked a moment ago. The route is also
     * throttled per user (`throttle:practice-capture`), since every queued run reads an external API.
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
        } catch (RollcallWeekLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error requesting the capture. Try again in a moment.'], 500);
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
     * reason inline — including "Already linked to <trooper> on <game>". A trooper who already holds
     * another identity on that game is a 422 with `reason: has_other_identity` and that identity in
     * `current`, which the drawer turns into "replace or cancel"; the leader's "Replace" sends the
     * same request again with `replace: true` and `replacing`, the platform id it was shown (#176).
     * If the trooper's identity changed since, the answer is the same 422 with the new `current`,
     * and the drawer asks again; `replace` without `replacing` is refused by validation.
     */
    public function linkUnmatched(Request $request, int $unit, LinkUnmatchedPlayerHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'platform' => ['required', 'string', 'exists:games,slug'],
            'platform_id' => ['required', 'string', 'max:255'],
            'trooper_id' => ['required', 'integer'],
            'replace' => ['sometimes', 'boolean'],
            // A replace must say which identity it replaces (the one the leader was shown), so a
            // change since the question is caught instead of overwritten. Without it the request is
            // refused outright: a client that cannot say what it replaces must not replace anything.
            'replacing' => ['required_if_accepted:replace', 'string', 'max:255'],
        ], [
            'replacing.required_if_accepted' => 'This replace does not say which ID it replaces, so nothing was changed. Reload the page and link the player again.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));
        // Checked by `exists` above; a game deleted since is a 404, answered before the try below would call it a 500.
        $game = $this->games->findBySlug($request->input('platform')) ?? abort(404);
        $trooperId = (int) $request->input('trooper_id');

        try {
            $result = $handler->handle($request->user(), new LinkUnmatchedPlayer(
                $unit, $weekStart, $game, $request->input('platform_id'), $trooperId, $request->boolean('replace'),
                $request->boolean('replace') ? $request->input('replacing') : null,
            ));
        } catch (GameIdentityForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UnmatchedPlayerResolutionException $e) {
            return response()->json(array_filter(
                ['message' => $e->getMessage(), 'reason' => $e->reason, 'current' => $e->current],
                fn ($value) => $value !== null,
            ), 422);
        } catch (GameRetiredException|InvalidGameIdentityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (GameIdentityLinkException) {
            return response()->json(['message' => $this->collisionMessage($game, $request->input('platform_id'))], 422);
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
            'platform' => ['required', 'string', 'exists:games,slug'],
            'platform_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));
        $game = $this->games->findBySlug($request->input('platform')) ?? abort(404);

        try {
            $handler->handle($request->user(), new DismissUnmatchedPlayer($unit, $weekStart, $game, $request->input('platform_id')));
        } catch (GameIdentityForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UnmatchedPlayerResolutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error dismissing this player. Try again in a moment.'], 500);
        }

        return response()->json(['dismissed' => true]);
    }

    /**
     * A leader undoes a dismissal (#176): the toast's Undo and the drawer's "Dismissed" list both
     * land here. One audited Command, scope-gated like the dismissal (AD-8); the player is listed
     * again on this practice and later ones. A player who is not dismissed is a 422 with the reason.
     */
    public function restoreUnmatched(Request $request, int $unit, RestoreDismissedPlayerHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'platform' => ['required', 'string', 'exists:games,slug'],
            'platform_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));
        $game = $this->games->findBySlug($request->input('platform')) ?? abort(404);

        try {
            $handler->handle($request->user(), new RestoreDismissedPlayer($unit, $weekStart, $game, $request->input('platform_id')));
        } catch (GameIdentityForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UnmatchedPlayerResolutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error undoing this dismissal. Try again in a moment.'], 500);
        }

        return response()->json(['restored' => true]);
    }

    /**
     * "Already linked to SGT Ortega.M on Hell Let Loose." — the owner is named so the leader knows
     * whose row to fix (as on the link-identity screen, 7.2).
     */
    private function collisionMessage(Game $game, string $platformId): string
    {
        $owner = $this->identities->findByPlatformId($game, trim($platformId));
        $name = $owner === null ? null : $this->troopers->find($owner->trooper_id)?->display_name;

        return 'Already linked to '.($name ?? 'another trooper')." on {$game->name}.";
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
     * The section a user opens the rollcall on without choosing: the one their billet commands, else
     * one they are acting lead of, else the first section of the first platoon in their scope, in
     * designation order ({@see UnitDesignationOrder}). Only a section with troopers is landed on
     * ({@see Headcount::withoutEmptyLineUnits()}, #426) unless every one in scope is empty; the
     * picker still lists the empty ones. In a person preview the billet and acting leads are the
     * previewed person's ({@see ScopeResolver::landingAccount()}), so the preview opens where they
     * would. Null only when their scope holds no section, which leaves the (empty) picker.
     *
     * @param  Collection<int, Unit>  $sections  the sections in the user's scope
     */
    private function landingSection(User $user, Collection $sections): ?Unit
    {
        $withTroopers = $this->headcount->withoutEmptyLineUnits($sections);
        $candidates = $withTroopers->isEmpty() ? $sections : $withTroopers;

        $account = $this->scope->landingAccount($user);
        $commanded = $account === null ? null : $this->commands->commandedUnitFor($account);
        $own = $commanded === null ? null : $candidates->firstWhere('id', $commanded->id);

        if ($own !== null) {
            return $own;
        }

        $ordered = $candidates->count() <= 1 ? $candidates : UnitDesignationOrder::sort($candidates, $this->units->tree());
        $actingFor = ($account === null ? collect() : $this->actingLeads->unitsLedBy($account))->pluck('id')->flip();

        return $ordered->first(fn (Unit $section) => $actingFor->has($section->id)) ?? $ordered->first();
    }

    /**
     * The section's report for the week shown, for the deadline banner: when it falls due (AD-11,
     * `WeekOf::reportDue`), whether it is sent, where to write it, and when an unsent one stops
     * being called overdue: once the week leaves the KPI window ({@see KpiService::WINDOW_WEEKS}
     * Cav weeks, the current one included).
     *
     * @return array{due: string, overdueUntil: string, sent: bool, href: string}
     */
    private function reportProps(Unit $section, CarbonImmutable $weekStart): array
    {
        return [
            'due' => WeekOf::reportDue($weekStart)->toIso8601String(),
            'overdueUntil' => WeekOf::next($weekStart, KpiService::WINDOW_WEEKS)->toIso8601String(),
            'sent' => $this->reports->find($section->id, $weekStart)?->status === WeeklyReportStatus::Sent,
            'href' => route('reports.show', ['unit' => $section->id, 'week' => $weekStart->toDateString()]),
        ];
    }

    /**
     * The picker's sections ({@see RollcallRoster::options()}), each with where its rollcall stands for the week.
     *
     * @param  Collection<int, Unit>  $sections
     * @return list<array<string, mixed>>
     */
    private function pickerOptions(Collection $sections, CarbonImmutable $weekStart): array
    {
        // Where each section's rollcall stands for the week, so the picker can say which still
        // need work: the counts the home step matrix reads, one query for every section listed. A
        // trooper whose leave covers the week has nothing to sign or mark (3.4): the one LOA
        // definition says who (AD-21), as on the rollcall screen.
        $ids = $sections->modelKeys();
        $covered = array_keys(array_filter(
            $this->loaCoverage->forTroopers($this->troopers->placedInAny($ids)->modelKeys(), $weekStart),
            fn (LoaCover $cover) => $cover->isCovered(),
        ));
        $progress = $this->attendance->weekProgress($ids, $weekStart, $covered);

        return array_map(function (array $option) use ($progress) {
            $week = $progress->get($option['id']);

            return [
                ...$option,
                'progress' => [
                    'strength' => $week?->strength ?? 0,
                    'onLoa' => $week?->onLoa ?? 0,
                    'unsigned' => $week?->unsigned ?? 0,
                    'unmarked' => $week === null ? 0 : $week->strength - $week->marked,
                    'started' => $week !== null && ($week->signed > 0 || $week->marked > $week->onLoa),
                    'posted' => $week?->postedAt !== null,
                ],
            ];
        }, $this->roster->options($sections));
    }

    /**
     * {@see RollcallRoster::week()}, plus whether the week is older than the week floor.
     *
     * @return array<string, mixed>
     */
    private function weekProps(CarbonImmutable $weekStart, CarbonImmutable $currentWeek): array
    {
        return [
            ...$this->roster->week($weekStart, $currentWeek),
            // The week whose report is still due: the page names a week explicitly in its links while this is not
            // the running week, since the bare URL may open this one instead.
            'reporting' => WeekOf::reporting(CarbonImmutable::now())->toDateString(),
            // Older than the week floor: shown read-only, every write for it is refused.
            'beforeFloor' => WeekOf::isBeforeFloor($weekStart),
        ];
    }

    /**
     * The section's troopers with this week's marks, last week's practice marks (the pre-fill
     * suggestion, 3.7), each trooper's leave for the week from `LoaCoverage` (3.4, 4.4), the at-risk
     * flags from the shared KPI service (AD-21), and when the roster and the leave data they come
     * from were last synced (AD-20). The rows come from {@see RollcallRoster}, which the template
     * editor's preview of a real section reads too; the leave and the attendance strip are added here.
     *
     * @return array<string, mixed>
     */
    private function rosterProps(Unit $section, CarbonImmutable $weekStart, CarbonImmutable $currentWeek): array
    {
        $week = $this->roster->forWeek($section, $weekStart);
        $practice = $week->practice;
        $trooperIds = array_column($week->troopers, 'id');
        $history = $this->history($section, collect($trooperIds), $weekStart);
        $lastSync = $this->rosterSync->current();
        // The one LOA definition (4.4, AD-21), the same the Command refuses a practice mark by: a covered trooper's
        // status is derived as `loa`, so the screen locks the row and never sends one for them (3.4).
        $covers = $this->loaCoverage->forTroopers($trooperIds, $weekStart);
        // Leave starting the week after this one, for the leader planning the next practice (read-only).
        $leaveFrom = $this->upcomingLeave->startingAfter($trooperIds, $weekStart);

        return [
            'troopers' => array_map(fn (array $row) => [
                ...$row,
                // `LoaCover::toArray()` for a trooper with leave this week (covered or needs review), the badge's
                // shape; null with none. A leave that needs review derives no status, so that row stays markable.
                'loa' => $covers[$row['id']]->suppressesAtRisk() ? $covers[$row['id']]->toArray() : null,
                'leaveFrom' => $leaveFrom[$row['id']] ?? null,
                'history' => $history[$row['id']],
            ], $week->troopers),
            'priorWeekMarked' => $week->priorWeekMarked,
            // Where the leave data comes from and how fresh it is (4.3, AD-20), shown with every LOA badge.
            'loaSync' => $this->loaProvenance->current()->toArray(),
            // The section's real report for the week was sent (AD-12): the rollcall is read-only.
            // A draft, no report, or a generated demo report (#115) leaves it open.
            'locked' => $this->reports->isSentFor($section->id, $weekStart),
            'weekStatus' => $this->weekStatus($section, $currentWeek, $trooperIds),
            'atRisk' => $this->atRisk($section, $weekStart),
            'roster' => [
                'source' => $lastSync->source,
                'syncedAt' => $lastSync->lastSyncedAt?->toIso8601String(),
            ],
            ...$this->captureProps($practice),
            // Troopers from other sections recorded at this practice (JSAM evidence, 2026-10-23): what the capture saw
            // and who the leader added by hand, for the practice step's "Guests" area.
            'guests' => $this->guests->forPractice($practice),
            'telemetry' => $this->telemetryProps($section->id, $practice),
            'practice' => [
                'lastMarkedAt' => $week->marks->max(fn (Attendance $row) => $row->updated_at)?->toIso8601String(),
                'posted' => $this->postedProps($practice),
                'confirmation' => $this->confirmationProps($practice),
            ],
        ];
    }

    /**
     * Where each of the section's last {@see STATUS_WEEKS} past weeks stands, newest first, so the
     * week navigation can flag the ones still needing work: `unmarked` troopers placed in the section
     * now with no practice mark (LOA counts as marked, as on the screen: a trooper whose leave covers
     * the week, by `LoaCoverage`, has nothing to mark), and whether the rollcall is `posted` with no
     * mark changed since. A week with no practice row has everyone not on leave unmarked and nothing
     * posted. An empty roster has nothing to do, so it sends no weeks.
     *
     * @param  list<int>  $trooperIds  the troopers placed in the section now
     * @return list<array{date: string, unmarked: int, posted: bool}>
     */
    private function weekStatus(Unit $section, CarbonImmutable $currentWeek, array $trooperIds): array
    {
        if ($trooperIds === []) {
            return [];
        }

        $firstWeek = $currentWeek->subWeeks(self::STATUS_WEEKS);
        $covered = [];
        foreach ($this->loaCoverage->forTroopersOverWeeks($trooperIds, $firstWeek, self::STATUS_WEEKS) as $trooperId => $weeks) {
            foreach ($weeks as $date => $cover) {
                if ($cover->isCovered()) {
                    $covered[$date][] = $trooperId;
                }
            }
        }

        $practices = $this->practices->weekStatuses($section->id, $firstWeek, $currentWeek->subWeek(), $covered);
        $strength = count($trooperIds);

        return array_map(function (int $back) use ($currentWeek, $practices, $strength, $covered) {
            $date = $currentWeek->subWeeks($back)->toDateString();
            $practice = $practices->get($date);

            return [
                'date' => $date,
                'unmarked' => max(0, $strength - count($covered[$date] ?? []) - ($practice?->marked ?? 0)),
                'posted' => $practice?->posted ?? false,
            ];
        }, range(1, self::STATUS_WEEKS));
    }

    /**
     * Each trooper's Section Practice marks over the {@see HISTORY_WEEKS} completed Cav weeks before `$weekStart`,
     * oldest first, for the card's attendance strip: the same counting marks the roster matrix reads (one query for
     * the section) and LOA from the one coverage definition, as the KPI service reads it (one query). A week with no
     * practice row has a null `status` and is not `held`; `loa` is true when leave covers the week or the week was
     * marked `loa`. A leave that needs review is not shown as LOA, since nothing says the trooper was away (AD-4).
     *
     * @param  Collection<int, int>  $trooperIds
     * @return array<int, list<array{week: string, status: ?string, held: bool, loa: bool}>>
     */
    private function history(Unit $section, Collection $trooperIds, CarbonImmutable $weekStart): array
    {
        $first = $weekStart->subWeeks(self::HISTORY_WEEKS);
        $weeks = array_map(fn (int $offset) => $first->addWeeks($offset)->toDateString(), range(0, self::HISTORY_WEEKS - 1));

        $marks = $this->attendance->countingMarks($section, $first, $weekStart->subWeek())
            ->groupBy(fn (CountingMark $mark) => $mark->trooperId)
            ->map(fn (Collection $marks) => $marks->keyBy(fn (CountingMark $mark) => $mark->weekStart->toDateString()));
        $covers = $this->loaCoverage->forTroopersOverWeeks($trooperIds, $first, self::HISTORY_WEEKS);

        return $trooperIds->mapWithKeys(fn (int $id) => [$id => array_map(function (string $week) use ($id, $marks, $covers) {
            $mark = $marks->get($id)?->get($week);

            return [
                'week' => $week,
                'status' => $mark?->status?->value,
                'held' => $mark?->held ?? false,
                'loa' => $covers[$id][$week]->isCovered() || $mark?->status === AttendanceStatus::Loa,
            ];
        }, $weeks)])->all();
    }

    /**
     * How the section's post shows fireteams, and whether the viewer may change it: a leader who commands the section
     * (its SL or ASL, the command above it, or an Admin) sets its fireteams and display; the company's command sets the
     * company's policy. A staff grant reads the rollcall but changes neither, and a view-as preview changes nothing.
     *
     * @return array<string, mixed>
     */
    private function fireteamProps(User $user, Unit $section): array
    {
        $writable = $this->scope->activeViewAs($user) === null;
        $company = $this->fireteams->companyOf($section);

        return $this->fireteams->options(
            $section,
            canEdit: $writable && $this->scope->commandsUnit($user, $section),
            canEditPolicy: $writable && $company !== null && $this->scope->commandsUnit($user, $company),
        );
    }

    /**
     * What the capture run left for the leader (7.7): the players no trooper is linked to, once the
     * practice has been captured. `capture` drives the capture banner (7.8); the drawer renders only
     * when there are players. A practice with no capture gets `telemetry` instead (7.9). `dismissed`
     * are the players this practice's capture saw that a leader dismissed, for the drawer's
     * "Dismissed" list and its Undo (#176), in the same shape.
     *
     * @return array{capture: ?array{source: string, capturedAt: string}, unmatched: list<array<string, string>>, dismissed: list<array<string, string>>}
     */
    private function captureProps(?SectionPractice $practice): array
    {
        if ($practice?->captured_at === null) {
            return ['capture' => null, 'unmatched' => [], 'dismissed' => []];
        }

        $shape = fn (PracticeCapturePlayer $player) => [
            'platform' => $player->game->slug,
            'platformLabel' => $player->game->name,
            // As the game server sent it, with any hidden character written out (as Settings > Games shows them), so a
            // direction override or zero-width character in a player's name cannot disguise it or reorder the text
            // around it. The ID is sent as reported: the drawer posts it back to link or dismiss that player.
            'playerName' => GameIdentityText::reveal($player->player_name),
            'platformId' => $player->platform_id,
        ];

        return [
            'capture' => ['source' => $practice->capture_source, 'capturedAt' => $practice->captured_at->toIso8601String()],
            'unmatched' => $this->capturePlayers->unmatchedFor($practice->id)->map($shape)->values()->all(),
            'dismissed' => $this->capturePlayers->dismissedFor($practice->id)->map($shape)->values()->all(),
        ];
    }

    /**
     * Why the practice has no server data, when it has none (7.9, AD-20): `state` is `not_configured`
     * (the game's env keys are unset, nothing was attempted) or `failed` (the last capture found no
     * source that could answer, at `lastAttemptAt`). Null when the practice was captured or never
     * attempted. The failure is never read as an empty practice: the marks stay the manual ones.
     * `feeds` names the sources that were tried, so the banner says which feed is down (#176).
     *
     * @return array{state: string, platformLabel: ?string, feeds: list<string>, lastAttemptAt: ?string, canRetry: bool}|null
     */
    private function telemetryProps(int $sectionId, ?SectionPractice $practice): ?array
    {
        $state = $this->telemetry->stateFor($sectionId, $practice);

        return $state instanceof CaptureTelemetry ? [
            'state' => $state->state->value,
            'platformLabel' => $state->game?->name,
            'feeds' => $state->feeds,
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
