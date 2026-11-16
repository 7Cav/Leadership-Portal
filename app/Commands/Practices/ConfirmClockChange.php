<?php

namespace App\Commands\Practices;

use App\Authorization\ScopeResolver;
use App\Models\PracticeSlotConfirmation;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PracticeSlotConfirmationRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\ClockChange;
use App\Support\Time\PracticeWindow;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * Clock changes and practice slots (16NOV26). A slot is kept in Zulu, so when clocks change where a platoon leads from,
 * the practice moves an hour on the leaders' own clocks. In the week before a change and the week of it, the platoon
 * leader confirms each section's slot: keep it in Zulu, or move it so the practice stays at the same local time. The
 * section's weekly report waits for the answer in those weeks.
 *
 * Whose clocks: the time zone the platoon leader's browser last reported. A platoon with no leader, or one whose time
 * zone the portal has not seen yet, is asked nothing. Anyone who may change the platoon (its leader, the company,
 * battalion and regiment above, an Admin) may answer, audited as `practice_slot.clock_change_confirmed`.
 */
class ConfirmClockChange
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly UserRepository $users,
        private readonly SectionPracticeSlotRepository $slots,
        private readonly PracticeSlotConfirmationRepository $confirmations,
        private readonly SetPracticeSlot $setSlot,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * What `$section` owes for a clock change in the week opening `$weekStart`, or null when nothing: `at` the moment
     * of the change (ISO, UTC), `phase` `before` or `of`, `timezone` the platoon leader's, `slot` the slot in effect
     * that week and `keepLocal` the one that keeps it at the same local time, each `[weekday, startMinute]`, and
     * `confirmed` the answer once given.
     *
     * @return array{at: string, phase: string, timezone: string, slot: array{0: int, 1: int}, keepLocal: array{0: int, 1: int}, platoonId: int, confirmed: array{moved: bool, by: ?string, at: ?string, slot: array{0: int, 1: int}}|null}|null
     */
    public function notice(Unit $section, CarbonImmutable $weekStart): ?array
    {
        if ($section->type !== UnitType::Section || $section->parent_id === null || ! $this->changeNear($weekStart)) {
            return null;
        }

        $placing = $this->slots->forSection($section->id)?->placingFor($weekStart);
        $timezone = $this->leaderTimezone((int) $section->parent_id);

        if ($placing === null || $timezone === null) {
            return null;
        }

        [$weekday, $startMinute] = $placing;
        $change = ClockChange::around($timezone, PracticeWindow::inWeek(WeekOf::start($weekStart), $weekday, $startMinute)->start);

        if ($change === null) {
            return null;
        }

        $answer = $this->confirmations->find($section->id, $change['at']);

        return [
            'at' => $change['at']->toIso8601ZuluString(),
            'phase' => $change['phase'],
            'timezone' => $timezone,
            'slot' => [$weekday, $startMinute],
            'keepLocal' => ClockChange::keepLocal($weekday, $startMinute, $change['shift']),
            'platoonId' => (int) $section->parent_id,
            'confirmed' => $answer === null ? null : self::answer($answer),
        ];
    }

    /** Whether `$actor` may answer for `$section`: they may change its platoon. */
    public function mayConfirm(User $actor, Unit $section): bool
    {
        return $section->parent_id !== null && $this->scope->writeScope($actor)->contains((int) $section->parent_id);
    }

    /**
     * Records the answer for the section's clock change in the week opening `$weekStart`. With `$move`, the slot moves to
     * the one that keeps the practice at the same local time, from the first practice after the change: next week when
     * the change is still to come, else this week (or the next, when this one is already held).
     *
     * @throws PracticeSlotForbiddenException when `$actor` may not change the section's platoon.
     * @throws InvalidPracticeSlotException when there is no clock change to answer that week.
     */
    public function handle(User $actor, int $sectionId, CarbonImmutable $weekStart, bool $move): array
    {
        $section = $this->units->find($sectionId);

        if ($section === null || ! $this->mayConfirm($actor, $section)) {
            throw new PracticeSlotForbiddenException;
        }

        $weekStart = WeekOf::start($weekStart);
        $notice = $this->notice($section, $weekStart);

        if ($notice === null) {
            throw InvalidPracticeSlotException::noClockChange();
        }

        if ($notice['confirmed'] !== null) {
            return $notice['confirmed'];
        }

        [$weekday, $startMinute] = $move ? $notice['keepLocal'] : $notice['slot'];
        $changeAt = CarbonImmutable::parse($notice['at']);

        $answer = $this->audit->record($actor->id, 'practice_slot.clock_change_confirmed', null, false, function () use ($section, $notice, $weekStart, $move, $weekday, $startMinute, $changeAt, $actor) {
            if ($move) {
                $from = $notice['phase'] === 'before' ? WeekOf::next($weekStart) : $this->setSlot->changesFrom($section->id);
                $this->slots->set($section->id, $weekday, $startMinute, $from, $this->slots->placingFor($section->id, WeekOf::previous($from)));
            }

            return $this->confirmations->record($section->id, $changeAt, $weekday, $startMinute, $move, $actor->id);
        });

        return self::answer($answer);
    }

    /**
     * The sections among `$sections` with a clock change to answer in the week opening `$weekStart` and no answer yet,
     * each with its notice, for Home. A quick look at every time zone kept first, so a week with no change near it asks
     * nothing per section.
     *
     * @param  iterable<Unit>  $sections
     * @return list<array{section: Unit, notice: array<string, mixed>}>
     */
    public function pendingAmong(iterable $sections, CarbonImmutable $weekStart): array
    {
        $weekStart = WeekOf::start($weekStart);

        if (! $this->changeNear($weekStart)) {
            return [];
        }

        $pending = [];

        foreach ($sections as $section) {
            $notice = $this->notice($section, $weekStart);

            if ($notice !== null && $notice['confirmed'] === null) {
                $pending[] = ['section' => $section, 'notice' => $notice];
            }
        }

        return $pending;
    }

    /** @var list<string>|null every time zone kept, read once on this request */
    private ?array $zones = null;

    /**
     * Whether any time zone kept has a clock change within a week of the week opening `$weekStart`: a practice anywhere
     * in the week is within a week of a change from the week before to the week after, which the two probes cover (a
     * week either side of the week's start and of its end). Most weeks this answers no from one query.
     */
    private function changeNear(CarbonImmutable $weekStart): bool
    {
        $weekStart = WeekOf::start($weekStart);
        $this->forThisRequest();
        $this->zones ??= $this->confirmations->knownTimezones();

        foreach ($this->zones as $zone) {
            if (ClockChange::around($zone, $weekStart) !== null || ClockChange::around($zone, WeekOf::next($weekStart)) !== null) {
                return true;
            }
        }

        return false;
    }

    /** The request the memos above and below were read on: the router keeps a controller, and so this, between requests. */
    private ?int $request = null;

    private function forThisRequest(): void
    {
        $current = app()->bound('request') ? spl_object_id(app('request')) : null;

        if ($current !== $this->request) {
            $this->request = $current;
            $this->zones = null;
            $this->leaderZones = [];
        }
    }

    /** @var array<int, ?string> the platoon leaders' time zones read on this request, by platoon id */
    private array $leaderZones = [];

    /** The time zone the browser of the platoon's leader last reported, or null with no leader or none seen. */
    private function leaderTimezone(int $platoonId): ?string
    {
        $this->forThisRequest();

        if (array_key_exists($platoonId, $this->leaderZones)) {
            return $this->leaderZones[$platoonId];
        }

        $leader = $this->commands->commandersOf([$platoonId])->get($platoonId);
        $user = $leader?->cav_id === null ? null : $this->users->withCavId((int) $leader->cav_id);

        return $this->leaderZones[$platoonId] = $user === null ? null : $this->confirmations->timezoneOf($user->id);
    }

    /** @return array{moved: bool, by: ?string, at: ?string, slot: array{0: int, 1: int}} */
    private static function answer(PracticeSlotConfirmation $answer): array
    {
        return [
            'moved' => $answer->moved,
            'by' => $answer->confirmedBy?->username,
            'at' => $answer->created_at?->toIso8601ZuluString(),
            'slot' => [$answer->weekday, $answer->start_minute],
        ];
    }
}
