<?php

namespace App\Tutorial;

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TutorialPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The guided steps of a tutorial ({@see TutorialTrack}), picked by who the person plays ({@see TutorialPersona}), and
 * how far they got in each, read from their own practice copy. Each step names the getting started guide step it ticks
 * when done. A checklist item can also be ticked in the browser by a click (`click`, a selector), for an action that
 * leaves nothing on the server, such as copying a reminder.
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
    public static function idsFor(TutorialPersona $persona, TutorialTrack $track = TutorialTrack::Basics): array
    {
        if ($track === TutorialTrack::FixProblems) {
            // A missing report is chased from above the sections.
            $above = in_array($persona->leads, [UnitType::Platoon, UnitType::Company, UnitType::Battalion], true);

            return array_values(array_filter(['makeup', 'guest', 'blocked-report', $above ? 'missing-report' : null]));
        }

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
    public function describe(int $userId, TutorialPersona $persona, ?CarbonImmutable $now = null, TutorialTrack $track = TutorialTrack::Basics): array
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
                'body' => 'The practice game server already marked who played. SPC Demo.K has not signed and was not seen. Set their signature and their mark, then confirm the practice and mark the roll call posted.',
                'href' => $rollcall,
                'note' => 'In your real section, a mark you set by hand always wins over the game server.',
                'guide' => 'run-roll-call',
                'nav' => self::nav('Roll call'),
                // The next thing to do is shown in the tutorial blue, each on its own roll call step: Demo.K's card on
                // Signatures, then the Section practice step and Demo.K's card there, then Confirm practice took place,
                // then the Review and post step and Mark posted. A step's tab is blue only while another step is open.
                'highlight' => match (true) {
                    $demo !== null && ! $facts['demoSigned'] => self::onRollcallStep(1, '[data-trooper="'.$demo->id.'"]'),
                    $demo !== null && ! $facts['demoMarked'] => self::onRollcallStep(2, '[data-trooper="'.$demo->id.'"]'),
                    ! $facts['confirmed'] => '[data-testid="confirm-practice"]',
                    ! $facts['posted'] => self::onRollcallStep(3, '[data-testid="mark-posted"]'),
                    default => null,
                },
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
                    $reportUnit !== null && ! $opened($report) => '[data-row-id="'.$reportUnit->id.'"]:not([aria-selected="true"]), [data-report-unit="'.$reportUnit->id.'"] [data-testid="open-report"], '.$this->expandTo($reportUnit),
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

        if ($track === TutorialTrack::FixProblems) {
            $steps = $this->fixSteps($week, $section, $opened, $rollcall, $facts['confirmed']);
        }

        return array_map(function (string $id) use ($steps) {
            $step = $steps[$id];

            return ['id' => $id, 'highlight' => null, 'nav' => null, 'area' => null, ...$step, 'done' => collect($step['checklist'])->every(fn (array $item) => $item['done'])];
        }, self::idsFor($persona, $track));
    }

    /**
     * The fixing problems tutorial's steps: a make-up, a guest, the report they block, and a section's report still out.
     *
     * @param  callable(string): bool  $opened
     * @return array<string, array<string, mixed>>
     */
    /**
     * The roll-up opens two levels deep, so for a battalion leader or an Admin the row to click sits inside a collapsed
     * one: the caret of each collapsed practice unit above `$unit` is pointed at until the row shows.
     */
    private function expandTo(?Unit $unit): string
    {
        $above = match ($unit?->type) {
            UnitType::Section => [PracticeWorld::REGIMENT, PracticeWorld::BATTALION, PracticeWorld::COMPANY, PracticeWorld::PLATOON],
            UnitType::Platoon => [PracticeWorld::REGIMENT, PracticeWorld::BATTALION, PracticeWorld::COMPANY],
            UnitType::Company => [PracticeWorld::REGIMENT, PracticeWorld::BATTALION],
            UnitType::Battalion => [PracticeWorld::REGIMENT],
            default => [],
        };

        return implode(', ', array_map(
            fn (string $callsign) => '[data-row-id="'.($this->practice->unitByCallsign($callsign)?->id ?? 0).'"][aria-expanded="false"] .rt-caret',
            $above,
        )) ?: '[data-row-id="0"]';
    }

    private function fixSteps(CarbonImmutable $week, ?Unit $section, callable $opened, string $rollcall, bool $confirmed): array
    {
        $other = $this->practice->unitByCallsign(PracticeWorld::OTHER_SECTION);
        $madeUp = $this->practice->trooperByUsername(PracticeWorld::MADE_UP);
        $guest = $this->practice->trooperByUsername(PracticeWorld::GUEST);
        $facts = $this->practice->fixProgress($section?->id, $madeUp?->id, $guest?->id, $week);
        $report = '/reports/'.($section?->id ?? '').'/'.$week->toDateString();
        $otherRollcall = '/rollcall/'.($other?->id ?? '');

        return [
            'makeup' => [
                'title' => 'Add a make-up',
                'body' => 'CPL Sample.R missed this week\'s practice and made it up at another unit\'s practice. On the Section practice step, mark them Make-up complete.',
                'href' => $rollcall,
                'note' => 'A mark you set by hand always wins over the game server.',
                'guide' => null,
                'nav' => self::nav('Roll call'),
                'highlight' => $facts['madeUp'] || $madeUp === null ? null : self::onRollcallStep(2, '[data-trooper="'.$madeUp->id.'"]'),
                'checklist' => [
                    ['label' => 'Open the roll call of 1st Section', 'done' => $opened($rollcall)],
                    ['label' => 'Mark CPL Sample.R Make-up complete', 'done' => $facts['madeUp']],
                ],
            ],
            'guest' => [
                'title' => 'Add a guest',
                'body' => 'PFC Model.G of 2nd Section played at your practice on a console, so the game server did not see them. Add them under Guests on the Section practice step.',
                'href' => $rollcall,
                'note' => 'A guest gets a make-up on their own section\'s roll call, which their leader can change.',
                'guide' => null,
                'nav' => self::nav('Roll call'),
                'highlight' => $facts['guestAdded'] ? null : self::onRollcallStep(2, implode(', ', [
                    '[data-testid="guest-add"]',
                    '[data-testid="guest-search"]',
                    '[data-testid="guest-result-'.($guest?->id ?? 0).'"]',
                ])),
                'checklist' => [
                    ['label' => 'Add PFC Model.G as a guest', 'done' => $facts['guestAdded']],
                ],
            ],
            'blocked-report' => [
                'title' => 'Fix what blocks the report',
                'body' => '1st Section\'s report cannot be sent yet. Follow its checks: confirm the practice on the roll call, give PVT Trial.B\'s miss a reason, then send it and say whether Trial.B made up the practice.',
                'href' => $report,
                // The practice is confirmed on the roll call, so the step's blue follows the reader there.
                'area' => ['/reports', '/rollcall'],
                'note' => 'Send anyway skips a check, but never an unconfirmed practice.',
                'guide' => null,
                'nav' => self::nav('Reports'),
                'highlight' => match (true) {
                    ! $confirmed => '[data-check="confirmed"] a, [data-testid="confirm-practice"]',
                    // Confirmed: from the roll call to Reports, on the roll-up to the section's row and Open report,
                    // on the report to Trial.B's reason first and then the send.
                    ! $facts['sectionSent'] => implode(', ', [
                        'body:has([data-step]) [data-testid="nav-Reports"]',
                        '[data-row-id="'.($section?->id ?? 0).'"]:not([aria-selected="true"])',
                        $this->expandTo($section),
                        '[data-report-unit="'.($section?->id ?? 0).'"] [data-testid="open-report"]',
                        '[data-check="unaccounted"]:not([data-done])',
                        'body:has([data-check="unaccounted"][data-done]) :is([data-testid="send-button"], [data-testid="send-anyway"])',
                        '[data-testid="makeup-checks"]',
                        '[data-testid="send-confirm"]',
                    ]),
                    default => null,
                },
                'checklist' => [
                    ['label' => 'Confirm the practice', 'done' => $confirmed],
                    ['label' => 'Open 1st Section\'s report', 'done' => $opened($report)],
                    ['label' => 'Send the report, with PVT Trial.B\'s make-up answered', 'done' => $facts['sectionSent']],
                ],
            ],
            'missing-report' => [
                'title' => 'Chase a missing report',
                'body' => '2nd Section has not sent its report. Find it on the roll-up, copy the reminder for its leader, then open its roll call to see what is left.',
                'href' => '/reports',
                'area' => ['/reports', '/rollcall'],
                'note' => 'Here the reminder goes nowhere. In the portal, paste it to the section leader.',
                'guide' => null,
                'nav' => self::nav('Reports'),
                // Until both items are done: the copy is ticked in the browser, so the coach drops the blue once it is.
                'highlight' => $other === null ? null : implode(', ', [
                    // On a sent report (1st Section's, where the step before ends): back to the roll-up.
                    'body:not(:has([data-check])) nav[aria-label="Report mode"] a[href="/reports"]',
                    '[data-row-id="'.$other->id.'"]:not([aria-selected="true"])',
                    $this->expandTo($other),
                    '[data-report-unit="'.$other->id.'"] :is([data-testid="copy-reminder"], [data-testid="open-report"])',
                    '[data-reminder-unit="'.$other->id.'"]',
                    '[data-check="confirmed"] a',
                ]),
                'checklist' => [
                    // Its own panel's Copy reminder, or the one beside it on its platoon's panel.
                    ['label' => 'Copy the reminder for 2nd Section', 'done' => false, 'click' => '[data-report-unit="'.($other?->id ?? 0).'"] [data-testid="copy-reminder"], [data-reminder-unit="'.($other?->id ?? 0).'"]'],
                    ['label' => 'Open 2nd Section\'s roll call', 'done' => $opened($otherRollcall)],
                ],
            ],
        ];
    }
}
