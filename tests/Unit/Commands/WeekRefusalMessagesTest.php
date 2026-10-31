<?php

use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\GameIdentities\UnmatchedPlayerResolutionException;
use App\Commands\Guests\GuestAttendanceRefusedException;
use App\Commands\StaffPractices\StaffPracticeRefusedException;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Commands\TrainingPlans\InvalidTrainingPlanException;
use App\Commands\WeeklyReports\InvalidPlatoonTrainingTopicException;
use App\Commands\WeeklyReports\InvalidReportContentException;
use Carbon\CarbonImmutable;

// Every refusal a user reads names its week by the end date, as the UI does (#568). 2026-10-24 is a Saturday, so the
// week is the one ending Friday 30Oct26.

it('names the week by its end date', function (Closure $refusal) {
    $message = $refusal(CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC'))->getMessage();

    expect($message)->toContain('week ending 30Oct26')
        ->and($message)->not->toContain('24Oct26')
        ->and(strtolower($message))->not->toContain('week of');
})->with([
    'attendance before the floor' => [fn ($week) => InvalidAttendanceMarkException::weekBeforeFloor($week)],
    'unmatched player before the floor' => [fn ($week) => UnmatchedPlayerResolutionException::weekBeforeFloor($week)],
    'guest before the floor' => [fn ($week) => GuestAttendanceRefusedException::weekBeforeFloor($week)],
    'staff practice before the floor' => [fn ($week) => StaffPracticeRefusedException::weekBeforeFloor($week)],
    'staff practice outside the window' => [fn ($week) => StaffPracticeRefusedException::outsideWindow($week, 4)],
    'staff practice locked' => [fn ($week) => StaffPracticeRefusedException::locked($week)],
    'staff practice after the staff report' => [fn ($week) => StaffPracticeRefusedException::staffReportSent('SGT Brandt.L', $week)],
    'staff report not owed' => [fn ($week) => StaffReportRefusedException::notOwed('SGT Brandt.L', $week)],
    'staff report before the floor' => [fn ($week) => StaffReportRefusedException::weekBeforeFloor($week)],
    'staff report already sent' => [fn ($week) => StaffReportRefusedException::alreadySent($week)],
    'training plan week sent' => [fn ($week) => InvalidTrainingPlanException::weekSent($week)],
    'training plan before the floor' => [fn ($week) => InvalidTrainingPlanException::weekBeforeFloor($week)],
    'report before the floor' => [fn ($week) => InvalidReportContentException::weekBeforeFloor($week)],
    'training topic before the floor' => [fn ($week) => InvalidPlatoonTrainingTopicException::weekBeforeFloor($week)],
]);

it('says a running week ends on its Friday and opens for its staff report the next day', function () {
    expect(StaffReportRefusedException::weekNotEnded(CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC'))->getMessage())
        ->toBe('The week ending 30Oct26 has not ended yet. You can send its staff report from 31Oct26.');
});
