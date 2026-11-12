<?php

namespace App\Http\Controllers;

use App\Commands\WeeklyReports\SectionTrainingSubject;
use App\Models\Unit;
use App\Models\WeeklyReportStatus;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The roll call's weekly cycle (#703): the `training` prop, the week's training topic as the weekly report holds it.
 * The roll call page reads it on load and the topic write answers with it, so both show the same value.
 */
final class RollcallCycleProps
{
    public function __construct(
        private readonly WeeklyReportRepository $reports,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly UnitRepository $units,
    ) {}

    /**
     * `subject` is the topic the report shows: the platoon's when it enforces one, else the section's own, else the
     * platoon's (a sent report keeps the subject it froze). `platoon` names the platoon whose topic is set, null with
     * none. `canEdit` is false for a viewer who cannot write the section, a sent week, a week before the floor, or a
     * topic the platoon enforces; a view-as preview is the page's to say.
     *
     * @return array{subject: ?string, enforced: bool, platoon: ?string, canEdit: bool}
     */
    public function training(Unit $section, CarbonImmutable $weekStart, bool $canWrite): array
    {
        $report = $this->reports->find($section->id, $weekStart);
        $sent = $report?->status === WeeklyReportStatus::Sent;
        $topic = $sent ? null : $this->topics->forSection($section, $weekStart);
        $platoon = $topic === null ? null : $this->units->find($topic->unit_id);

        return [
            'subject' => $sent ? $report->training_subject : SectionTrainingSubject::effective($topic, $report?->training_subject),
            'enforced' => (bool) $topic?->enforced,
            'platoon' => $platoon?->name,
            'canEdit' => $canWrite && ! $sent && ! WeekOf::isBeforeFloor($weekStart) && ! $topic?->enforced,
        ];
    }
}
