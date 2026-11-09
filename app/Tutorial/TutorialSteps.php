<?php

namespace App\Tutorial;

use App\Repositories\TutorialPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The guided steps of the tutorial, picked by who the person plays ({@see TutorialPersona}), and how far they got in
 * each, read from their own practice copy. Each step names the getting started guide step it ticks when done.
 */
final class TutorialSteps
{
    public function __construct(private readonly TutorialPracticeRepository $practice) {}

    /**
     * The step ids for a persona, in order: roll call and the weekly report for anyone who leads a unit (and Admins),
     * the staff report for HQ staff, the roster for everyone, the Admin pages for Admins.
     *
     * @return list<string>
     */
    public static function idsFor(TutorialPersona $persona): array
    {
        return array_values(array_filter([
            $persona->leadsUnit() ? 'rollcall' : null,
            $persona->leadsUnit() ? 'weekly-report' : null,
            $persona->hqStaff !== null ? 'staff-report' : null,
            'roster',
            $persona->admin ? 'admin' : null,
        ]));
    }

    /**
     * Every step with its checklist ticked from the practice copy. Runs inside the sandbox.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(int $userId, TutorialPersona $persona, ?CarbonImmutable $now = null): array
    {
        $week = WeekOf::reporting($now ?? CarbonImmutable::now('UTC'));
        $section = $this->practice->unitByCallsign(PracticeWorld::SECTION);
        $reportUnit = match ($persona->leads?->value) {
            'battalion' => $this->practice->unitByCallsign(PracticeWorld::BATTALION),
            'company' => $this->practice->unitByCallsign(PracticeWorld::COMPANY),
            'platoon' => $this->practice->unitByCallsign(PracticeWorld::PLATOON),
            default => $section,
        };
        $demo = $this->practice->trooperByUsername(PracticeWorld::DEMO);
        $self = $this->practice->trooperByUsername($persona->username);
        $facts = $this->practice->progress($userId, $section?->id, $reportUnit?->id, $demo?->id, $self?->id, $week);

        $visits = collect($facts['visits']);
        $visited = fn (string $prefix) => $visits->contains(fn (string $url) => str_starts_with($url, $prefix));
        // One page exactly, with or without a query: `/rollcall/5` is not `/rollcall/50`.
        $opened = fn (string $page) => $visits->contains(fn (string $url) => $url === $page || str_starts_with($url, $page.'?'));
        $rollcall = '/rollcall/'.($section?->id ?? '');
        $report = '/reports/'.($reportUnit?->id ?? '').'/'.$week->toDateString();
        $roster = '/roster';

        $steps = [
            'rollcall' => [
                'title' => 'Run roll call',
                'body' => 'The practice server already marked who played. SPC Demo.K has not signed and was not seen. Set their signature and their mark, then confirm the practice and mark the roll call posted.',
                'href' => $rollcall,
                'note' => 'In your real section, a mark you set by hand always wins over the game server.',
                'guide' => 'run-roll-call',
                'checklist' => [
                    ['label' => 'Open the roll call of 1st Section', 'done' => $opened($rollcall)],
                    ['label' => 'Set the signature of SPC Demo.K', 'done' => $facts['demoSigned']],
                    ['label' => 'Set the practice mark of SPC Demo.K', 'done' => $facts['demoMarked']],
                    ['label' => 'Confirm the practice', 'done' => $facts['confirmed']],
                    ['label' => 'Mark the roll call posted', 'done' => $facts['posted']],
                ],
            ],
            'weekly-report' => [
                'title' => 'Send the weekly report',
                'body' => 'Write the training subject and a short note, check the numbers, then send the report for '.($reportUnit?->callsign ?? 'your unit').'.',
                'href' => $report,
                'note' => 'A sent report is final for the week. Here it goes nowhere.',
                'guide' => 'send-the-weekly-report',
                'checklist' => [
                    ['label' => 'Open the report for this week', 'done' => $opened($report)],
                    ['label' => 'Write the subject or a note', 'done' => $facts['draft']],
                    ['label' => 'Send the report', 'done' => $facts['sent']],
                ],
            ],
            'staff-report' => [
                'title' => 'Send your staff report',
                'body' => 'Say which section practices you attended this week, add anything else you did, then send it.',
                'href' => '/staff-report',
                'note' => 'In the portal it also goes to the staff Sheet. Here it does not.',
                'guide' => 'send-a-staff-report',
                'checklist' => [
                    ['label' => 'Open your staff report', 'done' => $visited('/staff-report')],
                    ['label' => 'Send it', 'done' => $facts['staffSent']],
                ],
            ],
            'roster' => [
                'title' => 'Read the roster',
                'body' => 'Look at your practice unit: who is on leave, who missed practice, and how strength moved over the last weeks.',
                'href' => $roster,
                'note' => null,
                'guide' => 'read-your-roster',
                'checklist' => [
                    ['label' => 'Open the roster', 'done' => $visited('/roster')],
                    ['label' => 'Open the Strength tab', 'done' => $visits->contains(fn (string $url) => str_starts_with($url, '/roster') && str_contains($url, 'view=strength'))],
                    ['label' => 'Open the milpac of a trooper', 'done' => $visited('/troopers/')],
                ],
            ],
            'admin' => [
                'title' => 'Look at the Admin pages',
                'body' => 'Open Org structure to see the practice unit, then the Audit log. Everything you did in the tutorial is there, and only there.',
                'href' => '/settings/org-structure',
                'note' => 'The real audit log has none of it.',
                'guide' => null,
                'checklist' => [
                    ['label' => 'Open Org structure', 'done' => $visited('/settings/org-structure')],
                    ['label' => 'Open the Audit log', 'done' => $visited('/settings/audit-log')],
                ],
            ],
        ];

        return array_map(function (string $id) use ($steps) {
            $step = $steps[$id];

            return ['id' => $id, ...$step, 'done' => collect($step['checklist'])->every(fn (array $item) => $item['done'])];
        }, self::idsFor($persona));
    }
}
