<?php

use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkRollcallPosted;
use App\Commands\Attendance\MarkRollcallPostedForbiddenException;
use App\Commands\Attendance\MarkRollcallPostedHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Models\AuditLogEntry;
use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

// 3.10 / AD-16, AD-8, AD-20: marking the week's rollcall posted is an audited stamp on the practice.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
});

function markPosted(User $actor, int $sectionId, DateTimeImmutable $moment): SectionPractice
{
    return app(MarkRollcallPostedHandler::class)->handle($actor, new MarkRollcallPosted($sectionId, $moment));
}

it('stamps the week\'s practice with now and the actor, creating the practice if needed', function () {
    $practice = markPosted($this->leader, $this->section->id, $this->moment);

    expect($practice->week_start->toDateString())->toBe('2026-10-03')
        ->and($practice->rollcall_posted_at->toIso8601String())->toBe('2026-10-08T16:20:00+00:00')
        ->and($practice->rollcall_posted_by)->toBe($this->leader->id)
        ->and($practice->rollcallPostedBy->is($this->leader))->toBeTrue()
        ->and(SectionPractice::query()->count())->toBe(1);
});

it('records one audit entry targeting the practice', function () {
    $practice = markPosted($this->leader, $this->section->id, $this->moment);

    $entry = AuditLogEntry::query()->sole();
    expect($entry->action)->toBe('rollcall.posted')
        ->and($entry->actor_id)->toBe($this->leader->id)
        ->and((int) $entry->target_id)->toBe($practice->id);
});

it('lets a later mark overwrite the earlier one — the latest post wins', function () {
    markPosted($this->leader, $this->section->id, $this->moment);
    $other = User::factory()->admin()->create();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-09 08:00:00', 'UTC'));

    $practice = markPosted($other, $this->section->id, $this->moment);

    expect($practice->rollcall_posted_at->toIso8601String())->toBe('2026-10-09T08:00:00+00:00')
        ->and($practice->rollcall_posted_by)->toBe($other->id)
        ->and(SectionPractice::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->count())->toBe(2);
});

it('refuses a section outside the actor\'s scope, writing nothing (AD-8)', function () {
    expect(fn () => markPosted(User::factory()->serving()->create(), $this->section->id, $this->moment))
        ->toThrow(MarkRollcallPostedForbiddenException::class);

    expect(SectionPractice::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
    expect(new MarkRollcallPostedForbiddenException(1))->toBeInstanceOf(AuthorizationException::class);
});

it('refuses a unit that is not a section', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);

    expect(fn () => markPosted(User::factory()->admin()->create(), $platoon->id, $this->moment))
        ->toThrow(InvalidAttendanceMarkException::class);
});

// AD-12: a sent week is locked for marking posted, as for every other rollcall write.

it('refuses a week whose section report has been sent, with no stamp and no audit entry', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => '2026-10-03']);

    expect(fn () => markPosted($this->leader, $this->section->id, $this->moment))
        ->toThrow(RollcallWeekLockedException::class);

    expect(SectionPractice::query()->whereNotNull('rollcall_posted_at')->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('still marks a week posted whose report is a draft, a demo report, or another section\'s', function () {
    WeeklyReport::factory()->create(['unit_id' => $this->section->id, 'week_start' => '2026-10-03']);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => '2026-09-26', 'is_demo' => true]);
    WeeklyReport::factory()->sent()->create(['unit_id' => Unit::factory()->create(['type' => UnitType::Section])->id, 'week_start' => '2026-10-03']);

    expect(markPosted($this->leader, $this->section->id, $this->moment)->rollcall_posted_at)->not->toBeNull()
        ->and(markPosted($this->leader, $this->section->id, CarbonImmutable::parse('2026-09-30 18:00:00', 'UTC'))->rollcall_posted_at)->not->toBeNull();
});
