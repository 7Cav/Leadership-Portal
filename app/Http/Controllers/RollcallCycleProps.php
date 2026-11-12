<?php

namespace App\Http\Controllers;

use App\Commands\WeeklyReports\SectionTrainingSubject;
use App\Models\Unit;
use App\Models\WeeklyReportStatus;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\RollcallFlow;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The roll call's weekly cycle (#703): the `flow` prop, which flow the week follows and whether its roll call was
 * created, and the `training` prop, the week's training topic as the weekly report holds it. The roll call page reads
 * both on load and its cycle writes answer with them, so the page and the server show the same state.
 */
final class RollcallCycleProps
{
    public function __construct(
        private readonly WeeklyReportRepository $reports,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
    ) {}

    /**
     * `version` is {@see RollcallFlow::V1} or {@see RollcallFlow::V2}; `created` says when and by whom a flow 2 roll call
     * was created, null until it is (and always for flow 1, which has no create step).
     *
     * @return array{version: int, created: array{at: string, by: ?string}|null}
     */
    public function flow(Unit $section, CarbonImmutable $weekStart): array
    {
        $practice = $this->practices->find($section->id, $weekStart);

        return [
            'version' => RollcallFlow::of($practice, $weekStart),
            'created' => $practice?->rollcall_created_at === null ? null : [
                'at' => $practice->rollcall_created_at->toIso8601String(),
                'by' => $practice->rollcallCreatedBy?->username,
            ],
        ];
    }

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
