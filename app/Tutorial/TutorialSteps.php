<?php

namespace App\Tutorial;

use App\Models\UnitType;
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
     * The sidebar link to `$labels` (`nav-{label}` in AppSidebar.vue), shown in the tutorial blue while the step's page
     * is not open.
     */
    private static function nav(string ...$labels): string
    {
        return implode(', ', array_map(fn (string $label) => '[data-testid="nav-'.$label.'"]', $labels));
    }

    /**
     * `$selector` while roll call step `$n` is open, and that step's tab while another one is: the page shows one step
     * at a time, in a section labelled by the step's heading (`step-{n}-title`).
     */
    private static function onRollcallStep(int $n, string $selector): string
    {
        return '[data-step="'.$n.'"]:not([aria-current="step"]), section[aria-labelledby="step-'.$n.'-title"] '.$selector;
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
        // A section writes its training subject and a platoon its topic for the sections; a company's or battalion's
        // report has neither, only notes.
        $hasSubject = $reportUnit === null || in_array($reportUnit->type, [UnitType::Section, UnitType::Platoon], true);

        $visits = collect($facts['visits']);
        $visited = fn (string $prefix) => $visits->contains(fn (string $url) => str_starts_with($url, $prefix));
        // One page exactly, with or without a query: `/rollcall/5` is not `/rollcall/50`.
        $opened = fn (string $page) => $visits->contains(fn (string $url) => $url === $page || str_starts_with($url, $page.'?'));
        $rollcall = '/rollcall/'.($section?->id ?? '');
        $report = '/reports/'.($reportUnit?->id ?? '').'/'.$week->toDateString();
        $roster = '/roster';
        $strength = $visits->contains(fn (string $url) => str_starts_with($url, '/roster') && str_contains($url, 'view=strength'));

        $steps = [
            'rollcall' => [
                'title' => 'Run roll call',
                'body' => 'Create this week\'s roll call, then mark it posted. The practice game server already marked who played; SPC Demo.K has not answered the roll call yet and was not on the server. Pick their answer (for example Will attend) and set their practice mark, then confirm the practice.',
                'href' => $rollcall,
                'note' => 'In your real section, a mark you set by hand always wins over the game server.',
                'guide' => 'run-roll-call',
                'nav' => self::nav('Roll call'),
                // The next thing to do is shown in the tutorial blue, each on its own step of the new roll call (#703):
                // Create roll call, then Mark as posted on Post, then Demo.K's signature and mark on Sign and mark, then
                // Confirm practice took place. Closing it is the weekly report step's. A step's tab is blue only while
                // another step is open.
                'highlight' => match (true) {
                    ! $facts['created'] => self::onRollcallStep(1, '[data-testid="create-rollcall"]'),
                    ! $facts['posted'] => self::onRollcallStep(2, '[data-testid="mark-posted"]'),
                    $demo !== null && ! $facts['demoSigned'] => self::onRollcallStep(3, '[data-kind="signature"] [data-trooper="'.$demo->id.'"]'),
                    $demo !== null && ! $facts['demoMarked'] => self::onRollcallStep(3, '[data-kind="practice"] [data-trooper="'.$demo->id.'"]'),
                    ! $facts['confirmed'] => '[data-testid="confirm-practice"]',
                    default => null,
                },
                'checklist' => [
                    ['label' => 'Open the roll call of 1st Section', 'done' => $opened($rollcall)],
                    ['label' => 'Create the roll call', 'done' => $facts['created']],
                    ['label' => 'Mark the roll call posted', 'done' => $facts['posted']],
                    ['label' => 'Pick an answer for SPC Demo.K', 'done' => $facts['demoSigned']],
                    ['label' => 'Set the practice mark of SPC Demo.K', 'done' => $facts['demoMarked']],
                    ['label' => 'Confirm the practice', 'done' => $facts['confirmed']],
                ],
            ],
            'weekly-report' => [
                'title' => 'Send the weekly report',
                'body' => ($hasSubject ? 'Write the training subject and a short note' : 'Write a short note').', check the numbers, then send the report for '.($reportUnit?->callsign ?? 'your unit').'.',
                'href' => $report,
                // Reports opens on the top of the roll-up, so the way to the report is shown there too.
                'area' => '/reports',
                'note' => 'A sent report can still be edited until its deadline. Here it goes nowhere.',
                'guide' => 'send-the-weekly-report',
                'nav' => self::nav('Reports'),
                // On the roll-up, the unit's row, then Open report beside it; on the report, the subject (a section's
                // training subject, a platoon's topic for its sections), then the notes, then Send.
                'highlight' => match (true) {
                    $reportUnit !== null && ! $opened($report) => '[data-row-id="'.$reportUnit->id.'"]:not([aria-selected="true"]), [data-report-unit="'.$reportUnit->id.'"] [data-testid="open-report"]',
                    $hasSubject && ! $facts['subject'] => '#training-subject, #platoon-topic',
                    ! $facts['note'] => '#report-notes',
                    ! $facts['sent'] => '[data-testid="send-button"], [data-testid="send-anyway"]',
                    default => null,
                },
                'checklist' => array_values(array_filter([
                    ['label' => 'Open the report for this week', 'done' => $opened($report)],
                    $hasSubject ? ['label' => 'Write the subject', 'done' => $facts['subject']] : null,
                    ['label' => 'Write a note', 'done' => $facts['note']],
                    ['label' => 'Send the report', 'done' => $facts['sent']],
                ])),
            ],
            'staff-report' => [
                'title' => 'Send your staff report',
                'body' => 'Say which section practices you attended this week, add anything else you did, then send it.',
                'href' => '/staff-report',
                // Staff reports lists the reader's own with a Send your staff report button.
                'area' => ['/staff-report', '/staff-reports'],
                'note' => 'In the portal it also goes to the staff sheet. Here it does not.',
                'guide' => 'send-a-staff-report',
                'nav' => self::nav('Staff report', 'Staff reports'),
                'highlight' => $facts['staffSent'] ? null : '[data-testid="staff-report-send"], [data-testid="own-staff-report-send"]',
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
                'nav' => self::nav('Roster'),
                // The Strength tab, then the way to a trooper's panel: Order of battle while no trooper rows are shown,
                // Demo.K's row while no panel is open, and Open milpac in the panel.
                'highlight' => match (true) {
                    ! $strength => '[data-testid="view-strength"]',
                    ! $visited('/troopers/') => implode(', ', array_filter([
                        'body:not(:has([data-member], .tr-trooper)) [data-testid="view-orbat"]',
                        $demo === null ? null : 'body:not(:has([data-slot="sheet-content"])) :is([data-testid="trooper-'.$demo->id.'"], [data-testid="matrix-trooper-'.$demo->id.'"])',
                        '[data-testid="sheet-open-trooper"]',
                    ])),
                    default => null,
                },
                'checklist' => [
                    ['label' => 'Open the roster', 'done' => $visited('/roster')],
                    ['label' => 'Open the Strength tab', 'done' => $strength],
                    ['label' => 'Open the milpac of a trooper', 'done' => $visited('/troopers/')],
                ],
            ],
            'admin' => [
                'title' => 'Look at the Admin pages',
                'body' => 'Open Org structure to see the practice unit, then the Audit log. Everything you did in the tutorial is there, and only there.',
                'href' => '/settings/org-structure',
                'note' => 'The real audit log has none of it.',
                'guide' => null,
                // Both pages are in the sidebar: the one still to open.
                'nav' => null,
                'highlight' => match (true) {
                    ! $visited('/settings/org-structure') => self::nav('Org structure'),
                    ! $visited('/settings/audit-log') => self::nav('Audit log'),
                    default => null,
                },
                'checklist' => [
                    ['label' => 'Open Org structure', 'done' => $visited('/settings/org-structure')],
                    ['label' => 'Open the Audit log', 'done' => $visited('/settings/audit-log')],
                ],
            ],
        ];

        return array_map(function (string $id) use ($steps) {
            $step = $steps[$id];

            return ['id' => $id, 'highlight' => null, 'nav' => null, 'area' => null, ...$step, 'done' => collect($step['checklist'])->every(fn (array $item) => $item['done'])];
        }, self::idsFor($persona));
    }
}
