<?php

namespace App\Repositories;

use App\Models\PlatoonTrainingTopic;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `platoon_training_topics` (AD-2, AD-22): the week's training topic a platoon
 * leader set for their sections. One row per `(unit_id, week_start)`.
 *
 * Every `$weekStart` must be a Saturday 00:00 UTC (AD-11); anything else throws
 * `InvalidArgumentException`.
 */
interface PlatoonTrainingTopicRepository
{
    /**
     * The platoon's topic for the week, with `setter` loaded, or null when none is set.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function find(int $unitId, CarbonInterface $weekStart): ?PlatoonTrainingTopic;

    /**
     * The topic `$section`'s platoon (its parent) set for the week, with `setter` loaded, or null
     * when there is none or `$section` is not a section.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forSection(Unit $section, CarbonInterface $weekStart): ?PlatoonTrainingTopic;

    /**
     * Set the platoon's topic for the week, replacing any earlier one. `$planId` is the approved training plan that
     * wrote it, or null for a topic set by hand.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function set(int $unitId, CarbonInterface $weekStart, string $subject, bool $enforced, int $setBy, ?int $setByAdmin = null, ?int $setAsUnit = null, ?int $planId = null): PlatoonTrainingTopic;

    /**
     * The topics an approval of `$planId` wrote and that still stand, earliest week first.
     *
     * @return Collection<int, PlatoonTrainingTopic>
     */
    public function fromPlan(int $planId): Collection;

    /**
     * Remove the platoon's topic for the week. Returns the removed row (as it was), or null when
     * there was none.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function clear(int $unitId, CarbonInterface $weekStart): ?PlatoonTrainingTopic;

    /**
     * Whether `$unit` has a training topic for any week. A topic is part of its week's report
     * record, so `DeleteUnit` refuses a unit that has one rather than let
     * `platoon_training_topics.unit_id` cascade it away.
     */
    public function hasTopicsFor(Unit $unit): bool;
}
